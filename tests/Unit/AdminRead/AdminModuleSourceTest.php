<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Catalog\AdminCatalogCollectionItemListSource;
use App\AdminRead\Catalog\AdminCatalogTechniqueSource;
use App\AdminRead\Cms\AdminCmsCategorySource;
use App\AdminRead\Event\AdminEventListSource;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class AdminModuleSourceTest extends CIUnitTestCase
{
    public function testCmsCategorySourceReturnsOneBoundedProjection(): void
    {
        $db = $this->db();
        $this->drop($db, [
            'cms_category_translations', 'cms_categories', 'cms_collection_translations',
            'cms_collections', 'cms_languages',
        ]);
        $db->query('CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_collections (id INTEGER PRIMARY KEY, collection_key TEXT, collection_type TEXT, is_active INTEGER, sort_order INTEGER)');
        $db->query('CREATE TABLE cms_collection_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id INTEGER, language_id INTEGER, name TEXT)');
        $db->query('CREATE TABLE cms_categories (id INTEGER PRIMARY KEY, collection_id INTEGER, parent_id INTEGER, sort_order INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE cms_category_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, language_id INTEGER, slug TEXT, name TEXT, description TEXT, meta_title TEXT, meta_description TEXT)');

        try {
            $db->table('cms_languages')->insert(['id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español', 'is_default' => 1, 'is_active' => 1, 'sort_order' => 1]);
            $db->table('cms_collections')->insert(['id' => 2, 'collection_key' => 'news', 'collection_type' => 'editorial', 'is_active' => 1, 'sort_order' => 1]);
            $db->table('cms_categories')->insert(['id' => 3, 'collection_id' => 2, 'parent_id' => null, 'sort_order' => 1, 'is_active' => 1, 'created_at' => '2026-08-20', 'updated_at' => '2026-08-20']);
            $db->table('cms_category_translations')->insert(['category_id' => 3, 'language_id' => 1, 'slug' => 'arte', 'name' => 'Arte']);

            $source = new AdminCmsCategorySource($db, $this->cache());
            $result = $source->bootstrap(3, ['cms.categories.read', 'cms.collections.read', 'cms.languages.read']);

            $this->assertSame(3, $result['category']['id']);
            $this->assertSame('Arte', $result['category']['translations'][0]['name']);
            $this->assertSame('es', $result['languages'][0]['code']);
            $this->assertSame('news', $result['collections'][0]['collection_key']);
        } finally {
            $this->drop($db, [
                'cms_category_translations', 'cms_categories', 'cms_collection_translations',
                'cms_collections', 'cms_languages',
            ]);
            $db->close();
        }
    }

    public function testCatalogCollectionItemListSourceBoundsCategoryOptions(): void
    {
        $db = $this->db();
        $this->drop($db, ['categories']);
        $db->query('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, sort_order INTEGER, deleted_at TEXT)');

        try {
            $db->table('categories')->insert(['id' => 4, 'name' => 'Pintura', 'slug' => 'pintura', 'sort_order' => 1, 'deleted_at' => null]);
            $source = new AdminCatalogCollectionItemListSource($db, $this->cache());
            $result = $source->bootstrap(['catalog.collectionItem.read', 'catalog.category.read']);

            $this->assertSame(4, $result['categories'][0]['id']);
        } finally {
            $this->drop($db, ['categories']);
            $db->close();
        }
    }

    public function testEventListSourceReturnsOnlyActiveTypes(): void
    {
        $db = $this->db();
        $this->drop($db, ['event_types']);
        $db->query('CREATE TABLE event_types (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, sort_order INTEGER, is_active INTEGER, deleted_at TEXT)');

        try {
            $db->table('event_types')->insertBatch([
                ['id' => 1, 'slug' => 'talk', 'name' => 'Talk', 'sort_order' => 1, 'is_active' => 1, 'deleted_at' => null],
                ['id' => 2, 'slug' => 'hidden', 'name' => 'Hidden', 'sort_order' => 2, 'is_active' => 0, 'deleted_at' => null],
            ]);
            $source = new AdminEventListSource($db, $this->cache());
            $result = $source->bootstrap(['event.events.read', 'event.event-types.read']);

            $this->assertCount(1, $result['eventTypes']);
            $this->assertSame('talk', $result['eventTypes'][0]['slug']);
        } finally {
            $this->drop($db, ['event_types']);
            $db->close();
        }
    }

    public function testCatalogTechniqueSourceIncludesCatalogTranslationsAndCmsLanguages(): void
    {
        $db = $this->db();
        $this->drop($db, ['catalog_translations', 'techniques', 'cms_languages']);
        $db->query('CREATE TABLE techniques (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, summary TEXT, video_url TEXT, pdf_file_id INTEGER, sort_order INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $db->query('CREATE TABLE catalog_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, translatable_type TEXT, translatable_id INTEGER, locale TEXT, field TEXT, value TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, name TEXT, native_name TEXT, is_default INTEGER, is_active INTEGER, sort_order INTEGER)');

        try {
            $db->table('techniques')->insert(['id' => 5, 'name' => 'Grabado', 'slug' => 'grabado', 'summary' => 'Resumen', 'video_url' => null, 'pdf_file_id' => null, 'sort_order' => 1, 'created_at' => '2026-08-20', 'updated_at' => '2026-08-20', 'deleted_at' => null]);
            $db->table('catalog_translations')->insert(['translatable_type' => 'technique', 'translatable_id' => 5, 'locale' => 'es', 'field' => 'name', 'value' => 'Grabado', 'updated_at' => '2026-08-20']);
            $db->table('cms_languages')->insert(['id' => 1, 'code' => 'es', 'name' => 'Español', 'native_name' => 'Español', 'is_default' => 1, 'is_active' => 1, 'sort_order' => 1]);

            $source = new AdminCatalogTechniqueSource($db, $db, $this->cache());
            $result = $source->workspace(5, ['catalog.technique.read', 'cms.languages.read']);

            $this->assertSame(5, $result['technique']['id']);
            $this->assertSame('Grabado', $result['technique']['translations'][0]['name']);
            $this->assertSame('es', $result['languages'][0]['code']);
        } finally {
            $this->drop($db, ['catalog_translations', 'techniques', 'cms_languages']);
            $db->close();
        }
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests', false);
    }

    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('save');

        return $cache;
    }

    /** @param list<string> $tables */
    private function drop(BaseConnection $db, array $tables): void
    {
        foreach ($tables as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
