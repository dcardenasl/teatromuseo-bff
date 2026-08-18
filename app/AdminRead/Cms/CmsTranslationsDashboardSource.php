<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminDashboardTranslationsSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/**
 * Fast translation coverage projection for the Admin dashboard.
 *
 * The full CMS audit remains the source of truth for the detailed audit page.
 * This dashboard card only needs one bounded aggregate per active language;
 * it must not call the CMS audit endpoint, which materializes every resource
 * and translation row in PHP.
 */
final class CmsTranslationsDashboardSource implements AdminDashboardTranslationsSourceInterface
{
    /**
     * @var array<string, array{table: string, translation: string, fk: string, where: string, required: list<string>, optional: list<string>, join: string}>
     */
    private const RESOURCE_BRANCHES = [
        'page' => [
            'table' => 'cms_pages r',
            'translation' => 'cms_page_translations',
            'fk' => 'page_id',
            'where' => 'r.deleted_at IS NULL',
            'required' => ['slug', 'title'],
            'optional' => ['excerpt', 'meta_title', 'meta_description'],
            'join' => '',
        ],
        'menu' => [
            'table' => 'cms_menus r',
            'translation' => 'cms_menu_translations',
            'fk' => 'menu_id',
            'where' => 'r.deleted_at IS NULL',
            'required' => ['name'],
            'optional' => [],
            'join' => '',
        ],
        'menu_item' => [
            'table' => 'cms_menu_items r',
            'translation' => 'cms_menu_item_translations',
            'fk' => 'menu_item_id',
            'where' => 'owner.deleted_at IS NULL',
            'required' => ['label'],
            'optional' => ['custom_url'],
            'join' => ' JOIN cms_menus owner ON owner.id = r.menu_id',
        ],
        'collection' => [
            'table' => 'cms_collections r',
            'translation' => 'cms_collection_translations',
            'fk' => 'collection_id',
            'where' => '1 = 1',
            'required' => ['slug', 'name'],
            'optional' => ['description', 'listing_title', 'listing_intro', 'default_meta_title', 'default_meta_description', 'entry_cta_label'],
            'join' => '',
        ],
        'category' => [
            'table' => 'cms_categories r',
            'translation' => 'cms_category_translations',
            'fk' => 'category_id',
            'where' => '1 = 1',
            'required' => ['name', 'slug'],
            'optional' => ['description', 'meta_title', 'meta_description'],
            'join' => '',
        ],
        'tag' => [
            'table' => 'cms_tags r',
            'translation' => 'cms_tag_translations',
            'fk' => 'tag_id',
            'where' => '1 = 1',
            'required' => ['name', 'slug'],
            'optional' => [],
            'join' => '',
        ],
        'entry' => [
            'table' => 'cms_entries r',
            'translation' => 'cms_entry_translations',
            'fk' => 'entry_id',
            'where' => 'r.deleted_at IS NULL',
            'required' => ['slug', 'title'],
            'optional' => ['excerpt', 'meta_title', 'meta_description'],
            'join' => '',
        ],
        'form' => [
            'table' => 'cms_forms r',
            'translation' => 'cms_form_translations',
            'fk' => 'form_id',
            'where' => '1 = 1',
            'required' => ['name', 'submit_label'],
            'optional' => ['description', 'success_message', 'error_message'],
            'join' => '',
        ],
        'form_field' => [
            'table' => 'cms_form_fields r',
            'translation' => 'cms_form_field_translations',
            'fk' => 'form_field_id',
            'where' => '1 = 1',
            'required' => ['label'],
            'optional' => [],
            'join' => '',
        ],
    ];

