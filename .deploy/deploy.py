#!/usr/bin/env python3
"""Compatibility entrypoint for the versioned deploy helper."""

from __future__ import annotations

import sys
from pathlib import Path


ROOT_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT_DIR / "scripts"))

from deploy_ftp import *  # noqa: E402,F401,F403


if __name__ == "__main__":
    raise SystemExit(main())
