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
}
