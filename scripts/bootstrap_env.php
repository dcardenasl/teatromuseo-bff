#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Updates existing .env keys and optionally generates JWT secret.
 *
 * Usage:
 *   php scripts/bootstrap_env.php --file .env --set "key=value" --generate-jwt
 */

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function parseArgs(array $argv): array
{
    $file = null;
    $sets = [];
    $generateJwt = false;

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];

        if ($arg === '--file') {
            if (! isset($argv[$i + 1])) {
                fail('Missing value for --file');
            }
            $file = $argv[++$i];
            continue;
        }

        if ($arg === '--set') {
            if (! isset($argv[$i + 1])) {
                fail('Missing value for --set');
            }
            $pair = $argv[++$i];
            $pos = strpos($pair, '=');
            if ($pos === false) {
                fail("Invalid --set value '$pair'. Expected key=value.");
            }
            $key = trim(substr($pair, 0, $pos));
            $value = trim(substr($pair, $pos + 1));
            if ($key === '') {
                fail("Invalid --set value '$pair'. Empty key.");
            }
            $sets[$key] = $value;
            continue;
        }

        if ($arg === '--generate-jwt') {
            $generateJwt = true;
            continue;
        }

        fail("Unknown argument: $arg");
    }

    if ($file === null) {
        fail('Missing required argument: --file');
    }

    if ($generateJwt && ! isset($sets['JWT_SECRET_KEY'])) {
        $sets['JWT_SECRET_KEY'] = bin2hex(random_bytes(32));
    }

    if ($sets === []) {
        fail('Nothing to update. Provide at least one --set or --generate-jwt.');
    }

    return [$file, $sets];
}

[$file, $sets] = parseArgs($argv);

if (! is_file($file)) {
    fail("File not found: $file");
}

$content = file_get_contents($file);
if ($content === false) {
    fail("Failed to read file: $file");
}

$lines = preg_split('/\R/', $content);
if ($lines === false) {
    fail("Failed to parse file: $file");
}

$foundKeys = [];
foreach ($lines as $index => $line) {
    if (! preg_match('/^\s*(?:[;#]\s*)?([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $line, $matches)) {
        continue;
    }

    $key = $matches[1];
    if (! array_key_exists($key, $sets)) {
        continue;
    }

    $lines[$index] = sprintf('%s = %s', $key, $sets[$key]);
    $foundKeys[$key] = true;
}

$missingKeys = array_values(array_diff(array_keys($sets), array_keys($foundKeys)));
foreach ($missingKeys as $key) {
    $lines[] = sprintf('%s = %s', $key, $sets[$key]);
}

$newContent = implode(PHP_EOL, $lines);
if (! str_ends_with($newContent, PHP_EOL)) {
    $newContent .= PHP_EOL;
}

if (file_put_contents($file, $newContent) === false) {
    fail("Failed to write file: $file");
}

fwrite(STDOUT, "Updated .env keys: " . implode(', ', array_keys($sets)) . PHP_EOL);
