<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Catalog\CatalogDashboardSource;
use App\AdminRead\Cms\CmsDashboardSource;
use App\AdminRead\Event\EventDashboardSource;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;

final class DashboardSourcesTest extends CIUnitTestCase
{
    private BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readDb = Database::connect('tests', false);
    }

    protected function tearDown(): void
    {
        foreach ([
            'cms_page_translations', 'cms_entry_translations', 'cms_form_submissions',
            'cms_pages', 'cms_entries', 'cms_collections', 'cms_menus', 'cms_categories',
            'cms_tags', 'cms_forms', 'collection_items', 'categories', 'techniques',
            'event_references', 'tickets', 'bookings', 'ticket_types', 'occurrences',
            'venues', 'event_types', 'events',
        ] as $table) {
            $this->readDb->query('DROP TABLE IF EXISTS ' . $table);
        }
        $this->readDb->close();
        parent::tearDown();
    }

    public function testCmsProjectionHonoursPermissionsAndSoftDeletes(): void
    {
        $this->createCmsSchema();
        $this->readDb->table('cms_pages')->insert(['id' => 1, 'updated_at' => '2026-08-16 12:00:00', 'deleted_at' => null]);
        $this->readDb->table('cms_pages')->insert(['id' => 2, 'updated_at' => '2026-08-16 11:00:00', 'deleted_at' => '2026-08-16 11:30:00']);
        $this->readDb->table('cms_page_translations')->insert(['page_id' => 1, 'language_id' => 1, 'title' => 'Page', 'slug' => 'page']);
        $this->readDb->table('cms_collections')->insert(['id' => 1, 'deleted_at' => null]);
        $this->readDb->table('cms_form_submissions')->insert(['status' => 'new']);

        $result = (new CmsDashboardSource($this->readDb))->read([
            'cms.pages.read',
            'cms.collections.read',
            'cms.submissions.read',
        ]);

        $this->assertSame(1, $result['sections']['counts']['pages']);
        $this->assertSame(1, $result['sections']['counts']['collections']);
        $this->assertSame(['new' => 1, 'read' => 0, 'replied' => 0, 'spam' => 0, 'archived' => 0], $result['sections']['submissions']);
        $this->assertSame('Page', $result['sections']['recent_activity'][0]['translations'][0]['title']);
    }

    public function testCatalogProjectionReturnsOnlyVisibleRows(): void
    {
        $this->createCatalogSchema();
        $this->readDb->table('collection_items')->insert(['id' => 1, 'name' => 'Piece', 'updated_at' => '2026-08-16 12:00:00', 'deleted_at' => null]);
        $this->readDb->table('collection_items')->insert(['id' => 2, 'name' => 'Deleted', 'updated_at' => '2026-08-16 11:00:00', 'deleted_at' => '2026-08-16 11:30:00']);
        $this->readDb->table('categories')->insert(['id' => 1, 'name' => 'Category', 'slug' => 'category', 'updated_at' => '2026-08-16 12:00:00', 'deleted_at' => null]);
        $this->readDb->table('techniques')->insert(['id' => 1, 'name' => 'Technique', 'slug' => 'technique', 'updated_at' => '2026-08-16 12:00:00', 'deleted_at' => null]);

        $result = (new CatalogDashboardSource($this->readDb))->read([
            'catalog.collectionItem.read',
            'catalog.category.read',
            'catalog.technique.read',
        ]);

        $this->assertSame([
            'collection_items' => 1,
            'categories' => 1,
            'techniques' => 1,
        ], $result['sections']['counts']);
        $this->assertCount(3, $result['sections']['recent_activity']);
    }

    public function testEventProjectionUsesTheOwnedEventReferenceShape(): void
    {
        $this->createEventSchema();
        foreach ([
            'events' => ['title' => 'Event'],
            'event_types' => ['name' => 'Type', 'slug' => 'type'],
            'venues' => ['name' => 'Venue', 'slug' => 'venue'],
            'occurrences' => ['status' => 'published'],
            'event_references' => [],
            'ticket_types' => ['name' => 'Ticket'],
            'bookings' => ['status' => 'paid'],
            'tickets' => ['holder_name' => 'Visitor', 'status' => 'valid'],
        ] as $table => $values) {
            $this->readDb->table($table)->insert(array_merge(['id' => 1, 'updated_at' => '2026-08-16 12:00:00', 'deleted_at' => null], $values));
        }

        $result = (new EventDashboardSource($this->readDb))->read([
            'event.events.read',
            'event.event-types.read',
            'event.venues.read',
            'event.occurrences.read',
            'event.event-references.read',
            'event.ticket-types.read',
            'event.bookings.read',
            'event.tickets.read',
        ]);

        $this->assertCount(8, $result['sections']['counts']);
        $this->assertSame(1, $result['sections']['counts']['event_references']);
    }

    public function testFailedAdminQueryIsNotReportedAsZero(): void
    {
        $this->expectException(RuntimeException::class);
        (new CatalogDashboardSource($this->readDb))->read(['catalog.collectionItem.read']);
    }

    private function createCmsSchema(): void
    {
        foreach ([
            'cms_pages' => 'id INTEGER PRIMARY KEY, updated_at TEXT, deleted_at TEXT',
            'cms_entries' => 'id INTEGER PRIMARY KEY, updated_at TEXT, deleted_at TEXT',
            'cms_collections' => 'id INTEGER PRIMARY KEY, deleted_at TEXT',
            'cms_menus' => 'id INTEGER PRIMARY KEY, deleted_at TEXT',
            'cms_categories' => 'id INTEGER PRIMARY KEY',
            'cms_tags' => 'id INTEGER PRIMARY KEY',
            'cms_forms' => 'id INTEGER PRIMARY KEY',
            'cms_form_submissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT',
            'cms_page_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, language_id INTEGER, title TEXT, slug TEXT',
            'cms_entry_translations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, language_id INTEGER, title TEXT, slug TEXT',
        ] as $table => $definition) {
            $this->readDb->query('CREATE TABLE ' . $table . ' (' . $definition . ')');
        }
    }

    private function createCatalogSchema(): void
    {
        foreach ([
            'collection_items' => 'id INTEGER PRIMARY KEY, name TEXT, updated_at TEXT, deleted_at TEXT',
            'categories' => 'id INTEGER PRIMARY KEY, name TEXT, slug TEXT, updated_at TEXT, deleted_at TEXT',
            'techniques' => 'id INTEGER PRIMARY KEY, name TEXT, slug TEXT, updated_at TEXT, deleted_at TEXT',
        ] as $table => $definition) {
            $this->readDb->query('CREATE TABLE ' . $table . ' (' . $definition . ')');
        }
    }

    private function createEventSchema(): void
    {
        foreach ([
            'events' => 'id INTEGER PRIMARY KEY, title TEXT, updated_at TEXT, deleted_at TEXT',
            'event_types' => 'id INTEGER PRIMARY KEY, name TEXT, slug TEXT, updated_at TEXT, deleted_at TEXT',
            'venues' => 'id INTEGER PRIMARY KEY, name TEXT, slug TEXT, updated_at TEXT, deleted_at TEXT',
            'occurrences' => 'id INTEGER PRIMARY KEY, status TEXT, updated_at TEXT, deleted_at TEXT',
            'event_references' => 'id INTEGER PRIMARY KEY, updated_at TEXT, deleted_at TEXT',
            'ticket_types' => 'id INTEGER PRIMARY KEY, name TEXT, updated_at TEXT, deleted_at TEXT',
            'bookings' => 'id INTEGER PRIMARY KEY, status TEXT, updated_at TEXT, deleted_at TEXT',
            'tickets' => 'id INTEGER PRIMARY KEY, holder_name TEXT, status TEXT, updated_at TEXT, deleted_at TEXT',
        ] as $table => $definition) {
            $this->readDb->query('CREATE TABLE ' . $table . ' (' . $definition . ')');
        }
    }
}
