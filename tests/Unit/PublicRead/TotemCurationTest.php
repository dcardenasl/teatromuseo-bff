<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemReader;
use App\PublicRead\Catalog\PublicReadCollectionItemRequestDTO;
use App\PublicRead\CatalogFacetReader;
use App\PublicRead\Support\FileMetaResolverInterface;
use App\PublicRead\Support\MediaHydrator;
use App\Support\PublicReadCallerContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use Tests\Support\PublicReadSlugFixture;

/**
 * Kiosk curation (`collection_items.show_in_totem`) must only gate the
 * totem caller — the public website keeps seeing every published item, and
 * the gate is resolved from the trusted caller context the auth filter set,
 * never from a client-supplied query parameter.
 */
final class TotemCurationTest extends CIUnitTestCase
{
    /** @var BaseConnection<mixed, mixed> */
    protected BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var BaseConnection<mixed, mixed> $db */
        $db = Database::connect('tests', false);
        $this->readDb = $db;
        PublicReadSlugFixture::createCatalogSchema($this->readDb);
    }

    protected function tearDown(): void
    {
        PublicReadCallerContext::flush();
        $this->readDb->close();
        parent::tearDown();
    }

    public function testWebCallerSeesItemsRegardlessOfShowInTotemFlag(): void
    {
        PublicReadSlugFixture::insertCatalog($this->readDb, showInTotem: 0);
        PublicReadCallerContext::set('web');

        $result = $this->reader()->index($this->request(), []);

        self::assertSame(1, $result->body['meta']['total']);
    }

    public function testTotemCallerOnlySeesItemsFlaggedForTheKiosk(): void
    {
        PublicReadSlugFixture::insertCatalog($this->readDb, showInTotem: 0);
        PublicReadCallerContext::set('totem');

        $result = $this->reader()->index($this->request(), []);

        self::assertSame(0, $result->body['meta']['total']);
    }

    public function testTotemCallerSeesItemsExplicitlyFlaggedForTheKiosk(): void
    {
        PublicReadSlugFixture::insertCatalog($this->readDb, showInTotem: 1);
        PublicReadCallerContext::set('totem');

        $result = $this->reader()->index($this->request(), []);

        self::assertSame(1, $result->body['meta']['total']);
    }

    public function testFacetCountsRespectTheSameCurationPredicate(): void
    {
        $this->readDb->query(
            'CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, icon TEXT, '
                . 'short_description TEXT, sort_order INTEGER, deleted_at TEXT)',
        );
        $this->readDb->table('categories')->insert(['id' => 1, 'name' => 'Títeres', 'slug' => 'titeres', 'sort_order' => 1]);
        PublicReadSlugFixture::insertCatalog($this->readDb, showInTotem: 0);
        $this->readDb->table('collection_items')->where('id', 1)->update(['category_id' => 1]);

        PublicReadCallerContext::set('web');
        $webCategories = (new CatalogFacetReader($this->readDb))->categories(withCounts: true);
        self::assertSame(1, $webCategories[0]['item_count']);

        PublicReadCallerContext::set('totem');
        $totemCategories = (new CatalogFacetReader($this->readDb))->categories(withCounts: true);
        self::assertSame(0, $totemCategories[0]['item_count']);
    }

    private function reader(): PublicReadCollectionItemReader
    {
        return new PublicReadCollectionItemReader($this->readDb, $this->mediaHydrator());
    }

    private function request(): PublicReadCollectionItemRequestDTO
    {
        /** @var PublicReadCollectionItemRequestDTO $dto */
        $dto = Services::requestDtoFactory()->make(
            PublicReadCollectionItemRequestDTO::class,
            ['locale' => 'es'],
        );

        return $dto;
    }

    private function mediaHydrator(): MediaHydrator
    {
        return new MediaHydrator(new class () implements FileMetaResolverInterface {
            public function resolveMany(array $fileIds, string $context = 'public'): array
            {
                unset($fileIds, $context);

                return [];
            }
        });
    }
}
