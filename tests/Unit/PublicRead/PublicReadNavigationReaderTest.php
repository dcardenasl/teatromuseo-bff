<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Cms\PublicReadNavigationReader;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class PublicReadNavigationReaderTest extends CIUnitTestCase
{
    /** @var BaseConnection<mixed, mixed> */
    protected BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BaseConnection<mixed, mixed> $db */
        $db = Database::connect('tests', false);
        $this->readDb = $db;
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->readDb->close();
        parent::tearDown();
    }

    public function testMenuKeyExposesNavigationRegardlessOfLocation(): void
    {
        $this->readDb->table('cms_languages')->insert([
            'id' => 1,
            'code' => 'es',
            'is_default' => 1,
            'is_active' => 1,
        ]);
        $this->readDb->table('cms_menus')->insert([
            'id' => 1,
            'menu_key' => 'legal',
            'location' => 'editor_defined_legal_slot',
            'updated_at' => '2026-08-24 12:00:00',
            'is_active' => 1,
            'deleted_at' => null,
        ]);
        $this->readDb->table('cms_menu_translations')->insert([
            'menu_id' => 1,
            'language_id' => 1,
            'name' => 'Legal',
        ]);
        $this->readDb->table('cms_pages')->insert([
            'id' => 19,
            'page_type' => 'generic',
            'status' => 'published',
            'deleted_at' => null,
            'published_at' => null,
            'scheduled_at' => null,
        ]);
        $this->readDb->table('cms_page_translations')->insert([
            'page_id' => 19,
            'language_id' => 1,
            'slug' => 'aviso-legal',
        ]);
        $this->readDb->table('cms_menu_items')->insert([
            'id' => 1,
            'menu_id' => 1,
            'parent_id' => null,
            'link_type' => 'page',
            'page_id' => 19,
            'entry_id' => null,
            'collection_id' => null,
            'link_target' => '_self',
            'icon' => null,
            'css_class' => null,
            'sort_order' => 1,
            'updated_at' => '2026-08-24 12:00:00',
            'is_active' => 1,
        ]);
        $this->readDb->table('cms_menu_item_translations')->insert([
            'menu_item_id' => 1,
            'language_id' => 1,
            'label' => 'Aviso Legal',
            'custom_url' => null,
        ]);

        $result = (new PublicReadNavigationReader($this->readDb))->show('es');
        $legal = $result->body['data']['legal'];

        self::assertIsArray($legal);
        self::assertSame('legal', $legal['menu_key']);
        self::assertSame('editor_defined_legal_slot', $legal['location']);
        self::assertSame('Aviso Legal', $legal['items'][0]['label']);
        self::assertSame('aviso-legal', $legal['items'][0]['navigation']['slug']);
    }

    private function createSchema(): void
    {
        $tables = [
            'cms_languages' => 'id INTEGER PRIMARY KEY, code TEXT, is_default INTEGER, is_active INTEGER',
            'cms_menus' => 'id INTEGER PRIMARY KEY, menu_key TEXT, location TEXT, updated_at TEXT, is_active INTEGER, deleted_at TEXT',
            'cms_menu_translations' => 'menu_id INTEGER, language_id INTEGER, name TEXT',
            'cms_menu_items' => 'id INTEGER PRIMARY KEY, menu_id INTEGER, parent_id INTEGER, link_type TEXT, page_id INTEGER, entry_id INTEGER, collection_id INTEGER, link_target TEXT, icon TEXT, css_class TEXT, sort_order INTEGER, updated_at TEXT, is_active INTEGER',
            'cms_menu_item_translations' => 'menu_item_id INTEGER, language_id INTEGER, label TEXT, custom_url TEXT',
            'cms_pages' => 'id INTEGER PRIMARY KEY, page_type TEXT, status TEXT, deleted_at TEXT, published_at TEXT, scheduled_at TEXT',
            'cms_page_translations' => 'page_id INTEGER, language_id INTEGER, slug TEXT',
            'cms_entries' => 'id INTEGER PRIMARY KEY, collection_id INTEGER, workflow_status TEXT, deleted_at TEXT, published_at TEXT, scheduled_at TEXT',
            'cms_collections' => 'id INTEGER PRIMARY KEY, is_active INTEGER',
        ];

        foreach ($tables as $table => $columns) {
            $this->readDb->query(sprintf('CREATE TABLE %s (%s)', $table, $columns));
        }
    }
}
