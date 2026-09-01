#!/usr/bin/env python3
"""Versioned FTPS deploy helper shared by the Teatro Museo applications."""

from __future__ import annotations

import argparse
import json
import os
import ssl
import sys
import time
from datetime import datetime, timezone
from ftplib import FTP, FTP_TLS, error_perm
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


DEPLOY_HELPER_VERSION = "2026-08-19.1"
ROOT_DIR = Path(__file__).resolve().parent.parent
DEPLOY_DIR = ROOT_DIR / ".deploy"
CONFIG_PATH = DEPLOY_DIR / ".env.deploy"
STATE_PATH = DEPLOY_DIR / ".last_ftp_deploy"
ROLLBACK_DIR = DEPLOY_DIR / ".rollback"

BLACKLIST_DIRS = {
    ".git",
    ".github",
    ".claude",
    ".deploy",
    ".idea",
    ".vscode",
    ".phpunit.cache",
    ".performance",
    "docs",
    "scripts",
    "build",
    "builds",
    "coverage",
    "dist",
    "node_modules",
    "results",
    "tests",
    "vendor",
    "writable",
}

BLACKLIST_FILES = {
    ".DS_Store",
    ".env",
    ".env.deploy",
    ".env.deploy.example",
    ".env.docker.example",
    ".last_ftp_deploy",
    "env",
    "composer.json",
    "composer.lock",
    "deploy-ftp.py",
    "package-lock.json",
    "package.json",
    "pnpm-lock.yaml",
    "yarn.lock",
    ".php-cs-fixer.cache",
    ".phpstan.cache",
    ".phpunit.result.cache",
    "AGENTS.md",
    "CLAUDE.md",
    "CONTEXT.md",
    "CONTRIBUTING.md",
    "DEPLOYMENT.md",
    "README.md",
    "RELEASE.md",
    "SECURITY.md",
    "TASKS.md",
    "TASKS_ARCHIVE.md",
}

BOOTSTRAP_FILES = {"composer.json", "composer.lock"}


def load_config() -> dict[str, str]:
    """Load deploy settings from .deploy/.env.deploy."""
    if not CONFIG_PATH.exists():
        print(f"Error: missing config file at {CONFIG_PATH}")
        raise SystemExit(1)

    if os.name != "nt":
        mode = CONFIG_PATH.stat().st_mode & 0o777
        if mode & 0o077:
            print(
                "Error: insecure permissions on "
                f"{CONFIG_PATH} ({oct(mode)}). Run: chmod 600 {CONFIG_PATH}"
            )
            raise SystemExit(1)

    config: dict[str, str] = {}
    with CONFIG_PATH.open("r", encoding="utf-8") as handle:
        for raw_line in handle:
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            config[key.strip()] = value.strip().strip("'").strip('"')

    return config


def get_setting(config: dict[str, str], key: str, default: str = "") -> str:
    """Read a setting from the config file first, then from the environment."""
    value = config.get(key) or os.environ.get(key) or default
    return value.strip() if isinstance(value, str) else str(value)


def parse_bool(value: str, default: bool = False) -> bool:
    normalized = value.strip().lower()
    if not normalized:
        return default
    if normalized in {"1", "true", "yes", "y", "on"}:
        return True
    if normalized in {"0", "false", "no", "n", "off"}:
        return False
    raise ValueError(f"invalid boolean value: {value}")


def get_last_deploy_time() -> float:
    """Return the timestamp of the last successful deploy."""
    if STATE_PATH.exists():
        try:
            return float(STATE_PATH.read_text(encoding="utf-8").strip())
        except (OSError, ValueError):
            pass
    return 0.0


def update_last_deploy_time(timestamp: float) -> None:
    """Persist the timestamp of the current deploy atomically."""
    temporary_path = STATE_PATH.with_name(f"{STATE_PATH.name}.tmp")
    temporary_path.write_text(str(timestamp), encoding="utf-8")
    os.replace(temporary_path, STATE_PATH)


def is_blacklisted_file(filename: str, include_bootstrap_files: bool = False) -> bool:
    if filename in BOOTSTRAP_FILES and include_bootstrap_files:
        return False
    return (
        filename in BLACKLIST_FILES
        or filename.startswith(".env")
        or filename.endswith(".pyc")
    )


