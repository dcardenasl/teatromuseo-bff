<?php

declare(strict_types=1);

namespace App\AdminRead\Event;

use App\AdminRead\Contracts\AdminDashboardSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/** Permission-aware Event dashboard projection over the Event database. */
final class EventDashboardSource implements AdminDashboardSourceInterface
{
    /** @var array<string, array{table: string, permission: string, projection: string, title: string, soft_delete: bool, activity: bool}> */
    private const RESOURCES = [
        'events' => [
            'table' => 'events',
            'permission' => 'event.events.read',
            'projection' => 'id, title, updated_at',
            'title' => 'title',
            'soft_delete' => true,
            'activity' => true,
        ],
        'event_types' => [
            'table' => 'event_types',
            'permission' => 'event.event-types.read',
            'projection' => 'id, name, slug, updated_at',
            'title' => 'name',
            'soft_delete' => true,
            'activity' => true,
        ],
        'venues' => [
            'table' => 'venues',
            'permission' => 'event.venues.read',
            'projection' => 'id, name, slug, updated_at',
            'title' => 'name',
            'soft_delete' => true,
            'activity' => true,
        ],
        'occurrences' => [
            'table' => 'occurrences',
            'permission' => 'event.occurrences.read',
            'projection' => 'id, status, updated_at',
            'title' => 'status',
            'soft_delete' => true,
            'activity' => true,
        ],
        'event_references' => [
            'table' => 'event_references',
            'permission' => 'event.event-references.read',
            // Event references are count-only in the domain contract and do
            // not have a `name` column in their owned schema.
            'projection' => 'id, updated_at',
            'title' => '',
            'soft_delete' => true,
            'activity' => false,
        ],
        'ticket_types' => [
            'table' => 'ticket_types',
            'permission' => 'event.ticket-types.read',
            'projection' => 'id, name, updated_at',
            'title' => 'name',
            'soft_delete' => true,
            'activity' => true,
        ],
        'bookings' => [
            'table' => 'bookings',
            'permission' => 'event.bookings.read',
            'projection' => 'id, status, updated_at',
            'title' => 'status',
            'soft_delete' => true,
            'activity' => true,
        ],
        'tickets' => [
            'table' => 'tickets',
            'permission' => 'event.tickets.read',
            'projection' => 'id, holder_name, status, updated_at',
            'title' => 'holder_name',
            'soft_delete' => true,
            'activity' => true,
        ],
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions): array
    {
        $knownPermissions = array_column(self::RESOURCES, 'permission');
        if (array_intersect($knownPermissions, $permissions) === []) {
            return ['sections' => ['counts' => []]];
        }

        $counts = [];
        $activity = [];
        foreach (self::RESOURCES as $type => $resource) {
            if (! in_array($resource['permission'], $permissions, true)) {
                continue;
            }

            $builder = $this->db->table($resource['table']);
            if ($resource['soft_delete']) {
                $builder->where('deleted_at', null);
            }
            $counts[$type] = ReadOnlyQuery::count($builder, 'Event ' . $type);
            if ($resource['activity']) {
                $activity = array_merge($activity, $this->recent($resource, $type));
            }
        }

        usort(
            $activity,
            static fn (array $left, array $right): int => strcmp(
                (string) ($right['updated_at'] ?? ''),
                (string) ($left['updated_at'] ?? '')
            )
        );

        return ['sections' => [
            'counts' => $counts,
            'recent_activity' => array_slice($activity, 0, 6),
        ]];
    }

    /** @param array{table: string, permission: string, projection: string, title: string, soft_delete: bool, activity: bool} $resource */
    private function recent(array $resource, string $type): array
    {
        $builder = $this->db->table($resource['table'])
            ->select($resource['projection'])
            ->orderBy('updated_at', 'DESC')
            ->limit(5);
        if ($resource['soft_delete']) {
            $builder->where('deleted_at', null);
        }

        $rows = ReadOnlyQuery::rows($builder, 'Event ' . $type . ' activity');
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'type' => $type,
                'id' => (int) ($row['id'] ?? 0),
                'title' => trim((string) ($row[$resource['title']] ?? '')),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $items;
    }
}