    /** @param BaseConnection<mixed,mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions, string $bearerToken): array
    {
        if (! in_array('cms.languages.read', $permissions, true)) {
            return ['sections' => ['translations' => []]];
        }

        // The token is intentionally not used here. Authorization was already
        // resolved by effectivepermissionsauth; this source only reads the
        // CMS replica through the named SELECT-only connection.
        unset($bearerToken);

        $branches = array_map(
            fn (array $definition): string => $this->resourceBranch($definition),
            array_values(self::RESOURCE_BRANCHES),
        );
        $branches[] = $this->settingsBranch();
        $branches[] = $this->blocksBranch();

        $rows = ReadOnlyQuery::sql(
            $this->db,
            <<<'SQL'
            WITH active_languages AS (
                SELECT id, code, name, native_name, is_default
                FROM cms_languages
                WHERE is_active = 1
            ), resource_completion AS (
            SQL
            . implode("\nUNION ALL\n", $branches)
            . <<<'SQL'
            )
            SELECT languages.id AS language_id,
                   languages.code,
                   COALESCE(languages.native_name, languages.name) AS name,
                   languages.is_default,
                   COALESCE(SUM(resource_completion.total_elements), 0) AS total_elements,
                   COALESCE(SUM(resource_completion.completed_elements), 0) AS completed_elements
            FROM active_languages languages
            LEFT JOIN resource_completion
                ON resource_completion.language_id = languages.id
            GROUP BY languages.id, languages.code, languages.name,
                     languages.native_name, languages.is_default
            ORDER BY languages.id ASC
            SQL,
            [],
            'CMS translation dashboard projection',
        );

        return ['sections' => ['translations' => array_map(
            static function (array $row): array {
                $total = (int) ($row['total_elements'] ?? 0);
                $completed = min($total, (int) ($row['completed_elements'] ?? 0));

                return [
                    'language_id' => (int) ($row['language_id'] ?? 0),
                    'code' => (string) ($row['code'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'is_default' => (bool) ($row['is_default'] ?? false),
                    'total_elements' => $total,
                    'completed_elements' => $completed,
                    'percentage' => $total > 0 ? (int) round($completed / $total * 100) : 100,
                ];
            },
            $rows,
        )]];
    }

    /** @param array{table: string, translation: string, fk: string, where: string, required: list<string>, optional: list<string>, join: string} $definition */
    private function resourceBranch(array $definition): string
    {
        $required = $this->allFieldsPresent($definition['required']);
        $optional = $this->optionalFieldsConsistent(
            $definition['translation'],
            $definition['fk'],
            $definition['optional'],
        );
        $complete = sprintf(
            "t.id IS NOT NULL AND (%s) AND (%s)",
            $required,
            $optional,
        );

        return sprintf(
            "SELECT languages.id AS language_id, COUNT(*) AS total_elements,\n"
            . "       SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS completed_elements\n"
            . "FROM active_languages languages\n"
            . "CROSS JOIN %s%s\n"
            . "LEFT JOIN %s t ON t.%s = r.id AND t.language_id = languages.id\n"
            . "WHERE %s\n"
            . "GROUP BY languages.id",
            $complete,
            $definition['table'],
            $definition['join'],
            $definition['translation'],
            $definition['fk'],
            $definition['where'],
        );
    }

    private function settingsBranch(): string
    {
        return <<<'SQL'
            SELECT languages.id AS language_id, COUNT(*) AS total_elements,
                   SUM(CASE WHEN (languages.is_default = 1
                                  AND r.setting_value IS NOT NULL
                                  AND TRIM(r.setting_value) <> '')
                                  OR (languages.is_default <> 1
                                      AND t.id IS NOT NULL
                                      AND t.setting_value IS NOT NULL
                                      AND TRIM(t.setting_value) <> '')
                            THEN 1 ELSE 0 END) AS completed_elements
            FROM active_languages languages
            CROSS JOIN cms_settings r
            LEFT JOIN cms_setting_translations t
                ON t.setting_id = r.id AND t.language_id = languages.id
            WHERE r.is_translatable = 1
            GROUP BY languages.id
            SQL;
    }