def iter_deployable_files(
    include_bootstrap_files: bool = False,
) -> list[tuple[str, Path]]:
    """Return all local files eligible for the runtime deploy."""
    deployable: list[tuple[str, Path]] = []

    for directory, directories, filenames in os.walk(ROOT_DIR):
        directory_path = Path(directory)
        relative_directory = directory_path.relative_to(ROOT_DIR)
        if any(part in BLACKLIST_DIRS for part in relative_directory.parts):
            directories[:] = []
            continue

        directories[:] = [
            child for child in directories if child not in BLACKLIST_DIRS
        ]

        for filename in filenames:
            if is_blacklisted_file(filename, include_bootstrap_files):
                continue
            full_path = directory_path / filename
            relative_path = full_path.relative_to(ROOT_DIR).as_posix()
            deployable.append((relative_path, full_path))

    return deployable


def collect_modified_files(
    last_deploy: float,
    include_bootstrap_files: bool = False,
) -> list[tuple[str, Path]]:
    """List files modified since the last deploy, skipping runtime junk."""
    deployable = iter_deployable_files(include_bootstrap_files)
    modified = [
        (relative_path, full_path)
        for relative_path, full_path in deployable
        if full_path.stat().st_mtime > last_deploy
    ]

    if include_bootstrap_files:
        existing = {relative_path for relative_path, _ in modified}
        for filename in sorted(BOOTSTRAP_FILES):
            full_path = ROOT_DIR / filename
            if full_path.is_file() and filename not in existing:
                modified.append((filename, full_path))

    return modified


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Incremental FTPS deploy helper for this repository."
    )
    parser.add_argument(
        "-y",
        "--yes",
        action="store_true",
        help="Skip confirmation prompts",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Show the files that would be uploaded and exit",
    )
    parser.add_argument(
        "--only",
        action="append",
        metavar="PATH",
        help="Upload only this repository-relative path; may be repeated",
    )
    parser.add_argument(
        "--bootstrap",
        action="store_true",
        help="Include composer.json and composer.lock for runtime provisioning",
    )
    parser.add_argument(
        "--prune",
        action="store_true",
        help="Delete remote non-runtime files absent locally after a successful deploy",
    )
    parser.add_argument(
        "--rollback",
        metavar="RELEASE_ID",
        help="Restore a previously saved release backup",
    )
    parser.add_argument(
        "--version",
        action="version",
        version=DEPLOY_HELPER_VERSION,
    )
    return parser.parse_args()


def ensure_remote_dir(ftp: FTP | FTP_TLS, remote_dir_path: str) -> None:
    """Ensure the remote directory exists and leave FTP cwd on it."""
    normalized = remote_dir_path.replace("\\", "/").strip("/")
    ftp.cwd("/")
    if not normalized:
        return

    current = ""
    for part in [piece for piece in normalized.split("/") if piece]:
        current = f"{current}/{part}" if current else part
        try:
            ftp.cwd("/" + current)
        except error_perm:
            print(f"Creating remote folder: {current}")
            ftp.mkd("/" + current)
            ftp.cwd("/" + current)


def join_remote_path(remote_dir: str, relative_path: str) -> str:
    remote_root = "/" + remote_dir.strip("/") if remote_dir.strip("/") else ""
    return f"{remote_root}/{relative_path}".replace("//", "/")


def connect_ftp(config: dict[str, str]) -> FTP | FTP_TLS:
    """Connect using FTPS by default; plain FTP requires explicit opt-in."""
    protocol = get_setting(config, "FTP_PROTOCOL", "ftps").lower()
    host = get_setting(config, "FTP_HOST", "ftp.teatromuseo.cl")
    port = int(get_setting(config, "FTP_PORT", "21"))
    user = get_setting(config, "FTP_USER")
    password = get_setting(config, "FTP_PASS")
    allow_insecure = parse_bool(get_setting(config, "ALLOW_INSECURE_FTP", "false"))

    if not user or not password:
        raise ValueError("FTP_USER or FTP_PASS is not configured in .deploy/.env.deploy")

    if protocol == "ftp":
        if not allow_insecure:
            raise ValueError(
                "Plain FTP is disabled. Use FTP_PROTOCOL=ftps or explicitly set "
                "ALLOW_INSECURE_FTP=true for a temporary exception."
            )
        ftp: FTP | FTP_TLS = FTP()
    elif protocol in {"ftps", "ftp_tls", "ftp-tls"}:
        verify_tls = parse_bool(get_setting(config, "FTP_TLS_VERIFY", "true"), True)
        if verify_tls:
            context = ssl.create_default_context()
        else:
            if not allow_insecure:
                raise ValueError(
                    "TLS certificate verification is disabled. Set "
                    "ALLOW_INSECURE_FTP=true only for a temporary exception."
                )
            context = ssl._create_unverified_context()
        ftp = FTP_TLS(context=context)
    else:
        raise ValueError(f"unsupported FTP_PROTOCOL: {protocol}")

    print(f"Connecting to {host}:{port} using {protocol.upper()}...")
    ftp.connect(host, port, timeout=15)
    ftp.login(user, password)
    if isinstance(ftp, FTP_TLS):
        ftp.prot_p()
    ftp.encoding = "utf-8"
    print("Login successful.")
    return ftp


