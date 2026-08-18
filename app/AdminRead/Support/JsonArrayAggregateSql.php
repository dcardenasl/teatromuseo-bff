<?php

declare(strict_types=1);

namespace App\AdminRead\Support;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Select-only JSON array aggregation compatible with the supported databases.
 *
 * MariaDB 10.3 does not provide JSON_ARRAYAGG. GROUP_CONCAT(JSON_OBJECT(...))
 * preserves the JSON payload shape without requiring EXECUTE privileges or a
 * stored routine on the read-only database user.
 */
final class JsonArrayAggregateSql
{
    private const GROUP_CONCAT_MAX_LENGTH = 1048576;

    /**
     * @return array{aggregate: string, object: string, suffix: string}
     */
    public static function forDatabase(BaseConnection $db): array
    {
        $parts = self::forDriver((string) $db->DBDriver, $db->getVersion());
        if ($parts['suffix'] !== '') {
            $result = $db->query('SET SESSION group_concat_max_len = ' . self::GROUP_CONCAT_MAX_LENGTH);
            if ($result === false) {
                throw new RuntimeException('Unable to configure the MariaDB JSON aggregation length.');
            }
        }

        return $parts;
    }

    /**
     * @return array{aggregate: string, object: string, suffix: string}
     */
    public static function forDriver(string $driver, string $version): array
    {
        if (str_contains(strtolower($driver), 'sqlite')) {
            return [
                'aggregate' => 'json_group_array',
                'object' => 'json_object',
                'suffix' => '',
            ];
        }

        if (self::isLegacyMariaDb($version)) {
            return [
                'aggregate' => "CONCAT('[', GROUP_CONCAT",
                'object' => 'JSON_OBJECT',
                'suffix' => " SEPARATOR ','), ']'",
            ];
        }

        return [
            'aggregate' => 'JSON_ARRAYAGG',
            'object' => 'JSON_OBJECT',
            'suffix' => '',
        ];
    }

    private static function isLegacyMariaDb(string $version): bool
    {
        if (! str_contains(strtolower($version), 'mariadb')) {
            return false;
        }

        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $matches) !== 1) {
            return true;
        }

        return version_compare($matches[1], '10.5', '<');
    }
}
