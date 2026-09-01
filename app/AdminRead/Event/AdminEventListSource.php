<?php

declare(strict_types=1);

namespace App\AdminRead\Event;

use App\AdminRead\Contracts\AdminEventListSourceInterface;
use App\AdminRead\Support\PermissionGuard;
use App\AdminRead\Support\ReadOnlyQuery;
use App\Support\RequestTelemetry;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;

/** One bounded Event read for the event-list filters and labels. */
final class AdminEventListSource implements AdminEventListSourceInterface
{
    private const CACHE_TTL = 30;
    private const MAX_EVENT_TYPES = 250;

    /** @param BaseConnection<mixed,mixed> $eventDb */
    public function __construct(
        private readonly BaseConnection $eventDb,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @param list<string> $permissions */
    public function bootstrap(array $permissions): array
    {
        PermissionGuard::require($permissions, 'event.events.read');
        $scope = array_values(array_unique($permissions));
        sort($scope);
        $cacheKey = 'admin_event_list_' . hash('sha256', implode("\0", $scope));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.event.list', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.event.list', 'miss');

        $eventTypes = [];
        if (in_array('event.event-types.read', $permissions, true)) {
            $rows = ReadOnlyQuery::rows(
                $this->eventDb->table('event_types')
                    ->select('id, slug, name, sort_order, is_active')
                    ->where('is_active', 1)
                    ->where('deleted_at', null)
                    ->orderBy('sort_order', 'ASC')
                    ->orderBy('id', 'ASC')
                    ->limit(self::MAX_EVENT_TYPES),
                'Event list types',
            );
            foreach ($rows as &$row) {
                $row['id'] = (int) ($row['id'] ?? 0);
                $row['is_active'] = (bool) ($row['is_active'] ?? false);
            }
            unset($row);
            $eventTypes = $rows;
        }

        $sections = ['eventTypes' => $eventTypes, 'quality' => []];
        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }
}