def write_manifest(manifest_path: Path, manifest: dict[str, object]) -> None:
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = manifest_path.with_suffix(".tmp")
    temporary_path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    os.replace(temporary_path, manifest_path)


def create_release_backup(
    ftp: FTP | FTP_TLS,
    remote_dir: str,
    modified_files: list[tuple[str, Path]],
) -> tuple[str, dict[str, object]]:
    """Save remote versions before overwriting them."""
    release_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    release_dir = ROLLBACK_DIR / release_id
    files_dir = release_dir / "files"
    manifest: dict[str, object] = {
        "version": 1,
        "helper_version": DEPLOY_HELPER_VERSION,
        "release_id": release_id,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "remote_dir": remote_dir,
        "files": [],
    }

    entries: list[dict[str, object]] = []
    for relative_path, _ in sorted(modified_files):
        backup_path = files_dir / relative_path
        backup_path.parent.mkdir(parents=True, exist_ok=True)
        existed = True
        try:
            with backup_path.open("wb") as handle:
                ftp.retrbinary(
                    f"RETR {join_remote_path(remote_dir, relative_path)}",
                    handle.write,
                )
        except error_perm:
            existed = False
            if backup_path.exists():
                backup_path.unlink()

        entries.append(
            {
                "path": relative_path,
                "existed": existed,
                "backup": (
                    backup_path.relative_to(release_dir).as_posix()
                    if existed
                    else None
                ),
            }
        )
        manifest["files"] = entries
        write_manifest(release_dir / "manifest.json", manifest)

    return release_id, manifest


def restore_release_backup(
    ftp: FTP | FTP_TLS,
    manifest: dict[str, object],
) -> list[str]:
    """Restore the remote files captured in a release manifest."""
    release_id = str(manifest.get("release_id", ""))
    release_dir = ROLLBACK_DIR / release_id
    remote_dir = str(manifest.get("remote_dir", ""))
    failures: list[str] = []
    entries = manifest.get("files", [])
    if not isinstance(entries, list):
        return ["rollback manifest has an invalid files list"]

    for entry in reversed(entries):
        if not isinstance(entry, dict):
            failures.append("rollback manifest contains an invalid entry")
            continue
        relative_path = str(entry.get("path", ""))
        if not relative_path:
            failures.append("rollback manifest contains an empty path")
            continue

        try:
            if bool(entry.get("existed")):
                backup_value = entry.get("backup")
                if not isinstance(backup_value, str):
                    raise ValueError("missing backup path")
                backup_path = release_dir / backup_value
                target_dir = os.path.dirname(relative_path)
                ensure_remote_dir(
                    ftp,
                    "/".join(
                        part
                        for part in (remote_dir.strip("/"), target_dir)
                        if part
                    ),
                )
                with backup_path.open("rb") as handle:
                    ftp.storbinary(
                        f"STOR {join_remote_path(remote_dir, relative_path).rsplit('/', 1)[-1]}",
                        handle,
                    )
            else:
                try:
                    ftp.delete(join_remote_path(remote_dir, relative_path))
                except error_perm:
                    pass
        except Exception as exc:
            failures.append(f"{relative_path}: {exc}")

    return failures


