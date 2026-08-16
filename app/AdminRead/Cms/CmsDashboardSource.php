<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminDashboardSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/** Permission-aware CMS dashboard projection over the CMS read-only database. */
final class CmsDashboardSource implements AdminDashboardSourceInterface
{
    /** @var array<string, array{table: string, permission: string, soft_delete: bool}> */
    private const COUNT_RESOURCES = [
        'pages' => ['table' => 'cms_pages', 'permission' => 'cms.pages.read', 'soft_delete' => true],
        'entries' => ['table' => 'cms_entries', 'permission' => 'cms.entries.read', 'soft_delete' => true],
        'collections' => ['table' => 'cms_collections', 'permission' => 'cms.collections.read', 'soft_delete' => false],
        'menus' => ['table' => 'cms_menus', 'permission' => 'cms.menus.read', 'soft_delete' => true],
        'categories' => ['table' => 'cms_categories', 'permission' => 'cms.categories.read', 'soft_delete' => false],
        'tags' => ['table' => 'cms_tags', 'permission' => 'cms.tags.read', 'soft_delete' => false],
        'forms' => ['table' => 'cms_forms', 'permission' => 'cms.forms.read', 'soft_delete' => false],
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
        $allowed = array_merge(
            array_column(self::COUNT_RESOURCES, 'permission'),
            ['cms.submissions.read']
        );
        if (array_intersect($allowed, $permissions) === []) {
            return ['sections' => ['counts' => []]];
        }

        $pages = in_array('cms.pages.read', $permissions, true)
            ? $this->recentRows('cms_pages', 'id, updated_at', true, 'CMS pages')
            : [];
        $entries = in_array('cms.entries.read', $permissions, true)
            ? $this->recentRows('cms_entries', 'id, updated_at', true, 'CMS entries')
            : [];

        $counts = [];
        foreach (self::COUNT_RESOURCES as $key => $resource) {
            if (! in_array($resource['permission'], $permissions, true)) {
                continue;
            }

            $builder = $this->db->table($resource['table']);
            if ($resource['soft_delete']) {
                $builder->where('deleted_at', null);
            }
            $counts[$key] = ReadOnlyQuery::count($builder, 'CMS ' . $key);
        }

        $sections = ['counts' => $counts];
        if (in_array('cms.submissions.read', $permissions, true)) {
            $sections['submissions'] = $this->submissionCounts();
        }
        if ($pages !== [] || $entries !== []) {
            $sections['recent_activity'] = $this->recentActivity($pages, $entries);
        }

        return ['sections' => $sections];
    }

    /** @return list<array<string, mixed>> */
    private function recentRows(string $table, string $projection, bool $softDelete, string $label): array
    {
        $builder = $this->db->table($table)->select($projection)->orderBy('updated_at', 'DESC')->limit(5);
        if ($softDelete) {
            $builder->where('deleted_at', null);
        }

        return ReadOnlyQuery::rows($builder, $label);
    }

    /** @return array<string, int> */
    private function submissionCounts(): array
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('cms_form_submissions')
                ->select('status, COUNT(*) AS total', false)
                ->groupBy('status'),
            'CMS form submissions'
        );
        $counts = ['new' => 0, 'read' => 0, 'replied' => 0, 'spam' => 0, 'archived' => 0];
        foreach ($rows as $row) {
            $counts[(string) ($row['status'] ?? '')] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function recentActivity(array $pages, array $entries): array
    {
        $items = [];
        $pageIds = $this->ids($pages);
        $entryIds = $this->ids($entries);

        $pageTranslations = $this->translations('cms_page_translations', 'page_id', $pageIds, 'CMS page translations');
        $entryTranslations = $this->translations('cms_entry_translations', 'entry_id', $entryIds, 'CMS entry translations');

        foreach ($pages as $page) {
            $id = (int) ($page['id'] ?? 0);
            $items[] = [
                'type' => 'page',
                'id' => $id,
                'updated_at' => (string) ($page['updated_at'] ?? ''),
                'translations' => $pageTranslations[$id] ?? [],
            ];
        }
        foreach ($entries as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $items[] = [
                'type' => 'entry',
                'id' => $id,
                'updated_at' => (string) ($entry['updated_at'] ?? ''),
                'translations' => $entryTranslations[$id] ?? [],
            ];
        }

        usort(
            $items,
            static fn (array $left, array $right): int => strcmp(
                (string) ($right['updated_at'] ?? ''),
                (string) ($left['updated_at'] ?? '')
            )
        );

        return array_slice($items, 0, 6);
    }

    /** @param list<array<string, mixed>> $rows @return list<int> */
    private function ids(array $rows): array
    {
        return array_values(array_filter(
            array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows),
            static fn (int $id): bool => $id > 0
        ));
    }

    /** @param list<int> $ids @return array<int, list<array<string, mixed>>> */
    private function translations(string $table, string $foreignKey, array $ids, string $label): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = ReadOnlyQuery::rows(
            $this->db->table($table)
                ->select($foreignKey . ', language_id, title, slug')
                ->whereIn($foreignKey, $ids),
            $label
        );
        $grouped = [];
        foreach ($rows as $row) {
            $ownerId = (int) ($row[$foreignKey] ?? 0);
            $grouped[$ownerId][] = [
                'language_id' => (int) ($row['language_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }

        return $grouped;
    }
}
