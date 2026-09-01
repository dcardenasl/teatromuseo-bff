<?php

declare(strict_types=1);

namespace App\AdminRead\Event;

use App\AdminRead\Contracts\AdminEventWorkspaceSourceInterface;
use App\AdminRead\Support\CmsLanguageOptions;
use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\JsonProjectionDecoder;
use App\AdminRead\Support\PermissionGuard;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** One-read Event editor workspace; occurrences remain a separate lookup seam. */
final class AdminEventWorkspaceSource implements AdminEventWorkspaceSourceInterface
{
    private const MAX_TYPES = 250;

    /**
     * @param BaseConnection<mixed,mixed> $eventDb
     * @param BaseConnection<mixed,mixed> $cmsDb
     */
    public function __construct(
        private readonly BaseConnection $eventDb,
        private readonly BaseConnection $cmsDb,
    ) {
    }

    /** @param list<string> $permissions */
    public function workspace(?int $eventId, array $permissions): array
    {
        PermissionGuard::require($permissions, 'event.events.read');
        if ($eventId !== null && $eventId < 1) {
            throw new RuntimeException('A positive Event identifier is required.');
        }
        $event = $eventId === null ? null : $this->event($eventId);
        if ($eventId !== null && $event === null) {
            throw new RuntimeException('Event not found.');
        }

        return [
            'event' => $event,
            'eventTypes' => in_array('event.event-types.read', $permissions, true) ? $this->eventTypes() : [],
            'languages' => in_array('cms.languages.read', $permissions, true) ? $this->languages() : [],
            'quality' => [],
        ];
    }

    /** @return array<string,mixed>|null */
    private function event(int $eventId): ?array
    {
        $json = JsonArrayAggregateSql::forDatabase($this->eventDb);
        $aggregate = $json['aggregate'];
        $object = $json['object'];
        $suffix = $json['suffix'];
        $empty = "'[]'";
        $translations = <<<SQL
            SELECT {$aggregate}({$object}(
                'id', t.id,
                'locale', t.locale,
                'field', t.field,
                'value', t.value,
                'updated_at', t.updated_at
            ){$suffix}) AS translations_json
            FROM event_translations t
            WHERE t.translatable_type = 'event'
              AND t.translatable_id = ?
        SQL;
        $slugs = <<<SQL
            SELECT {$aggregate}({$object}(
                'locale', s.locale,
                'slug', s.slug
            ){$suffix}) AS slugs_json
            FROM event_public_slugs s
            WHERE s.resource_type = 'event'
              AND s.resource_id = ?
        SQL;
        $sql = <<<SQL
            SELECT event.id, event.uuid, event.title, event.event_type, event.description,
                   event.cover_file_id, event.gallery_file_ids, event.status,
                   event.created_at, event.updated_at, event.deleted_at,
                   COALESCE(translation_projection.translations_json, {$empty}) AS translations_json,
                   COALESCE(slug_projection.slugs_json, {$empty}) AS slugs_json
            FROM events event
            LEFT JOIN ({$translations}) translation_projection ON 1 = 1
            LEFT JOIN ({$slugs}) slug_projection ON 1 = 1
            WHERE event.id = ?
              AND event.deleted_at IS NULL
            LIMIT 1
        SQL;
        $rows = ReadOnlyQuery::sql(
            $this->eventDb,
            $sql,
            [$eventId, $eventId, $eventId],
            'Event admin workspace',
        );
        $event = $rows[0] ?? null;
        if (! is_array($event)) {
            return null;
        }
        $event['id'] = (int) ($event['id'] ?? 0);
        $event['translations'] = $this->translations($event['translations_json'] ?? null);
        $event['slugs'] = [];
        foreach (JsonProjectionDecoder::decodeList($event['slugs_json'] ?? null) as $slug) {
            $locale = strtolower(trim((string) ($slug['locale'] ?? '')));
            if ($locale !== '') {
                $event['slugs'][$locale] = (string) ($slug['slug'] ?? '');
            }
        }
        $defaultTranslation = $event['translations'][0] ?? [];
        $event['title'] = (string) ($event['title'] ?? $defaultTranslation['title'] ?? '');
        $event['description'] = (string) ($event['description'] ?? $defaultTranslation['description'] ?? '');
        $event['slug'] = (string) (array_values($event['slugs'])[0] ?? '');
        unset($event['translations_json'], $event['slugs_json']);

        return $event;
    }

    /** @return list<array<string,mixed>> */
    private function eventTypes(): array
    {
        $query = $this->eventDb->table('event_types')
            ->select('id, slug, name, sort_order, is_active')
            ->where('is_active', 1)
            ->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(self::MAX_TYPES)
            ->get();

        return $query !== false ? array_values($query->getResultArray()) : [];
    }

    /** @return list<array<string,mixed>> */
    private function languages(): array
    {
        return CmsLanguageOptions::list($this->cmsDb);
    }

    /** @return list<array<string,mixed>> */
    private function translations(mixed $value): array
    {
        $grouped = [];
        foreach (JsonProjectionDecoder::decodeList($value) as $row) {
            $locale = strtolower(trim((string) ($row['locale'] ?? '')));
            $field = trim((string) ($row['field'] ?? ''));
            if ($locale === '' || $field === '') {
                continue;
            }
            $grouped[$locale] ??= ['locale' => $locale];
            $grouped[$locale][$field] = $row['value'] ?? '';
            $grouped[$locale]['updated_at'] = $row['updated_at'] ?? null;
        }

        return array_values($grouped);
    }
}
