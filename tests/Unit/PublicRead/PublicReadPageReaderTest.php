<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Cms\BlockInstanceSerializer;
use App\PublicRead\Cms\FileUrlResolver;
use App\PublicRead\Cms\PublicReadPageReader;
use App\PublicRead\Support\FileMetaResolverInterface;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** Verifies the real CMS reader's set-based localized path resolution. */
final class PublicReadPageReaderTest extends CIUnitTestCase
{
    /** @var BaseConnection<mixed, mixed> */
    private BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BaseConnection<mixed, mixed> $db */
        $db = Database::connect('tests', false);
        $this->readDb = $db;
        $this->createSchema();
        $this->seedPages();
    }

    protected function tearDown(): void
    {
        $this->readDb->close();
        parent::tearDown();
    }

    public function testShowAnyPreservesCandidateOrderAndResolvesOneLocalizedPage(): void
    {
        $result = $this->reader()->showAny(
            locale: 'en',
            paths: ['cartelera', 'programming', 'events'],
            fields: ['title'],
        );

        self::assertTrue($result->body['ok']);
        self::assertSame(['title' => 'Programming'], $result->body['data']);
        self::assertSame([
            'paths' => ['cartelera', 'programming', 'events'],
            'preview' => false,
        ], $result->body['meta']['query']);
    }

    public function testShowKeepsTheSinglePathMetadataContract(): void
    {
        $result = $this->reader()->show('en', 'programming', ['title']);

        self::assertTrue($result->body['ok']);
        self::assertSame(['title' => 'Programming'], $result->body['data']);
        self::assertSame([
            'path' => 'programming',
            'preview' => false,
        ], $result->body['meta']['query']);
    }

    private function reader(): PublicReadPageReader
    {
        $files = new class () implements FileMetaResolverInterface {
            public function resolveMany(array $fileIds, string $context = 'public'): array
            {
                unset($fileIds, $context);

                return [];
            }
        };
        $fileUrlResolver = new FileUrlResolver($files, 'https://files.example.test');

        return new PublicReadPageReader(
            db: $this->readDb,
            blockSerializer: new BlockInstanceSerializer($this->readDb, $fileUrlResolver),
            fileUrlResolver: $fileUrlResolver,
        );
    }

    private function createSchema(): void
    {
        $this->readDb->query(
            'CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, is_default INTEGER, is_active INTEGER)',
        );
        $this->readDb->query(<<<'SQL'
            CREATE TABLE cms_pages (
                id INTEGER PRIMARY KEY,
                parent_id INTEGER,
                collection_id INTEGER,
                page_type TEXT,
                published_at TEXT,
                status TEXT,
                scheduled_at TEXT,
                sort_order INTEGER,
                sitemap_priority REAL,
                sitemap_changefreq TEXT,
                is_in_sitemap INTEGER,
                updated_at TEXT,
                deleted_at TEXT
            )
        SQL);
        $this->readDb->query(<<<'SQL'
            CREATE TABLE cms_page_translations (
                id INTEGER PRIMARY KEY,
                page_id INTEGER,
                language_id INTEGER,
                slug TEXT,
                title TEXT,
                excerpt TEXT,
                meta_title TEXT,
                meta_description TEXT,
                og_image_file_id INTEGER,
                og_image_url TEXT,
                og_type TEXT,
                canonical_url TEXT,
                robots TEXT,
                schema_data TEXT,
                updated_at TEXT
            )
        SQL);
    }

    private function seedPages(): void
    {
        $this->readDb->table('cms_languages')->insertBatch([
            ['id' => 1, 'code' => 'es', 'is_default' => 1, 'is_active' => 1],
            ['id' => 2, 'code' => 'en', 'is_default' => 0, 'is_active' => 1],
        ]);
        $this->readDb->table('cms_pages')->insert([
            'id' => 10,
            'parent_id' => null,
            'collection_id' => null,
            'page_type' => 'events',
            'published_at' => '2026-01-01 00:00:00',
            'status' => 'published',
            'scheduled_at' => null,
            'sort_order' => 1,
            'sitemap_priority' => 0.5,
            'sitemap_changefreq' => 'daily',
            'is_in_sitemap' => 1,
            'updated_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ]);
        $this->readDb->table('cms_page_translations')->insertBatch([
            [
                'id' => 20,
                'page_id' => 10,
                'language_id' => 1,
                'slug' => 'cartelera',
                'title' => 'Cartelera',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            [
                'id' => 21,
                'page_id' => 10,
                'language_id' => 2,
                'slug' => 'programming',
                'title' => 'Programming',
                'updated_at' => '2026-01-01 00:00:00',
            ],
        ]);
    }
}
