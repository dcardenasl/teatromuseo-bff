<?php

declare(strict_types=1);

namespace App\PublicRead;

use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;

/** Read-only catalog facets kept in the Catalog database. */
final class CatalogFacetReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        $query = $this->db->table('categories')
            ->select('id, name, slug, icon, short_description, sort_order')
            ->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $query === false ? [] : array_values($query->getResultArray());
    }

    /** @return list<array<string, mixed>> */
    public function techniques(): array
    {
        $query = $this->db->table('techniques')
            ->select('id, name, slug, summary, video_url, pdf_file_id, sort_order')
            ->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $query === false ? [] : array_values($query->getResultArray());
    }

    /** @return array<string, mixed> */
    public function technique(string $idOrSlug): array
    {
        $builder = $this->db->table('techniques')
            ->select('id, name, slug, summary, video_url, pdf_file_id, sort_order')
            ->where('deleted_at', null);

        if (ctype_digit($idOrSlug)) {
            $builder->where('id', (int) $idOrSlug);
        } else {
            $builder->where('slug', $idOrSlug);
        }

        $query = $builder->get();
        $row = $query === false ? null : $query->getRowArray();
        if ($row === null) {
            throw new NotFoundException('Technique not found.');
        }

        return $row;
    }
}
