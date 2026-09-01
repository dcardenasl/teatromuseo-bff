<?php

declare(strict_types=1);

namespace App\PublicRead\Event;

use App\PublicRead\Page\EventReaderInterface;
use App\PublicRead\Support\MediaHydrator;
use App\PublicRead\Support\PublicReadEnvelope;
use App\PublicRead\Support\PublicReadPagination;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/**
 * SQL-first public event reader. Hydration is limited to the selected page;
 * the legacy EventService listing remains available for the admin surface.
 */
final class PublicReadEventReader implements EventReaderInterface
{
    /** @var list<string> */
    private const PUBLIC_COLUMNS = [
        'id', 'uuid', 'title', 'event_type', 'description', 'cover_file_id',
        'gallery_file_ids', 'status', 'created_at', 'updated_at',
    ];

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly MediaHydrator $mediaHydrator,
        private readonly string $timezone = 'UTC',
        private readonly string $fallbackLocale = 'es',
    ) {
    }

    /** @param list<string> $fields */
    public function index(PublicReadEventRequestDTO $request, array $fields): ApiResult
    {
        $now = $this->now();
        $builder = $this->baseBuilder($now);
        $this->applyFilters($builder, $request);

        $countBuilder = clone $builder;
        $total = (int) $countBuilder->countAllResults();

        if ($request->sort === 'latest') {
            $builder->orderBy('e.created_at', 'DESC')->orderBy('e.id', 'DESC');
        } elseif ($request->sort === 'id') {
            $builder->orderBy('e.id', 'ASC');
        } elseif ($request->sort === 'title') {
            // The legacy projection is the only indexed title projection. The
            // localized title is hydrated after pagination, so keep this sort
            // stable and database-side without reintroducing a translation join.
            $builder->orderBy('e.title', 'ASC')->orderBy('e.id', 'ASC');
        } else {
            // Future events first, chronological; past events follow in reverse.
            $builder->orderBy('CASE WHEN occurrence_projection.next_occurrence_at IS NULL THEN 1 ELSE 0 END', 'ASC', false)
                ->orderBy('occurrence_projection.next_occurrence_at', 'ASC')
                ->orderBy('occurrence_projection.last_occurrence_at', 'DESC')
                ->orderBy('e.id', 'ASC');
        }

        $select = array_map(static fn (string $column): string => 'e.' . $column, $this->columnsFor($fields));
        $select[] = 'occurrence_projection.occurrence_revision AS occurrence_revision';
        if ($this->wants($fields, 'next_occurrence_at')) {
            $select[] = 'occurrence_projection.next_occurrence_at AS next_occurrence_at';
        }
        if ($this->wants($fields, 'last_occurrence_at')) {
            $select[] = 'occurrence_projection.last_occurrence_at AS last_occurrence_at';
        }

        $builder->select(implode(', ', $select), false);
        PublicReadPagination::apply($builder, $request->page, $request->perPage);
        $query = $builder->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        $hydrated = $this->hydrate($rows, $request->locale, false, $fields);

        return PublicReadEnvelope::success(
            locale: $request->locale,
            data: $hydrated['data'],
            sourceRevision: $hydrated['revision'],
            page: $request->page,
            perPage: $request->perPage,
            total: $total,
            domain: 'events',
            meta: ['fields' => $fields, 'query' => $request->toArray()],
        );
    }

    /** @param list<string> $fields */
    public function show(string $locale, string $idOrSlug, array $fields): ApiResult
    {
        $now = $this->now();
        $builder = $this->baseBuilder($now);
        $select = array_map(static fn (string $column): string => 'e.' . $column, $this->columnsFor($fields));
        $select[] = 'occurrence_projection.occurrence_revision AS occurrence_revision';
        $builder->select(implode(', ', $select), false);

        $idOrSlug = trim($idOrSlug);
        if (ctype_digit($idOrSlug)) {
            $builder->where('e.id', (int) $idOrSlug);
        } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $idOrSlug) === 1) {
            $builder->where('e.uuid', $idOrSlug);
        } else {
            $candidates = $this->localeCandidates($locale);
            $builder->groupStart()
                ->where('e.uuid', $idOrSlug)
                ->orWhere(
                    'e.id = ' . $this->slugResourceIdExpression($idOrSlug, $candidates),
                    null,
                    false,
                )
                ->groupEnd();
        }

        $query = $builder->get();
        $row = $query !== false ? $query->getRowArray() : null;
        if ($row === null) {
            return $this->notFound($locale);
        }

        $hydrated = $this->hydrate([$row], $locale, true, $fields);

        return PublicReadEnvelope::success(
            locale: $locale,
            data: $hydrated['data'][0] ?? [],
            sourceRevision: $hydrated['revision'],
            domain: 'events',
            meta: ['fields' => $fields, 'query' => ['id_or_slug' => $idOrSlug]],
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('Y-m-d H:i:s');
    }

    private function baseBuilder(string $now): BaseBuilder
    {
        $builder = $this->db->table('events e');
        $builder->join(
            $this->occurrenceProjection($now),
            'occurrence_projection.event_id = e.id',
            'inner',
            false,
        );
        $builder->where('e.status', 'published')->where('e.deleted_at', null);

        return $builder;
    }

    private function occurrenceProjection(string $now): string
    {
        $escapedNow = $this->db->escape($now);

        return <<<SQL
(
    SELECT o.event_id,
           MIN(CASE WHEN o.start_time >= {$escapedNow} THEN o.start_time END) AS next_occurrence_at,
           MAX(CASE WHEN o.start_time < {$escapedNow} THEN o.start_time END) AS last_occurrence_at,
           MAX(COALESCE(o.updated_at, o.created_at)) AS occurrence_revision
    FROM occurrences o
    WHERE o.deleted_at IS NULL
    GROUP BY o.event_id
) occurrence_projection
SQL;
    }

    private function applyFilters(BaseBuilder $builder, PublicReadEventRequestDTO $request): void
    {
        $localeCandidates = $this->localeCandidates($request->locale);
        $localeList = $this->escapedList($localeCandidates);

        if ($request->eventType !== '') {
            $builder->where('e.event_type', $request->eventType);
        }
        if ($request->search !== '') {
            $search = (string) $this->db->escape('%' . $request->search . '%');
            $builder->groupStart()
                ->like('e.title', $request->search)
                ->orLike('e.description', $request->search)
                ->orWhere(
                    "EXISTS (SELECT 1 FROM event_translations ets WHERE ets.translatable_type = 'event' AND ets.translatable_id = e.id AND ets.locale IN ({$localeList}) AND ets.field IN ('title', 'description') AND ets.value LIKE {$search})",
                    null,
                    false,
                )
                ->groupEnd();
        }

        if ($request->from !== '' || $request->to !== '') {
            $range = ['ofilter.event_id = e.id', 'ofilter.deleted_at IS NULL'];
            if ($request->from !== '') {
                $range[] = 'ofilter.start_time >= ' . $this->db->escape($request->from);
            }
            if ($request->to !== '') {
                $range[] = 'ofilter.start_time <= ' . $this->db->escape($request->to);
            }
            $builder->where(
                'EXISTS (SELECT 1 FROM occurrences ofilter WHERE ' . implode(' AND ', $range) . ')',
                null,
                false,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return array{data: list<array<string, mixed>>, revision: string}
     */
    private function hydrate(array $rows, string $locale, bool $detail, array $fields): array
    {
        if ($rows === []) {
            return ['data' => [], 'revision' => 'events:empty'];
        }

        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
        $candidates = $this->localeCandidates($locale);
        $needsContent = $this->needsContent($fields);
        $needsTranslations = $fields === [] || $needsContent || $this->wants($fields, 'translations');
        $needsSlugs = $fields === [] || $this->wants($fields, 'slug') || $this->wants($fields, 'slugs');
        $needsOccurrences = $detail && $this->wants($fields, 'occurrences');
        $childRevision = null;

        $translations = [];
        if ($needsTranslations) {
            $translationQuery = $this->db->table('event_translations')
                ->select('translatable_id, locale, field, value, updated_at, created_at')
                ->where('translatable_type', 'event')
                ->whereIn('translatable_id', $ids)
                ->whereIn('locale', $candidates)
                ->get();
            $translationRows = $translationQuery !== false ? $translationQuery->getResultArray() : [];
            foreach ($translationRows as $translation) {
                $id = (int) $translation['translatable_id'];
                $translationLocale = (string) $translation['locale'];
                $field = (string) $translation['field'];
                $translations[$id][$translationLocale][$field] = (string) $translation['value'];
                $childRevision = $this->maxTimestamp($childRevision, $translation);
            }
        }

        $slugs = [];
        if ($needsSlugs) {
            $slugQuery = $this->db->table('event_public_slugs')
                ->select('resource_id, locale, slug, updated_at, created_at')
                ->where('resource_type', 'event')
                ->whereIn('resource_id', $ids);
            // Detail/page resolution needs the complete language-link map,
            // including the default full projection (`fields=[]`). Listing
            // projections remain limited to the requested locale + fallback.
            if (! $detail && ! $this->wants($fields, 'slugs')) {
                $slugQuery->whereIn('locale', $candidates);
            }
            $slugResult = $slugQuery->get();
            $slugRows = $slugResult !== false ? $slugResult->getResultArray() : [];
            foreach ($slugRows as $slug) {
                $id = (int) $slug['resource_id'];
                $slugs[$id][(string) $slug['locale']] = (string) $slug['slug'];
                $childRevision = $this->maxTimestamp($childRevision, $slug);
            }
        }

        $occurrencesByEvent = [];
        if ($needsOccurrences) {
            $occurrenceQuery = $this->db->table('occurrences o')
                ->select('o.event_id, o.id, o.venue_id, o.start_time, o.end_time, o.status, o.capacity, o.available_spots, v.name AS venue_name')
                ->join('venues v', 'v.id = o.venue_id', 'left')
                ->whereIn('o.event_id', $ids)
                ->where('o.deleted_at', null)
                ->orderBy('o.start_time', 'ASC')
                ->get();
            $occurrences = $occurrenceQuery !== false ? $occurrenceQuery->getResultArray() : [];
            foreach ($occurrences as $occurrence) {
                $occurrencesByEvent[(int) $occurrence['event_id']][] = $occurrence;
                $childRevision = $this->maxTimestamp($childRevision, $occurrence);
            }
        }

        $resolveCover = $fields === [] || $this->wants($fields, 'cover_image');
        $resolveGallery = $fields === [] || $this->wants($fields, 'gallery_images');
        $fileIds = [];
        if ($resolveCover || $resolveGallery) {
            foreach ($rows as $row) {
                $fileIds = array_merge($fileIds, $this->fileIds($row, $resolveCover, $resolveGallery));
            }
        }
        $media = $this->mediaHydrator->resolve($fileIds);

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $localized = [];
            if ($needsContent) {
                $localized = [
                    'title' => $this->localizedValue($translations[$id] ?? [], $candidates, 'title', $row['title'] ?? ''),
                    'description' => $this->localizedValue($translations[$id] ?? [], $candidates, 'description', $row['description'] ?? ''),
                ];
            }

            $payload = [];
            if ($this->wants($fields, 'id')) {
                $payload['id'] = $id;
            }
            if ($this->wants($fields, 'uuid')) {
                $payload['uuid'] = (string) ($row['uuid'] ?? '');
            }
            if ($this->wants($fields, 'title')) {
                $payload['title'] = $localized['title'] ?? (string) ($row['title'] ?? '');
            }
            if ($this->wants($fields, 'event_type')) {
                $payload['event_type'] = (string) ($row['event_type'] ?? '');
            }
            if ($this->wants($fields, 'description')) {
                $payload['description'] = $localized['description'] ?? (string) ($row['description'] ?? '');
            }
            if ($this->wants($fields, 'cover_file_id')) {
                $payload['cover_file_id'] = isset($row['cover_file_id']) ? (int) $row['cover_file_id'] : null;
            }
            if ($this->wants($fields, 'gallery_file_ids')) {
                $payload['gallery_file_ids'] = $row['gallery_file_ids'] ?? null;
            }
            if ($resolveCover) {
                $payload['cover_image'] = $this->mediaHydrator->item($media, (int) ($row['cover_file_id'] ?? 0));
            }
            if ($resolveGallery) {
                $payload['gallery_images'] = $this->mediaHydrator->gallery($media, $row['gallery_file_ids'] ?? null);
            }
            if ($this->wants($fields, 'translations')) {
                $payload['translations'] = $this->translationPayload($translations[$id] ?? []);
            }
            if ($this->wants($fields, 'localized')) {
                $payload['localized'] = $localized;
            }
            if ($this->wants($fields, 'slug')) {
                $payload['slug'] = $this->localizedValue($slugs[$id] ?? [], $candidates, null, '');
            }
            if ($this->wants($fields, 'slugs')) {
                $payload['slugs'] = $slugs[$id] ?? [];
            }
            if ($this->wants($fields, 'occurrences')) {
                $payload['occurrences'] = $occurrencesByEvent[$id] ?? [];
            }
            if ($this->wants($fields, 'next_occurrence_at')) {
                $payload['next_occurrence_at'] = $row['next_occurrence_at'] ?? null;
            }
            if ($this->wants($fields, 'last_occurrence_at')) {
                $payload['last_occurrence_at'] = $row['last_occurrence_at'] ?? null;
            }
            if ($this->wants($fields, 'status')) {
                $payload['status'] = (string) ($row['status'] ?? '');
            }
            if ($this->wants($fields, 'created_at')) {
                $payload['created_at'] = $row['created_at'] ?? null;
            }
            if ($this->wants($fields, 'updated_at')) {
                $payload['updated_at'] = $row['updated_at'] ?? null;
            }

            $result[] = $payload;
        }

        return ['data' => $result, 'revision' => $this->revision($rows, $childRevision)];
    }

    /** @param list<string> $fields */
    private function needsContent(array $fields): bool
    {
        return $fields === [] || $this->wants($fields, 'title') || $this->wants($fields, 'description') || $this->wants($fields, 'localized');
    }

    /** @param list<string> $fields */
    private function wants(array $fields, string $field): bool
    {
        return $fields === [] || in_array($field, $fields, true);
    }

    /**
     * @param array<string, array<string, string>|string> $rows
     * @param list<string> $candidates
     */
    private function localizedValue(array $rows, array $candidates, ?string $field, mixed $legacy): string
    {
        foreach ($candidates as $candidate) {
            $value = $rows[$candidate] ?? null;
            if ($field !== null && is_array($value)) {
                $value = $value[$field] ?? null;
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return is_scalar($legacy) ? (string) $legacy : '';
    }

    /** @return list<string> */
    private function localeCandidates(string $locale): array
    {
        $candidates = [];
        $append = static function (string $candidate) use (&$candidates): void {
            if ($candidate !== '' && ! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        };

        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        while ($normalized !== '') {
            $append($normalized);
            $parts = explode('-', $normalized);
            array_pop($parts);
            $normalized = implode('-', $parts);
        }

        $fallback = strtolower(str_replace('_', '-', trim($this->fallbackLocale)));
        while ($fallback !== '') {
            $append($fallback);
            $parts = explode('-', $fallback);
            array_pop($parts);
            $fallback = implode('-', $parts);
        }

        return $candidates;
    }

    /** @param list<string> $values */
    private function escapedList(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => (string) $this->db->escape($value), $values));
    }

    /** @param list<string> $candidates */
    private function slugResourceIdExpression(string $slug, array $candidates): string
    {
        $priority = $this->localePriorityCase('ps.locale', $candidates);
        $localeList = $this->escapedList($candidates);

        return "(SELECT ps.resource_id FROM event_public_slugs ps WHERE ps.resource_type = 'event' AND ps.slug = "
            . $this->db->escape($slug)
            . " AND ps.locale IN ({$localeList}) ORDER BY {$priority}, ps.resource_id ASC LIMIT 1)";
    }

    /** @param list<string> $candidates */
    private function localePriorityCase(string $column, array $candidates): string
    {
        $parts = ['CASE'];
        foreach ($candidates as $priority => $candidate) {
            $parts[] = 'WHEN ' . $column . ' = ' . $this->db->escape($candidate) . ' THEN ' . $priority;
        }

        return implode(' ', [...$parts, 'ELSE', (string) count($candidates), 'END']);
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function columnsFor(array $fields): array
    {
        if ($fields === []) {
            return self::PUBLIC_COLUMNS;
        }

        $columns = [];
        $columns[] = 'id';
        $columns[] = 'updated_at';
        foreach (self::PUBLIC_COLUMNS as $column) {
            if ($this->wants($fields, $column) && ! in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }
        if (($this->wants($fields, 'title') || $this->wants($fields, 'localized'))
            && ! in_array('title', $columns, true)) {
            $columns[] = 'title';
        }
        if (($this->wants($fields, 'description') || $this->wants($fields, 'localized'))
            && ! in_array('description', $columns, true)) {
            $columns[] = 'description';
        }
        if ($this->wants($fields, 'cover_image') && ! in_array('cover_file_id', $columns, true)) {
            $columns[] = 'cover_file_id';
        }
        if ($this->wants($fields, 'gallery_images') && ! in_array('gallery_file_ids', $columns, true)) {
            $columns[] = 'gallery_file_ids';
        }

        return array_values($columns);
    }

    /** @param array<string, mixed> $row */
    private function maxTimestamp(?string $current, array $row): ?string
    {
        $candidate = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
        if ($candidate === '') {
            return $current;
        }

        return $current === null || $candidate > $current ? $candidate : $current;
    }

    /** @param list<array<string, mixed>> $rows */
    private function revision(array $rows, ?string $childRevision): string
    {
        $updated = '';
        $children = $childRevision ?? '';
        $maxId = 0;
        foreach ($rows as $row) {
            $updated = max($updated, (string) ($row['updated_at'] ?? ''));
            $children = max($children, (string) ($row['occurrence_revision'] ?? ''));
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }

        return 'events:' . ($updated !== '' ? $updated : 'empty')
            . ':children:' . ($children !== '' ? $children : 'empty')
            . ':' . $maxId;
    }

    /**
     * @param array<string, array<string, string>> $rows
     * @return list<array<string, string>>
     */
    private function translationPayload(array $rows): array
    {
        $payload = [];
        foreach ($rows as $locale => $fields) {
            $payload[] = ['locale' => $locale, ...$fields];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<int>
     */
    private function fileIds(array $row, bool $resolveCover, bool $resolveGallery): array
    {
        $ids = [];
        if ($resolveCover && (int) ($row['cover_file_id'] ?? 0) > 0) {
            $ids[] = (int) $row['cover_file_id'];
        }
        if ($resolveGallery) {
            foreach (explode(',', (string) ($row['gallery_file_ids'] ?? '')) as $rawId) {
                if ((int) trim($rawId) > 0) {
                    $ids[] = (int) trim($rawId);
                }
            }
        }

        return array_values(array_unique($ids));
    }


    private function notFound(string $locale): ApiResult
    {
        return new ApiResult([
            'version' => 1,
            'ok' => false,
            'data' => null,
            'meta' => ['locale' => $locale, 'source_revision' => 'events:empty'],
            'source' => ['domain' => 'events', 'state' => 'unavailable', 'stale' => false],
            'messages' => ['Event not found.'],
        ], 404);
    }
}
