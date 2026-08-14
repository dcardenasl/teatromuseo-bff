<?php

declare(strict_types=1);

namespace App\PublicRead;

use CodeIgniter\Database\BaseConnection;

/** Read-only projection for active event type filters. */
final readonly class EventTypeReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private BaseConnection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $query = $this->db->table('event_types')
            ->select('id, slug, name, sort_order, is_active, created_at, updated_at')
            ->where('is_active', 1)->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get();

        return $query === false ? [] : array_values($query->getResultArray());
    }
}
