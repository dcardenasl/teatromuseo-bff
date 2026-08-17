<?php

declare(strict_types=1);

namespace App\AdminRead\Event;

use App\AdminRead\Contracts\AdminEventLookupSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use App\Support\RequestTelemetry;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use InvalidArgumentException;

/** Bounded, permission-aware Event lookup projections for the Admin. */
final class AdminEventLookupSource implements AdminEventLookupSourceInterface
{
    /** @var list<string> */
    public const ALLOWED_CONTEXTS = ['occurrence', 'ticket_type', 'ticket', 'booking', 'event_reference'];

    private const ITEM_LIMIT = 100;
    private const CACHE_TTL = 30;

    /** @var array<string, list<string>> */
    private const CONTEXT_PERMISSIONS = [
        'occurrence' => ['event.events.read', 'event.venues.read'],
        'ticket_type' => ['event.events.read', 'event.occurrences.read'],
        'ticket' => ['event.bookings.read', 'event.ticket-types.read'],
        'booking' => ['event.ticket-types.read'],
        'event_reference' => ['event.events.read'],
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param list<string> $permissions
     * @return array<string, list<array<string, mixed>>>
     */
    public function read(string $context, array $permissions): array
    {
        if (! in_array($context, self::ALLOWED_CONTEXTS, true)) {
            throw new InvalidArgumentException('Unsupported Event lookup context.');
        }

        $this->requirePermissions($context, $permissions);

        $cacheKey = $this->cacheKey($context, $permissions);
        $cached   = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.event.lookup.' . $context, 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.event.lookup.' . $context, 'miss');

        $data = match ($context) {
            'occurrence' => $this->occurrenceLookups(),
            'ticket_type' => $this->ticketTypeLookups(),
            'ticket' => $this->ticketLookups(),
            'booking' => $this->bookingLookups(),
            'event_reference' => $this->eventReferenceLookups(),
        };

        $this->cache->save($cacheKey, $data, self::CACHE_TTL);

        return $data;
    }

    /** @param list<string> $permissions */
    private function requirePermissions(string $context, array $permissions): void
    {
        foreach (self::CONTEXT_PERMISSIONS[$context] as $permission) {
            if (! in_array($permission, $permissions, true)) {
                throw new AuthorizationException('The ' . $permission . ' permission is required.');
            }
        }
    }

    /** @param list<string> $permissions */
    private function cacheKey(string $context, array $permissions): string
    {
        $scope = array_values(array_unique($permissions));
        sort($scope);

        return 'admin_event_lookup_' . $context . '_' . hash('sha256', implode("\0", $scope));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function occurrenceLookups(): array
    {
        return [
            'events' => $this->events(),
            'venues' => $this->venues(),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function ticketTypeLookups(): array
    {
        return [
            'events' => $this->events(),
            'occurrences' => $this->occurrences(),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function ticketLookups(): array
    {
        return [
            'bookings' => $this->bookings(),
            'ticket_types' => $this->ticketTypes(),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function bookingLookups(): array
    {
        return ['ticket_types' => $this->ticketTypes()];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function eventReferenceLookups(): array
    {
        return ['events' => $this->events()];
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return ReadOnlyQuery::rows(
            $this->db->table('events')
                ->select('id, title')
                ->where('deleted_at', null)
                ->orderBy('title', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit(self::ITEM_LIMIT),
            'Event lookup events',
        );
    }

    /** @return list<array<string, mixed>> */
    private function venues(): array
    {
        return ReadOnlyQuery::rows(
            $this->db->table('venues')
                ->select('id, name')
                ->where('deleted_at', null)
                ->orderBy('name', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit(self::ITEM_LIMIT),
            'Event lookup venues',
        );
    }

    /** @return list<array<string, mixed>> */
    private function occurrences(): array
    {
        return ReadOnlyQuery::rows(
            $this->db->table('occurrences o')
                ->select('o.id, o.start_time, e.title AS event_title')
                ->join('events e', 'e.id = o.event_id', 'left')
                ->where('o.deleted_at', null)
                ->orderBy('o.start_time', 'ASC')
                ->orderBy('o.id', 'ASC')
                ->limit(self::ITEM_LIMIT),
            'Event lookup occurrences',
        );
    }

    /** @return list<array<string, mixed>> */
    private function ticketTypes(): array
    {
        return ReadOnlyQuery::rows(
            $this->db->table('ticket_types')
                ->select('id, name')
                ->where('deleted_at', null)
                ->orderBy('name', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit(self::ITEM_LIMIT),
            'Event lookup ticket types',
        );
    }

    /** @return list<array<string, mixed>> */
    private function bookings(): array
    {
        return ReadOnlyQuery::rows(
            $this->db->table('bookings')
                ->select('id, guest_email')
                ->where('deleted_at', null)
                ->orderBy('id', 'DESC')
                ->limit(self::ITEM_LIMIT),
            'Event lookup bookings',
        );
    }
}
