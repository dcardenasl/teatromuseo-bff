<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * Prevents production read projections from silently using an incomplete
 * database configuration.
 *
 * Every named read projection must use a real, explicitly configured MySQL
 * connection in production. The compatibility SQLite groups remain available
 * only to framework tooling and non-production tests.
 */
final class ReadOnlyDatabaseGuard
{
    /** @var list<string> */
    public const GROUPS = [
        'cms_readonly',
        'catalog_readonly',
        'event_readonly',
        'hub_readonly',
    ];

    /**
     * @param array<string, mixed> $configuration
     */
    public static function assertConfigured(string $group, array $configuration, string $environment): void
    {
        if (! in_array($group, self::GROUPS, true)) {
            throw new LogicException('Unknown read database group: ' . $group);
        }

        if ($environment !== 'production') {
            return;
        }

        $required = ['hostname', 'username', 'password', 'database', 'DBDriver'];
        $missing = [];

        foreach ($required as $key) {
            $value = $configuration[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new LogicException(sprintf(
                "Read database group '%s' is not configured for production. Missing: %s.",
                $group,
                implode(', ', $missing),
            ));
        }
    }
}
