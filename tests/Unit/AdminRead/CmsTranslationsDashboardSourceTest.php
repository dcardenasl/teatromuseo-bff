<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Cms\CmsTranslationsDashboardSource;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CmsTranslationsDashboardSourceTest extends CIUnitTestCase
{
    private BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readDb = Database::connect('tests', false);

        foreach ($this->schema() as $table => $definition) {
            $this->readDb->query('CREATE TABLE ' . $table . ' (' . $definition . ')');
        }

        $this->readDb->table('cms_languages')->insertBatch([
            ['id' => 1, 'code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'is_default' => 1, 'is_active' => 1],
            ['id' => 2, 'code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_default' => 0, 'is_active' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->schema()) as $table) {
            $this->readDb->query('DROP TABLE IF EXISTS ' . $table);
        }
        $this->readDb->close();
        parent::tearDown();
    }

    public function testUsesTheReadOnlySqlProjectionForLanguageCoverage(): void
    {
        $this->readDb->table('cms_pages')->insert([
            'id' => 10,
            'deleted_at' => null,
        ]);
        $this->readDb->table('cms_page_translations')->insert([
            'page_id' => 10,
            'language_id' => 1,
            'slug' => 'inicio',
            'title' => 'Inicio',
        ]);

        $result = (new CmsTranslationsDashboardSource($this->readDb))->read(
            ['cms.languages.read'],
            'unused-token',
        );
        $stats = $result['sections']['translations'];

        $this->assertCount(2, $stats);
        $this->assertSame([
            'language_id' => 1,
            'code' => 'es',
            'name' => 'Español',
            'is_default' => true,
            'total_elements' => 1,
            'completed_elements' => 1,
            'percentage' => 100,
        ], $stats[0]);
        $this->assertSame(0, $stats[1]['completed_elements']);
        $this->assertSame(0, $stats[1]['percentage']);
    }

    /** @return array<string, string> */
    private function schema(): array
    {
        return [
            'cms_languages' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER',
            'cms_pages' => 'id INTEGER PRIMARY KEY, deleted_at TEXT',
            'cms_page_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, language_id INTEGER, slug TEXT, title TEXT, excerpt TEXT, meta_title TEXT, meta_description TEXT',
            'cms_menus' => 'id INTEGER PRIMARY KEY, deleted_at TEXT',
            'cms_menu_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER, language_id INTEGER, name TEXT',
            'cms_menu_items' => 'id INTEGER PRIMARY KEY, menu_id INTEGER',
            'cms_menu_item_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, menu_item_id INTEGER, language_id INTEGER, label TEXT, custom_url TEXT',
            'cms_collections' => 'id INTEGER PRIMARY KEY',
            'cms_collection_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id INTEGER, language_id INTEGER, slug TEXT, name TEXT, description TEXT, listing_title TEXT, listing_intro TEXT, default_meta_title TEXT, default_meta_description TEXT, entry_cta_label TEXT',
            'cms_categories' => 'id INTEGER PRIMARY KEY',
            'cms_category_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, language_id INTEGER, name TEXT, slug TEXT, description TEXT, meta_title TEXT, meta_description TEXT',
            'cms_tags' => 'id INTEGER PRIMARY KEY',
            'cms_tag_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tag_id INTEGER, language_id INTEGER, name TEXT, slug TEXT',
            'cms_entries' => 'id INTEGER PRIMARY KEY, deleted_at TEXT',
            'cms_entry_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, language_id INTEGER, slug TEXT, title TEXT, excerpt TEXT, meta_title TEXT, meta_description TEXT',
            'cms_forms' => 'id INTEGER PRIMARY KEY',
            'cms_form_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, form_id INTEGER, language_id INTEGER, name TEXT, submit_label TEXT, description TEXT, success_message TEXT, error_message TEXT',
            'cms_form_fields' => 'id INTEGER PRIMARY KEY',
            'cms_form_field_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, form_field_id INTEGER, language_id INTEGER, label TEXT, placeholder TEXT, help_text TEXT, option_labels TEXT, error_required TEXT, error_invalid TEXT',
            'cms_settings' => 'id INTEGER PRIMARY KEY, is_translatable INTEGER',
            'cms_setting_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, setting_id INTEGER, language_id INTEGER, setting_value TEXT',
            'cms_block_instances' => 'id INTEGER PRIMARY KEY, block_id INTEGER, is_active INTEGER',
            'cms_content_blocks' => 'id INTEGER PRIMARY KEY, schema_definition TEXT',
            'cms_block_instance_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, instance_id INTEGER, language_id INTEGER, block_data TEXT',
        ];
    }
}