def load_release_manifest(release_id: str) -> dict[str, object]:
    if not release_id or "/" in release_id or "\\" in release_id or release_id in {".", ".."}:
        raise ValueError("invalid release id")
    manifest_path = ROLLBACK_DIR / release_id / "manifest.json"
    if not manifest_path.is_file():
        raise FileNotFoundError(f"rollback manifest not found: {manifest_path}")
    value = json.loads(manifest_path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError("rollback manifest must contain an object")
    return value


def perform_healthcheck(config: dict[str, str]) -> tuple[bool, str]:
    url = get_setting(config, "DEPLOY_HEALTHCHECK_URL")
    if not url:
        return True, "skipped (DEPLOY_HEALTHCHECK_URL is empty)"

    timeout = float(get_setting(config, "DEPLOY_HEALTHCHECK_TIMEOUT", "20"))
    request = Request(
        url,
        headers={"User-Agent": f"teatro-museo-deploy/{DEPLOY_HELPER_VERSION}"},
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            status = int(response.status)
            response.read(1024)
        if 200 <= status < 300:
            return True, f"HTTP {status}"
        return False, f"HTTP {status}"
    except HTTPError as exc:
        return False, f"HTTP {exc.code}"
    except (URLError, TimeoutError, ValueError) as exc:
        return False, str(exc)


def list_remote_files(
    ftp: FTP | FTP_TLS,
    remote_dir: str,
    current_relative_dir: str = "",
) -> list[str]:
    """List remote files using MLSD; used only by explicit --prune."""
    base = join_remote_path(remote_dir, current_relative_dir).rstrip("/") or "/"
    try:
        entries = list(ftp.mlsd(base))
    except Exception as exc:
        raise RuntimeError(
            "remote pruning requires an FTP server with MLSD support"
        ) from exc

    result: list[str] = []
    for name, facts in entries:
        if name in {".", ".."}:
            continue
        relative_path = (
            f"{current_relative_dir}/{name}".strip("/")
            if current_relative_dir
            else name
        )
        if is_blacklisted_file(name) or any(
            part in BLACKLIST_DIRS for part in Path(relative_path).parts[:-1]
        ):
            continue
        entry_type = facts.get("type", "")
        if entry_type == "dir":
            result.extend(list_remote_files(ftp, remote_dir, relative_path))
        elif entry_type == "file":
            result.append(relative_path)
        # Anything else (symlinks report as "OS.unix=slink:", plus any other
        # non-regular entry) is server infrastructure, not a deployed
        # artifact — never propose it for deletion. A stale public/uploads
        # symlink to the writable storage directory is exactly this case.
    return result


def prune_remote_files(
    ftp: FTP | FTP_TLS,
    remote_dir: str,
    include_bootstrap_files: bool,
    yes: bool,
    dry_run: bool = False,
) -> int:
    local_paths = {
        relative_path
        for relative_path, _ in iter_deployable_files(include_bootstrap_files)
    }
    remote_paths = list_remote_files(ftp, remote_dir)
    obsolete = sorted(path for path in remote_paths if path not in local_paths)
    if not obsolete:
        print("Remote prune: no obsolete non-runtime files found.")
        return 0

    print(f"Remote prune found {len(obsolete)} obsolete files:")
    for path in obsolete:
        print(f"  [obsolete] {path}")

    if dry_run:
        print("Dry run: no remote files were deleted.")
        return 0

    if not yes:
        if not sys.stdin.isatty():
            print("Error: non-interactive prune requires --yes.")
            return 1
        confirm = input("Delete these remote files? (y/n): ").strip().lower()
        if confirm not in {"y", "yes", "s", "si"}:
            print("Remote prune cancelled.")
            return 0

    failures: list[str] = []
    for path in obsolete:
        try:
            ftp.delete(join_remote_path(remote_dir, path))
        except Exception as exc:
            failures.append(f"{path}: {exc}")

    if failures:
        print("Remote prune completed with errors:")
        for failure in failures:
            print(f"  {failure}")
        return 1
    print(f"Remote prune deleted {len(obsolete)} files.")
    return 0


def rollback_command(config: dict[str, str], release_id: str) -> int:
    manifest = load_release_manifest(release_id)
    ftp: FTP | FTP_TLS | None = None
    try:
        ftp = connect_ftp(config)
        failures = restore_release_backup(ftp, manifest)
        if failures:
            print("Rollback completed with errors:")
            for failure in failures:
                print(f"  {failure}")
            return 1
        print(f"Rollback {release_id} completed successfully.")
        return 0
    finally:
        if ftp is not None:
            try:
                ftp.quit()
            except Exception:
                pass


def main() -> int:
    print(f"Starting deploy helper {DEPLOY_HELPER_VERSION}...")
    args = parse_args()
    config = load_config()

    if args.rollback:
        try:
            return rollback_command(config, args.rollback)
        except Exception as exc:
            print(f"Error during rollback: {exc}")
            return 1

    try:
        remote_dir = get_setting(config, "FTP_REMOTE_DIR").strip("/")
        last_deploy = get_last_deploy_time()
        current_time = time.time()
        modified_files = collect_modified_files(
            last_deploy,
            include_bootstrap_files=args.bootstrap,
        )

        if args.only:
            requested_paths = {
                os.path.normpath(path).replace(os.sep, "/") for path in args.only
            }
            invalid_paths = {
                path
                for path in requested_paths
                if os.path.isabs(path) or path == ".." or path.startswith("../")
            }
            if invalid_paths:
                print(f"Error: paths must be repository-relative: {sorted(invalid_paths)}")
                return 1
            modified_files = [
                (relative_path, full_path)
                for relative_path, full_path in modified_files
                if relative_path in requested_paths
            ]

        if not modified_files and not args.prune:
            print("Everything is up to date. No files changed since the last deploy.")
            return 0

        if modified_files:
            print(f"Found {len(modified_files)} new or modified files:")
            for relative_path, _ in sorted(modified_files):
                print(f"  [modified] {relative_path}")

        if args.dry_run and args.prune:
            # Listing obsolete remote files requires a live, read-only FTP
            # session (MLSD). No STOR/DELE call is ever made in this branch.
            ftp: FTP | FTP_TLS | None = None
            try:
                ftp = connect_ftp(config)
                prune_status = prune_remote_files(
                    ftp,
                    remote_dir,
                    include_bootstrap_files=args.bootstrap,
                    yes=args.yes,
                    dry_run=True,
                )
            finally:
                if ftp is not None:
                    try:
                        ftp.quit()
                    except Exception:
                        pass
            print("Dry run complete. No files were uploaded or deleted.")
            return prune_status

        if args.dry_run:
            print("Dry run complete. No files were uploaded or deleted.")
            return 0

        if not args.yes:
            if not sys.stdin.isatty():
                print("Error: non-interactive session detected. Re-run with --yes.")
                return 1
            confirm = input("\nUpload these changes now? (y/n): ").strip().lower()
            if confirm not in {"y", "yes", "s", "si"}:
                print("Deploy cancelled.")
                return 0

        ftp: FTP | FTP_TLS | None = None
        release_id: str | None = None
        manifest: dict[str, object] | None = None
        deploy_committed = False
        try:
            ftp = connect_ftp(config)
            if remote_dir:
                print(f"Validating remote base directory: {remote_dir}")
                ensure_remote_dir(ftp, remote_dir)

            if modified_files:
                release_id, manifest = create_release_backup(
                    ftp,
                    remote_dir,
                    modified_files,
                )
                print(f"Rollback backup created: {release_id}")

                for relative_path, full_local_path in sorted(modified_files):
                    remote_file_dir = os.path.dirname(relative_path)
                    target_dir = "/".join(
                        part
                        for part in (remote_dir, remote_file_dir)
                        if part and part != "."
                    )
                    ensure_remote_dir(ftp, target_dir)
                    print(f"Uploading: {relative_path}...", end="", flush=True)
                    with full_local_path.open("rb") as handle:
                        ftp.storbinary(
                            f"STOR {Path(relative_path).name}",
                            handle,
                        )
                    print(" OK")

                health_ok, health_message = perform_healthcheck(config)
                print(f"Health check: {health_message}")
                if not health_ok:
                    raise RuntimeError(f"health check failed: {health_message}")

                update_last_deploy_time(current_time)
                deploy_committed = True

            if args.prune:
                prune_status = prune_remote_files(
                    ftp,
                    remote_dir,
                    include_bootstrap_files=args.bootstrap,
                    yes=args.yes,
                )
                if prune_status != 0:
                    return prune_status

            uploaded = len(modified_files)
            suffix = f" Release: {release_id}." if release_id else ""
            print(f"Deploy finished successfully. {uploaded} files uploaded.{suffix}")
            return 0
        except Exception as exc:
            print(f"\nError during deploy: {exc}")
            if manifest is not None and not deploy_committed and ftp is not None:
                print(f"Attempting automatic rollback of {release_id}...")
                rollback_failures = restore_release_backup(ftp, manifest)
                if rollback_failures:
                    print("Automatic rollback completed with errors:")
                    for failure in rollback_failures:
                        print(f"  {failure}")
                else:
                    print("Automatic rollback completed successfully.")
            return 1
        finally:
            if ftp is not None:
                try:
                    ftp.quit()
                except Exception:
                    pass
    except (OSError, ValueError, TypeError) as exc:
        print(f"Error preparing deploy: {exc}")
        return 1


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print("\nDeploy interrupted.")
        raise SystemExit(130)
