<?php

declare(strict_types=1);

namespace App\PublicRead;

use Throwable;

/** Probes the four explicit read-only groups without exposing connection data. */
final class ReadDatabaseHealth
{
    /** @return array<string, array{status: string, response_time_ms?: float, message?: string}> */
    public function check(): array
    {
        $checks = [];
        $groups = [
            'cms' => ['group' => 'cms_readonly', 'prefix' => 'CMS_READONLY'],
            'catalog' => ['group' => 'catalog_readonly', 'prefix' => 'CATALOG_READONLY'],
            'event' => ['group' => 'event_readonly', 'prefix' => 'EVENT_READONLY'],
            'hub_files' => ['group' => 'hub_readonly', 'prefix' => 'HUB_READONLY'],
        ];

        foreach ($groups as $name => $definition) {
            $group = $definition['group'];
            $prefix = $definition['prefix'];
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'testing' && ! $this->isConfigured($prefix)) {
                $checks[$name] = ['status' => 'skipped', 'message' => 'read-only database not configured for tests'];
                continue;
            }

            $started = microtime(true);
            try {
                $connection = PublicReadContainer::database($group);
                $healthy = $connection->simpleQuery('SELECT 1');
                $checks[$name] = [
                    'status' => $healthy ? 'healthy' : 'unhealthy',
                    'response_time_ms' => round((microtime(true) - $started) * 1000, 2),
                ];
            } catch (Throwable) {
                $checks[$name] = [
                    'status' => 'unhealthy',
                    'response_time_ms' => round((microtime(true) - $started) * 1000, 2),
                    'message' => 'read-only database unreachable',
                ];
            }
        }

        return $checks;
    }

    /** @param array<string, array{status: string}> $checks */
    public function isHealthy(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! in_array($check['status'], ['healthy', 'skipped'], true)) {
                return false;
            }
        }

        return true;
    }

    private function isConfigured(string $prefix): bool
    {
        return trim((string) env($prefix . '_DB_HOSTNAME', '')) !== ''
            && trim((string) env($prefix . '_DB_DATABASE', '')) !== ''
            && trim((string) env($prefix . '_DB_USERNAME', '')) !== '';
    }
}
