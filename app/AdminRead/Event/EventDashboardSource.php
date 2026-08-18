<?php

declare(strict_types=1);

namespace App\AdminRead\Event;

use App\AdminRead\Contracts\AdminDashboardSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/** Permission-aware Event dashboard projection over the Event database. */
final class EventDashboardSource implements AdminDashboardSourceInterface
{
    /**
     * A method (not a `const`) so `soft_delete` keeps its declared `bool`
     * type instead of PHPStan narrowing it to the literal `true` every
     * current entry happens to share — it is a genuine per-resource flag for
     * future entries, not an always-true dead branch.
     *
     * @return array<string, array{table: string, permission: string, title: string, activity: bool, soft_delete: bool}>
     */
    private static function resources(): array
    {
        return [
            'events' => ['table' => 'events', 'permission' => 'event.events.read', 'title' => 'title', 'activity' => true, 'soft_delete' => true],
            'event_types' => ['table' => 'event_types', 'permission' => 'event.event-types.read', 'title' => 'name', 'activity' => true, 'soft_delete' => true],
            'venues' => ['table' => 'venues', 'permission' => 'event.venues.read', 'title' => 'name', 'activity' => true, 'soft_delete' => true],
            'occurrences' => ['table' => 'occurrences', 'permission' => 'event.occurrences.read', 'title' => 'status', 'activity' => true, 'soft_delete' => true],
            'event_references' => ['table' => 'event_references', 'permission' => 'event.event-references.read', 'title' => '', 'activity' => false, 'soft_delete' => true],
            'ticket_types' => ['table' => 'ticket_types', 'permission' => 'event.ticket-types.read', 'title' => 'name', 'activity' => true, 'soft_delete' => true],
            'bookings' => ['table' => 'bookings', 'permission' => 'event.bookings.read', 'title' => 'status', 'activity' => true, 'soft_delete' => true],
            'tickets' => ['table' => 'tickets', 'permission' => 'event.tickets.read', 'title' => 'holder_name', 'activity' => true, 'soft_delete' => true],
        ];
    }

    /** @param BaseConnection<mixed,mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions): array
    {
        $branches = [];
        foreach (self::resources() as $type => $resource) {
            if (! in_array($resource['permission'], $permissions, true)) {
                continue;
            }

            $where = $resource['soft_delete'] ? ' WHERE deleted_at IS NULL' : '';
            $branches[] = sprintf(
                "SELECT 'count' AS row_type, '%s' AS resource, NULL AS item_id,
                        NULL AS item_title, NULL AS updated_at, COUNT(*) AS total
                 FROM %s%s",
                $type,
                $resource['table'],
                $where,
            );

            if (! $resource['activity']) {
                continue;
            }

            $branches[] = sprintf(
                "SELECT 'activity' AS row_type, '%s' AS resource, id AS item_id,
                        item_title, updated_at, NULL AS total
                 FROM (
                     SELECT id, %s AS item_title, updated_at
                     FROM %s%s
                     ORDER BY updated_at DESC
                     LIMIT 5
                 ) recent_%s",
                $type,
                $resource['title'],
                $resource['table'],
                $where,
                $type,
            );
        }

        if ($branches === []) {
            return ['sections' => ['counts' => []]];
        }

        $rows = ReadOnlyQuery::sql(
            $this->db,
            'SELECT row_type, resource, item_id, item_title, updated_at, total
             FROM (' . implode("\nUNION ALL\n", $branches) . ') dashboard_rows
             ORDER BY CASE WHEN row_type = \'activity\' THEN updated_at ELSE NULL END DESC',
            [],
            'Event dashboard projection',
        );

        $counts = [];
        $activity = [];
        foreach ($rows as $row) {
            if (($row['row_type'] ?? '') === 'count') {
                $counts[(string) ($row['resource'] ?? '')] = (int) ($row['total'] ?? 0);
                continue;
            }

            $activity[] = [
                'type' => (string) ($row['resource'] ?? ''),
                'id' => (int) ($row['item_id'] ?? 0),
                'title' => trim((string) ($row['item_title'] ?? '')),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return ['sections' => [
            'counts' => $counts,
            'recent_activity' => array_slice($activity, 0, 6),
        ]];
    }
}
