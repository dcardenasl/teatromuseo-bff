#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Updates Project metadata and replaces template strings across docs/files.
 *
 * Usage:
 *   php scripts/set_project_meta.php --name "My API" --description "My API description"
 */

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function parseArgs(array $argv): array
{
    $name = null;
    $description = null;

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];

        if ($arg === '--name') {
            if (! isset($argv[$i + 1])) {
                fail('Missing value for --name');
            }
            $name = trim($argv[++$i]);
            continue;
        }

        if ($arg === '--description') {
            if (! isset($argv[$i + 1])) {
                fail('Missing value for --description');
            }
            $description = trim($argv[++$i]);
            continue;
        }

        fail("Unknown argument: $arg");
    }

    if ($name === null || $name === '') {
        fail('Missing required argument: --name');
    }

    if ($description === null || $description === '') {
        fail('Missing required argument: --description');
    }

    return [$name, $description];
}

function phpSingleQuote(string $value): string
{
    $escaped = str_replace(['\\', '\''], ['\\\\', '\\\''], $value);
    return "'" . $escaped . "'";
}

function replaceProjectConstant(string $content, string $constName, string $value): string
{
    $pattern = '/\b(?:public\s+)?const\s+' . preg_quote($constName, '/') . '\s*=\s*(["\']).*?\1\s*;/';
    $replacement = 'public const ' . $constName . ' = ' . phpSingleQuote($value) . ';';

    if (! preg_match($pattern, $content)) {
        fail("Could not find {$constName} constant in Project.php");
    }

    return preg_replace($pattern, $replacement, $content, 1);
}

function replaceProjectProperty(string $content, string $propertyName, string $value): string
{
    $pattern = '/\bpublic\s+string\s+\$' . preg_quote($propertyName, '/') . '\s*=\s*(["\']).*?\1\s*;/';
    $replacement = 'public string $' . $propertyName . ' = ' . phpSingleQuote($value) . ';';

    if (! preg_match($pattern, $content)) {
        fail("Could not find \${$propertyName} property in Project.php");
    }

    return preg_replace($pattern, $replacement, $content, 1);
}

function updateProjectFile(string $path, string $name, string $description): void
{
    if (! is_file($path)) {
        fail("Project config not found: $path");
    }

    $content = file_get_contents($path);
    if ($content === false) {
        fail("Failed to read file: $path");
    }

    $content = replaceProjectConstant($content, 'NAME', $name);
    $content = replaceProjectConstant($content, 'DESCRIPTION', $description);
    $content = replaceProjectProperty($content, 'name', $name);
    $content = replaceProjectProperty($content, 'description', $description);

    if (file_put_contents($path, $content) === false) {
        fail("Failed to write file: $path");
    }
}

function orderedReplacements(string $name, string $description): array
{
    $replacements = [
        // Name variants (longest first to avoid partial collisions).
        'CodeIgniter 4 API Starter Kit 🚀' => $name,
        'CodeIgniter 4 API Starter Kit' => $name,
        'CodeIgniter 4 API Starter' => $name,
        'CI4 API Starter' => $name,
        'CI4 API' => $name,

        // Description variants.
        'RESTful API built with CodeIgniter 4, featuring JWT authentication, standardized responses, and comprehensive documentation.' => $description,
        'Production-ready REST API with JWT authentication' => $description,
        'Production-ready CI4 API with JWT authentication' => $description,
        'CodeIgniter 4 API Starter Kit with Layered Architecture' => $description,
    ];

    uksort($replacements, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });

    return $replacements;
}

[$name, $description] = parseArgs($argv);

$root = dirname(__DIR__);
updateProjectFile($root . '/app/Config/Project.php', $name, $description);

$files = [
    'README.md',
    'README.es.md',
    'GETTING_STARTED.md',
    'docs/README.md',
    'docs/README.es.md',
    'docs/architecture/README.md',
    'docs/architecture/README.es.md',
    'docs/architecture/TESTING.md',
    'GEMINI.md',
    'Dockerfile',
    'init.sh',
    'docker/mysql/my.cnf',
    'composer.json',
];

$replacements = orderedReplacements($name, $description);

$updated = [];
$skipped = [];

foreach ($files as $relativePath) {
    $path = $root . '/' . $relativePath;
    if (! is_file($path)) {
        $skipped[] = $relativePath;
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        fail("Failed to read file: $path");
    }

    $newContent = $content;
    foreach ($replacements as $old => $new) {
        $newContent = str_replace($old, $new, $newContent);
    }

    if ($newContent !== $content) {
        if (file_put_contents($path, $newContent) === false) {
            fail("Failed to write file: $path");
        }
        $updated[] = $relativePath;
    }
}

fwrite(STDOUT, "Project metadata updated in app/Config/Project.php" . PHP_EOL);
if ($updated !== []) {
    fwrite(STDOUT, "Updated files:" . PHP_EOL);
    foreach ($updated as $file) {
        fwrite(STDOUT, "  - {$file}" . PHP_EOL);
    }
}
if ($skipped !== []) {
    fwrite(STDOUT, "Skipped missing files:" . PHP_EOL);
    foreach ($skipped as $file) {
        fwrite(STDOUT, "  - {$file}" . PHP_EOL);
    }
}
