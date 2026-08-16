<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemReader;
use App\PublicRead\Event\PublicReadEventReader;
use App\PublicRead\Support\FileMetaResolverInterface;
use App\PublicRead\Support\MediaHydrator;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Tests\Support\PublicReadSlugFixture;

/**
 * Exercises the real SQL projection used by page-resolve detail reads.
 *
 * The BFF has no owned database or migrations. This test uses the framework's
 * existing in-memory SQLite compatibility connection as a disposable read
 * fixture, so it verifies the conditional locale predicate without adding a
 * database dependency to the application or its normal test bootstrap.
 */
final class LocalizedSlugProjectionTest extends CIUnitTestCase
{
    /** @var BaseConnection<mixed, mixed> */
    protected BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var BaseConnection<mixed, mixed> $db */
        $db = Database::connect('tests', false);
        $this->readDb = $db;
    }

    protected function tearDown(): void
    {
        $this->readDb->close();
        parent::tearDown();
    }

    public function testEventDetailLoadsEveryConfiguredSlugWithTheDefaultProjection(): void
    {
        PublicReadSlugFixture::createEventSchema($this->readDb);
        PublicReadSlugFixture::insertEvent($this->readDb);

        $reader = new PublicReadEventReader($this->readDb, $this->mediaHydrator());
        $result = $reader->show('fr', 'festival-fr', []);

        self::assertTrue($result->body['ok']);
        $actual = $result->body['data']['slugs'];
        ksort($actual);
        $expected = PublicReadSlugFixture::slugs('festival');
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    public function testCatalogDetailLoadsEveryConfiguredSlugWithTheDefaultProjection(): void
    {
        PublicReadSlugFixture::createCatalogSchema($this->readDb);
        PublicReadSlugFixture::insertCatalog($this->readDb);

        $reader = new PublicReadCollectionItemReader($this->readDb, $this->mediaHydrator());
        $result = $reader->show('fr', 'piece-fr', []);

        self::assertTrue($result->body['ok']);
        $actual = $result->body['data']['slugs'];
        ksort($actual);
        $expected = PublicReadSlugFixture::slugs('piece');
        ksort($expected);
        self::assertSame($expected, $actual);
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
