<?php

declare(strict_types=1);

namespace App\PublicRead;

use App\Support\PublicReadCallerContext;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;

/** Read-only catalog facets kept in the Catalog database. */
final class CatalogFacetReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param bool $withCounts include a bounded `item_count` per row — the
     *     count of published items currently eligible for the requesting
     *     caller (respects kiosk curation the same way the item listing
     *     does), so a consumer can decide whether to show a category/
     *     technique without a separate full-listing fetch just to check.
     * @return list<array<string, mixed>>
     */
    public function categories(bool $withCounts = false): array
    {
        $builder = $this->db->table('categories c')
            ->select('c.id, c.name, c.slug, c.icon, c.short_description, c.sort_order');
        if ($withCounts) {
            $builder->select(
                '(SELECT COUNT(*) FROM collection_items ci WHERE ci.category_id = c.id AND '
                    . $this->eligibleItemPredicate() . ') AS item_count',
                false,
            );
        }
        $query = $builder
            ->where('c.deleted_at', null)
            ->orderBy('c.sort_order', 'ASC')
            ->orderBy('c.id', 'ASC')
            ->get();

        return $query === false ? [] : array_values($query->getResultArray());
    }

    /** @return list<array<string, mixed>> */
    public function techniques(bool $withCounts = false): array
    {
        $builder = $this->db->table('techniques t')
            ->select('t.id, t.name, t.slug, t.summary, t.video_url, t.pdf_file_id, t.sort_order');
        if ($withCounts) {
            $builder->select(
                '(SELECT COUNT(DISTINCT cit.collection_item_id) FROM collection_item_technique cit'
                    . ' JOIN collection_items ci ON ci.id = cit.collection_item_id'
                    . ' WHERE cit.technique_id = t.id AND ' . $this->eligibleItemPredicate() . ') AS item_count',
                false,
            );
        }
        $query = $builder
            ->where('t.deleted_at', null)
            ->orderBy('t.sort_order', 'ASC')
            ->orderBy('t.id', 'ASC')
            ->get();

        return $query === false ? [] : array_values($query->getResultArray());
    }

    /**
     * WHERE fragment matching the exact publication + kiosk-curation
     * predicate {@see \App\PublicRead\Catalog\PublicReadCollectionItemReader}
     * applies, so counts here never diverge from what a listing call would
     * actually return.
     */
    private function eligibleItemPredicate(): string
    {
        $predicate = "ci.is_active = 1 AND ci.status = 'published' AND ci.deleted_at IS NULL";
        if (PublicReadCallerContext::isTotem()) {
            $predicate .= ' AND ci.show_in_totem = 1';
        }

        return $predicate;
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
