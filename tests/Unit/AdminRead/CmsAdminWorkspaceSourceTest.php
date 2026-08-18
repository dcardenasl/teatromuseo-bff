<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Cms\AdminCmsWorkspaceSource;
use App\PublicRead\Cms\FileUrlResolver;
use App\PublicRead\Support\FileMetaResolverInterface;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

final class CmsAdminWorkspaceSourceTest extends CIUnitTestCase
{
    private BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readDb = Database::connect('tests', false);
        Services::cache()->clean();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'cms_entry_tags', 'cms_entry_categories', 'cms_tag_translations', 'cms_tags', 'cms_entry_translations', 'cms_entries', 'cms_collection_translations', 'cms_collections', 'cms_page_translations', 'cms_pages',
            'cms_block_instance_translations', 'cms_block_instances', 'cms_content_blocks', 'cms_category_translations', 'cms_categories',
            'cms_forms', 'cms_languages',
        ] as $table) {
            $this->readDb->query('DROP TABLE IF EXISTS ' . $table);
        }
        $this->readDb->close();
        Services::cache()->clean();
        parent::tearDown();
    }

    public function testComposesPageAndBlockEditorDataWithoutDomainHttpCalls(): void
    {
        $this->readDb->table('cms_languages')->insert([
            'id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español',
            'is_default' => 1, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->readDb->table('cms_pages')->insert([
            'id' => 17, 'page_type' => 'about', 'status' => 'published', 'is_in_sitemap' => 1,
            'sort_order' => 1, 'created_at' => '2026-08-17 10:00:00', 'updated_at' => '2026-08-17 10:00:00',
        ]);
        $this->readDb->table('cms_page_translations')->insert([
            'page_id' => 17, 'language_id' => 1, 'slug' => 'quienes-somos', 'title' => 'Quiénes somos',
        ]);
        $this->readDb->table('cms_content_blocks')->insert([
            'id' => 5, 'block_key' => 'container', 'name' => 'Container',
            'schema_definition' => json_encode(['allowed_children' => ['team_member'], 'fields' => []]),
            'supports_pages' => 1, 'supports_entries' => 0, 'is_container' => 1, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->readDb->table('cms_block_instances')->insert([
            'id' => 20, 'block_id' => 5, 'owner_type' => 'page', 'owner_id' => 17,
            'sort_order' => 1, 'is_active' => 1, 'block_config' => '{}',
        ]);
        $this->readDb->table('cms_block_instance_translations')->insert([
            'instance_id' => 20, 'language_id' => 1, 'block_data' => '{}', 'is_published' => 1,
        ]);
        $this->readDb->table('cms_collections')->insert([
            'id' => 3, 'collection_key' => 'news', 'collection_type' => 'editorial', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->readDb->table('cms_collection_translations')->insert([
            'collection_id' => 3, 'language_id' => 1, 'slug' => 'noticias', 'name' => 'Noticias',
        ]);

        $metaResolver = new class () implements FileMetaResolverInterface {
            public function resolveMany(array $fileIds, string $context = 'public'): array
            {
                return [];
            }
        };
        $source = new AdminCmsWorkspaceSource(
            $this->readDb,
            new FileUrlResolver($metaResolver),
        );

        $result = $source->pageWorkspace(17, 20, ['cms.pages.read']);

        $this->assertSame('Quiénes somos', $result['page']['title']);
        $this->assertSame('quienes-somos', $result['page']['translations'][0]['slug']);
        $this->assertCount(1, $result['blocks']);
        $this->assertSame('container', $result['blockTypes'][5]['block_key']);
        $this->assertSame('es', $result['languages'][0]['code']);
        $this->assertSame(3, $result['collectionsMap']['news']);
        $this->assertSame(20, $result['block']['id']);
    }

    public function testComposesEntryAndBlockEditorDataWithoutDomainHttpCalls(): void
    {
        $this->readDb->table('cms_languages')->insert([
            'id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español',
            'is_default' => 1, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->readDb->table('cms_pages')->insert([
            'id' => 17, 'page_type' => 'about', 'status' => 'published', 'is_in_sitemap' => 1,
            'sort_order' => 1,
        ]);
        $this->readDb->table('cms_collections')->insert([
            'id' => 3, 'collection_key' => 'news', 'collection_type' => 'editorial', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->readDb->table('cms_entries')->insert([
            'id' => 7, 'collection_id' => 3, 'workflow_status' => 'published', 'is_featured' => 0,
            'view_count' => 0, 'sort_order' => 1, 'is_in_sitemap' => 1,
        ]);
        $this->readDb->table('cms_entry_translations')->insert([
            'entry_id' => 7, 'language_id' => 1, 'slug' => 'entrada', 'title' => 'Entrada',
        ]);
        $this->readDb->table('cms_content_blocks')->insert([
            'id' => 5, 'block_key' => 'rich_text', 'name' => 'Rich text',
            'schema_definition' => json_encode(['fields' => []]),
            'supports_pages' => 1, 'supports_entries' => 1, 'is_container' => 0, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->readDb->table('cms_block_instances')->insert([
            'id' => 20, 'block_id' => 5, 'owner_type' => 'entry', 'owner_id' => 7,
            'sort_order' => 1, 'is_active' => 1, 'block_config' => '{}',
        ]);

        $metaResolver = new class () implements FileMetaResolverInterface {
            public function resolveMany(array $fileIds, string $context = 'public'): array
            {
                return [];
            }
        };
        $source = new AdminCmsWorkspaceSource(
            $this->readDb,
            new FileUrlResolver($metaResolver),
        );

        $result = $source->entryWorkspace(7, 20, ['cms.entries.read']);

        $this->assertSame('Entrada', $result['entry']['title']);
        $this->assertCount(1, $result['blocks']);
        $this->assertSame('rich_text', $result['blockTypes'][5]['block_key']);
        $this->assertSame(20, $result['block']['id']);
    }

    private function createSchema(): void
    {
        $definitions = [
            'cms_languages' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER, fallback_language_id INTEGER, sort_order INTEGER',
            'cms_pages' => 'id INTEGER PRIMARY KEY, parent_id INTEGER, collection_id INTEGER, page_type TEXT, status TEXT, published_at TEXT, scheduled_at TEXT, sort_order INTEGER, sitemap_priority REAL, sitemap_changefreq TEXT, is_in_sitemap INTEGER, deleted_at TEXT, created_at TEXT, updated_at TEXT',
            'cms_page_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, language_id INTEGER, slug TEXT, title TEXT, excerpt TEXT, meta_title TEXT, meta_description TEXT, og_image_file_id INTEGER, og_image_url TEXT, og_type TEXT, canonical_url TEXT, robots TEXT, schema_data TEXT, created_at TEXT, updated_at TEXT',
            'cms_entries' => 'id INTEGER PRIMARY KEY, collection_id INTEGER, author_id INTEGER, workflow_status TEXT, published_at TEXT, scheduled_at TEXT, is_featured INTEGER, view_count INTEGER, sort_order INTEGER, wizard_extra TEXT, sitemap_priority REAL, sitemap_changefreq TEXT, is_in_sitemap INTEGER, deleted_at TEXT, created_at TEXT, updated_at TEXT',
            'cms_entry_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, language_id INTEGER, slug TEXT, title TEXT, excerpt TEXT, featured_file_id INTEGER, featured_image_url TEXT, meta_title TEXT, meta_description TEXT, og_image_file_id INTEGER, og_type TEXT, canonical_url TEXT, robots TEXT, schema_data TEXT, created_at TEXT, updated_at TEXT',
            'cms_content_blocks' => 'id INTEGER PRIMARY KEY, block_key TEXT, name TEXT, description TEXT, category TEXT, icon TEXT, schema_definition TEXT, supports_pages INTEGER, supports_entries INTEGER, is_container INTEGER, is_active INTEGER, sort_order INTEGER',
            'cms_block_instances' => 'id INTEGER PRIMARY KEY, block_id INTEGER, owner_type TEXT, owner_id INTEGER, parent_instance_id INTEGER, sort_order INTEGER, column_index INTEGER, is_active INTEGER, block_config TEXT, created_at TEXT, updated_at TEXT',
            'cms_block_instance_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, instance_id INTEGER, language_id INTEGER, block_data TEXT, is_published INTEGER, created_at TEXT, updated_at TEXT',
            'cms_collections' => 'id INTEGER PRIMARY KEY, collection_key TEXT, collection_type TEXT, enables_categories INTEGER, enables_tags INTEGER, block_template TEXT, is_active INTEGER, sort_order INTEGER',
            'cms_collection_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id INTEGER, language_id INTEGER, slug TEXT, name TEXT',
            'cms_forms' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, form_key TEXT, is_active INTEGER',
            'cms_categories' => 'id INTEGER PRIMARY KEY, collection_id INTEGER, parent_id INTEGER, sort_order INTEGER, is_active INTEGER',
            'cms_category_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, language_id INTEGER, name TEXT',
        ];
        foreach ($definitions as $table => $definition) {
            $this->readDb->query('CREATE TABLE ' . $table . ' (' . $definition . ')');
        }
    }
}