    private function blocksBranch(): string
    {
        // Block schemas are JSON and may use either an explicit translatable
        // flag or one of the three content field types used by the CMS. This
        // portable predicate keeps the dashboard projection SQL-only on both
        // MySQL 8 and the SQLite contract fixture. The detailed block auditor
        // remains responsible for schema-specific field diagnostics.
        //
        // An optional block with no content in any language is complete even
        // when it has no translation rows. This is the same
        // shouldReportMissing() rule used by the full audit: empty containers
        // (for example, a gallery whose content lives in child instances) do
        // not create missing-translation work. Required fields, or content in
        // at least one language, make the per-language row actionable again.
        return <<<'SQL'
            SELECT languages.id AS language_id, COUNT(*) AS total_elements,
                   SUM(CASE WHEN (
                                  CASE
                                      WHEN INSTR(b.schema_definition, '"config_fields"') > 0
                                      THEN SUBSTR(
                                          b.schema_definition,
                                          1,
                                          INSTR(b.schema_definition, '"config_fields"') - 1
                                      )
                                      ELSE b.schema_definition
                                  END LIKE '%"required":true%'
                                  OR CASE
                                      WHEN INSTR(b.schema_definition, '"config_fields"') > 0
                                      THEN SUBSTR(
                                          b.schema_definition,
                                          1,
                                          INSTR(b.schema_definition, '"config_fields"') - 1
                                      )
                                      ELSE b.schema_definition
                                  END LIKE '%"required": true%'
                                  OR EXISTS (
                                      SELECT 1
                                      FROM cms_block_instance_translations any_t
                                      WHERE any_t.instance_id = r.id
                                        AND any_t.block_data IS NOT NULL
                                        AND TRIM(any_t.block_data) NOT IN ('', '{}', '[]', 'null')
                                  )
                              )
                              THEN CASE WHEN t.id IS NOT NULL
                                             AND t.block_data IS NOT NULL
                                             AND TRIM(t.block_data) NOT IN ('', '{}', '[]', 'null')
                                        THEN 1 ELSE 0 END
                              ELSE 1 END) AS completed_elements
            FROM active_languages languages
            CROSS JOIN cms_block_instances r
            INNER JOIN cms_content_blocks b
                ON b.id = r.block_id
            LEFT JOIN cms_block_instance_translations t
                ON t.instance_id = r.id AND t.language_id = languages.id
            WHERE r.is_active = 1
              AND (
                  b.schema_definition LIKE '%"type":"text"%'
                  OR b.schema_definition LIKE '%"type":"textarea"%'
                  OR b.schema_definition LIKE '%"type":"richtext"%'
                  OR b.schema_definition LIKE '%"translatable":true%'
                  OR b.schema_definition LIKE '%"translatable": true%'
              )
            GROUP BY languages.id
            SQL;
    }

    /** @param list<string> $fields */
    private function allFieldsPresent(array $fields): string
    {
        if ($fields === []) {
            return '1 = 1';
        }

        return implode(' AND ', array_map(
            static fn (string $field): string => sprintf(
                "t.%s IS NOT NULL AND TRIM(t.%s) <> ''",
                $field,
                $field,
            ),
            $fields,
        ));
    }

    /** @param list<string> $fields */
    private function optionalFieldsConsistent(string $translationTable, string $foreignKey, array $fields): string
    {
        if ($fields === []) {
            return '1 = 1';
        }

        return implode(' AND ', array_map(
            static fn (string $field): string => sprintf(
                "(NULLIF(TRIM(t.%1\$s), '') IS NOT NULL OR NOT EXISTS ("
                . "SELECT 1 FROM %2\$s other_t WHERE other_t.%3\$s = r.id "
                . "AND other_t.language_id <> languages.id "
                . "AND NULLIF(TRIM(other_t.%1\$s), '') IS NOT NULL))",
                $field,
                $translationTable,
                $foreignKey,
            ),
            $fields,
        ));
    }
}
