<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Cms\AdminCmsBootstrapSource;
use App\Libraries\Domain\DomainClient;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;

final class CmsAdminBootstrapSourceTest extends CIUnitTestCase
{
    public function testRequiresTheBaseEntryPermissionsBeforeReading(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('get');
        $client = $this->createMock(DomainClient::class);

        $this->expectException(AuthorizationException::class);
        (new AdminCmsBootstrapSource($client, $cache))->entryFormOptions(null, ['cms.entries.read'], 'token');
    }

    public function testComposesEntryOptionsAndScopesTheShortCache(): void
    {
        $permissions = [
            'cms.entries.read',
            'cms.languages.read',
            'cms.collections.read',
            'cms.categories.read',
            'cms.tags.read',
        ];
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with($this->stringStartsWith('admin_cms_bootstrap_entry-form-options_7_'))
            ->willReturn(null);
        $cache->expects($this->once())
            ->method('save')
            ->with($this->stringStartsWith('admin_cms_bootstrap_entry-form-options_7_'), $this->isType('array'), 30);

        $client = $this->getMockBuilder(DomainClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $client->expects($this->exactly(4))
            ->method('get')
            ->willReturn(['data' => []]);

        $result = (new AdminCmsBootstrapSource($client, $cache))->entryFormOptions(7, $permissions, 'token');

        $this->assertSame(['languages', 'collections', 'categories', 'tags'], array_keys($result));
    }

    public function testReturnsCachedPageOptionsWithoutCallingTheDomain(): void
    {
        $cached = ['languages' => [], 'pages' => [], 'collections' => []];
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with($this->stringStartsWith('admin_cms_bootstrap_page-form-options_base_'))
            ->willReturn($cached);
        $cache->expects($this->never())->method('save');
        $client = $this->createMock(DomainClient::class);
        $client->expects($this->never())->method('get');

        $result = (new AdminCmsBootstrapSource($client, $cache))->pageFormOptions(null, [
            'cms.pages.read',
            'cms.languages.read',
            'cms.collections.read',
        ], 'token');

        $this->assertSame($cached, $result);
    }

    public function testPageOptionsUseOneSqlProjectionWhenTheReadDatabaseIsConfigured(): void
    {
        $db = Database::connect('tests', false);
        foreach (['cms_collection_translations', 'cms_collections', 'cms_page_translations', 'cms_pages', 'cms_languages'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }

        $db->query('CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER, fallback_language_id INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_pages (id INTEGER PRIMARY KEY, parent_id INTEGER, collection_id INTEGER, page_type TEXT, status TEXT, sort_order INTEGER, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE cms_page_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, language_id INTEGER, slug TEXT, title TEXT)');
        $db->query('CREATE TABLE cms_collections (id INTEGER PRIMARY KEY, collection_key TEXT, collection_type TEXT, is_active INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_collection_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id INTEGER, language_id INTEGER, slug TEXT, name TEXT)');

        try {
            $db->table('cms_languages')->insert([
                'id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español',
                'is_default' => 1, 'is_active' => 1, 'sort_order' => 1,
            ]);
            $db->table('cms_pages')->insert([
                'id' => 17, 'page_type' => 'about', 'status' => 'published', 'sort_order' => 1,
                'created_at' => '2026-08-17 10:00:00', 'updated_at' => '2026-08-17 10:00:00',
            ]);
            $db->table('cms_page_translations')->insert([
                'page_id' => 17, 'language_id' => 1, 'slug' => 'quienes-somos', 'title' => 'Quiénes somos',
            ]);
            $db->table('cms_collections')->insert([
                'id' => 3, 'collection_key' => 'news', 'collection_type' => 'editorial', 'is_active' => 1, 'sort_order' => 1,
            ]);
            $db->table('cms_collection_translations')->insert([
                'collection_id' => 3, 'language_id' => 1, 'slug' => 'noticias', 'name' => 'Noticias',
            ]);

            $cache = $this->createMock(CacheInterface::class);
            $cache->expects($this->once())->method('get')->willReturn(null);
            $cache->expects($this->once())->method('save');
            $client = $this->createMock(DomainClient::class);
            $client->expects($this->never())->method('get');

            $result = (new AdminCmsBootstrapSource($client, $cache, $db))->pageFormOptions(null, [
                'cms.pages.read',
                'cms.languages.read',
                'cms.collections.read',
            ], 'token');

            $this->assertSame('Quiénes somos', $result['pages'][0]['translations'][0]['title']);
            $this->assertSame('Noticias', $result['collections'][0]['name']);
            $this->assertSame('es', $result['languages'][0]['code']);
        } finally {
            foreach (['cms_collection_translations', 'cms_collections', 'cms_page_translations', 'cms_pages', 'cms_languages'] as $table) {
                $db->query('DROP TABLE IF EXISTS ' . $table);
            }
            $db->close();
        }
    }

    public function testEntryOptionsUseOneSqlProjectionForAllVisibleCatalogs(): void
    {
        $db = Database::connect('tests', false);
        foreach ([
            'cms_tag_translations', 'cms_tags', 'cms_category_translations', 'cms_categories',
            'cms_collection_translations', 'cms_collections', 'cms_languages',
        ] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }

        $db->query('CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER, fallback_language_id INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_collections (id INTEGER PRIMARY KEY, collection_key TEXT, collection_type TEXT, is_active INTEGER, enables_categories INTEGER, enables_tags INTEGER, block_template TEXT, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_collection_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id INTEGER, language_id INTEGER, slug TEXT, name TEXT)');
        $db->query('CREATE TABLE cms_categories (id INTEGER PRIMARY KEY, collection_id INTEGER, parent_id INTEGER, sort_order INTEGER, is_active INTEGER)');
        $db->query('CREATE TABLE cms_category_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, language_id INTEGER, slug TEXT, name TEXT)');
        $db->query('CREATE TABLE cms_tags (id INTEGER PRIMARY KEY, is_active INTEGER)');
        $db->query('CREATE TABLE cms_tag_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_id INTEGER, language_id INTEGER, slug TEXT, name TEXT)');

        try {
            $db->table('cms_languages')->insert(['id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español', 'is_default' => 1, 'is_active' => 1, 'sort_order' => 1]);
            $db->table('cms_collections')->insert(['id' => 3, 'collection_key' => 'news', 'collection_type' => 'editorial', 'is_active' => 1, 'enables_categories' => 1, 'enables_tags' => 1, 'sort_order' => 1]);
            $db->table('cms_categories')->insert(['id' => 4, 'collection_id' => 3, 'sort_order' => 1, 'is_active' => 1]);
            $db->table('cms_category_translations')->insert(['category_id' => 4, 'language_id' => 1, 'slug' => 'arte', 'name' => 'Arte']);
            $db->table('cms_tags')->insert(['id' => 5, 'is_active' => 1]);
            $db->table('cms_tag_translations')->insert(['tag_id' => 5, 'language_id' => 1, 'slug' => 'destacado', 'name' => 'Destacado']);

            $cache = $this->createMock(CacheInterface::class);
            $cache->expects($this->once())->method('get')->willReturn(null);
            $cache->expects($this->once())->method('save');
            $client = $this->createMock(DomainClient::class);
            $client->expects($this->never())->method('get');

            $result = (new AdminCmsBootstrapSource($client, $cache, $db))->entryFormOptions(null, [
                'cms.entries.read', 'cms.languages.read', 'cms.collections.read',
                'cms.categories.read', 'cms.tags.read',
            ], 'token');

            $this->assertSame('news', $result['collections'][0]['collection_key']);
            $this->assertSame('Arte', $result['categories'][0]['name']);
            $this->assertSame('Destacado', $result['tags'][0]['name']);
            $this->assertSame('es', $result['languages'][0]['code']);
        } finally {
            foreach ([
                'cms_tag_translations', 'cms_tags', 'cms_category_translations', 'cms_categories',
                'cms_collection_translations', 'cms_collections', 'cms_languages',
            ] as $table) {
                $db->query('DROP TABLE IF EXISTS ' . $table);
            }
            $db->close();
        }
    }

    public function testMenuEditorUsesOneSqlProjectionAndHydratesTheSelectedItem(): void
    {
        $db = Database::connect('tests', false);
        foreach ([
            'cms_menu_item_translations', 'cms_menu_items', 'cms_menu_translations', 'cms_menus',
            'cms_page_translations', 'cms_pages', 'cms_entry_translations', 'cms_entries',
            'cms_collection_translations', 'cms_collections', 'cms_languages',
        ] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }
        $db->query('CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_menus (id INTEGER PRIMARY KEY, menu_key TEXT, location TEXT, is_active INTEGER)');
        $db->query('CREATE TABLE cms_menu_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER, language_id INTEGER, name TEXT)');
        $db->query('CREATE TABLE cms_menu_items (id INTEGER PRIMARY KEY, menu_id INTEGER, parent_id INTEGER, link_type TEXT, page_id INTEGER, entry_id INTEGER, collection_id INTEGER, link_target TEXT, icon TEXT, css_class TEXT, sort_order INTEGER, is_active INTEGER)');
        $db->query('CREATE TABLE cms_menu_item_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_item_id INTEGER, language_id INTEGER, label TEXT, custom_url TEXT)');
        $db->query('CREATE TABLE cms_pages (id INTEGER PRIMARY KEY, parent_id INTEGER, page_type TEXT, status TEXT, sort_order INTEGER, deleted_at TEXT)');
        $db->query('CREATE TABLE cms_page_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, language_id INTEGER, slug TEXT, title TEXT)');
        $db->query('CREATE TABLE cms_entries (id INTEGER PRIMARY KEY, collection_id INTEGER, workflow_status TEXT, sort_order INTEGER, deleted_at TEXT)');
        $db->query('CREATE TABLE cms_entry_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, language_id INTEGER, slug TEXT, title TEXT)');
        $db->query('CREATE TABLE cms_collections (id INTEGER PRIMARY KEY, collection_key TEXT, collection_type TEXT, is_active INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_collection_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id INTEGER, language_id INTEGER, slug TEXT, name TEXT)');

        try {
            $db->table('cms_languages')->insert(['id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español', 'is_default' => 1, 'is_active' => 1, 'sort_order' => 1]);
            $db->table('cms_menus')->insert(['id' => 8, 'menu_key' => 'header', 'location' => 'header', 'is_active' => 1]);
            $db->table('cms_menu_items')->insert(['id' => 12, 'menu_id' => 8, 'link_type' => 'custom_url', 'link_target' => '_self', 'sort_order' => 1, 'is_active' => 1]);
            $db->table('cms_menu_item_translations')->insert(['menu_item_id' => 12, 'language_id' => 1, 'label' => 'Inicio', 'custom_url' => '/']);

            $cache = $this->createMock(CacheInterface::class);
            $cache->expects($this->once())->method('get')->willReturn(null);
            $cache->expects($this->once())->method('save');
            $client = $this->createMock(DomainClient::class);
            $client->expects($this->never())->method('get');

            $result = (new AdminCmsBootstrapSource($client, $cache, $db))->menuEditorBootstrap(8, 12, [
                'cms.menus.read', 'cms.languages.read', 'cms.pages.read',
                'cms.entries.read', 'cms.collections.read',
            ], 'token');

            $this->assertSame('header', $result['menu']['menu_key']);
            $this->assertSame('Inicio', $result['item']['translations'][0]['label']);
            $this->assertSame('es', $result['languages'][0]['code']);
        } finally {
            foreach ([
                'cms_menu_item_translations', 'cms_menu_items', 'cms_menu_translations', 'cms_menus',
                'cms_page_translations', 'cms_pages', 'cms_entry_translations', 'cms_entries',
                'cms_collection_translations', 'cms_collections', 'cms_languages',
            ] as $table) {
                $db->query('DROP TABLE IF EXISTS ' . $table);
            }
            $db->close();
        }
    }

    public function testSiteIdentityUsesOneSqlProjectionAndHydratesTranslations(): void
    {
        $db = Database::connect('tests', false);
        foreach (['cms_setting_translations', 'cms_settings', 'cms_languages'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }
        $db->query('CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER, fallback_language_id INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_settings (id INTEGER PRIMARY KEY, setting_key TEXT, setting_value TEXT, setting_meta TEXT, setting_type TEXT, input_type TEXT, options_json TEXT, is_required INTEGER, is_readonly INTEGER, setting_group TEXT, is_translatable INTEGER, sort_order INTEGER, description TEXT, is_public INTEGER, is_active INTEGER)');
        $db->query('CREATE TABLE cms_setting_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_id INTEGER, language_id INTEGER, setting_value TEXT, label TEXT, placeholder TEXT, help_text TEXT)');

        try {
            $db->table('cms_languages')->insert(['id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español', 'is_default' => 1, 'is_active' => 1, 'sort_order' => 1]);
            $db->table('cms_settings')->insert([
                'id' => 21, 'setting_key' => 'site_name', 'setting_value' => 'Teatro Museo',
                'setting_type' => 'string', 'input_type' => 'text', 'setting_group' => 'identity',
                'is_translatable' => 1, 'is_public' => 1, 'is_active' => 1, 'sort_order' => 1,
            ]);
            $db->table('cms_setting_translations')->insert([
                'setting_id' => 21, 'language_id' => 1, 'setting_value' => 'Teatro Museo', 'label' => 'Nombre',
            ]);

            $cache = $this->createMock(CacheInterface::class);
            $cache->expects($this->once())->method('get')->willReturn(null);
            $cache->expects($this->once())->method('save');
            $client = $this->createMock(DomainClient::class);
            $client->expects($this->never())->method('get');

            $result = (new AdminCmsBootstrapSource($client, $cache, $db))->siteIdentityBootstrap([
                'cms.settings.read', 'cms.languages.read',
            ], 'token');

            $this->assertSame('site_name', $result['settings'][0]['setting_key']);
            $this->assertSame('Nombre', $result['settings'][0]['translations'][0]['label']);
            $this->assertSame('es', $result['languages'][0]['code']);
        } finally {
            foreach (['cms_setting_translations', 'cms_settings', 'cms_languages'] as $table) {
                $db->query('DROP TABLE IF EXISTS ' . $table);
            }
            $db->close();
        }
    }
}
