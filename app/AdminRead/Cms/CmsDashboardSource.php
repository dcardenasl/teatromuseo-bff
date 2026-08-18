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
        $allowed = array_merge(
            array_column(self::COUNT_RESOURCES, 'permission'),
            ['cms.submissions.read'],
        );
        if (array_intersect($allowed, $permissions) === []) {
            return ['sections' => ['counts' => []]];
        }

        $branches = [];
        foreach (self::COUNT_RESOURCES as $type => $resource) {
            if (! in_array($resource['permission'], $permissions, true)) {
                continue;
            }

            $where = $resource['soft_delete'] ? ' WHERE deleted_at IS NULL' : '';
            $branches[] = sprintf(
                "SELECT 'count' AS row_type, '%s' AS resource, NULL AS item_type,
                        NULL AS item_id, NULL AS updated_at, NULL AS language_id,
                        NULL AS title, NULL AS slug, COUNT(*) AS total,
                        NULL AS submission_status
                 FROM %s%s",
                $type,
                $resource['table'],
                $where,
            );
        }

        if (in_array('cms.pages.read', $permissions, true)) {
            $branches[] = <<<'SQL'
                SELECT 'activity' AS row_type, 'pages' AS resource, 'page' AS item_type,
                       recent_pages.id AS item_id, recent_pages.updated_at,
                       translations.language_id, translations.title, translations.slug,
                       NULL AS total, NULL AS submission_status
                FROM (
                    SELECT id, updated_at
                    FROM cms_pages
                    WHERE deleted_at IS NULL
                    ORDER BY updated_at DESC
                    LIMIT 5
                ) recent_pages
                LEFT JOIN cms_page_translations translations
                    ON translations.page_id = recent_pages.id
                SQL;
        }

        if (in_array('cms.entries.read', $permissions, true)) {
            $branches[] = <<<'SQL'
                SELECT 'activity' AS row_type, 'entries' AS resource, 'entry' AS item_type,
                       recent_entries.id AS item_id, recent_entries.updated_at,
                       translations.language_id, translations.title, translations.slug,
                       NULL AS total, NULL AS submission_status
                FROM (
                    SELECT id, updated_at
                    FROM cms_entries
                    WHERE deleted_at IS NULL
                    ORDER BY updated_at DESC
                    LIMIT 5
                ) recent_entries
                LEFT JOIN cms_entry_translations translations
                    ON translations.entry_id = recent_entries.id
                SQL;
        }

        if (in_array('cms.submissions.read', $permissions, true)) {
            $branches[] = <<<'SQL'
                SELECT 'submission' AS row_type, 'submissions' AS resource, NULL AS item_type,
                       NULL AS item_id, NULL AS updated_at, NULL AS language_id,
                       NULL AS title, NULL AS slug, COUNT(*) AS total, status AS submission_status
                FROM cms_form_submissions
                GROUP BY status
                SQL;
        }

        $rows = ReadOnlyQuery::sql(
            $this->db,
            'SELECT row_type, resource, item_type, item_id, updated_at, language_id,
                    title, slug, total, submission_status
             FROM (' . implode("\nUNION ALL\n", $branches) . ') dashboard_rows
             ORDER BY CASE WHEN row_type = \'activity\' THEN updated_at ELSE NULL END DESC',
            [],
            'CMS dashboard projection',
        );

        $counts = [];
        $submissions = ['new' => 0, 'read' => 0, 'replied' => 0, 'spam' => 0, 'archived' => 0];
        $activity = [];
        foreach ($rows as $row) {
            $rowType = (string) ($row['row_type'] ?? '');
            if ($rowType === 'count') {
                $counts[(string) ($row['resource'] ?? '')] = (int) ($row['total'] ?? 0);
                continue;
            }

            if ($rowType === 'submission') {
                $submissions[(string) ($row['submission_status'] ?? '')] = (int) ($row['total'] ?? 0);
                continue;
            }

            $type = (string) ($row['item_type'] ?? '');
            $id = (int) ($row['item_id'] ?? 0);
            $key = $type . ':' . $id;
            if (! isset($activity[$key])) {
                $activity[$key] = [
                    'type' => $type,
                    'id' => $id,
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'translations' => [],
                ];
            }

            if ($row['language_id'] !== null) {
                $activity[$key]['translations'][] = [
                    'language_id' => (int) $row['language_id'],
                    'title' => (string) ($row['title'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                ];
            }
        }

        $sections = ['counts' => $counts];
        if (in_array('cms.submissions.read', $permissions, true)) {
            $sections['submissions'] = $submissions;
        }
        if ($activity !== []) {
            $sections['recent_activity'] = array_values(array_slice($activity, 0, 6));
        }

        return ['sections' => $sections];
    }
}
