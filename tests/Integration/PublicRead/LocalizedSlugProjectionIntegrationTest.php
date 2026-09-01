<?php

declare(strict_types=1);

namespace Tests\Integration\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemReader;
use App\PublicRead\Event\PublicReadEventReader;
use App\PublicRead\Support\FileMetaResolverInterface;
use App\PublicRead\Support\MediaHydrator;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Tests\Support\PublicReadSlugFixture;

/**
 * Runs the localized-slug projection against MySQL 8 in the opt-in CI suite.
 *
 * The fixture is intentionally limited to the tables/columns exercised by
 * this contract. Domain migrations remain owned by their domain repositories;
 * the BFF never gains migrations or a write database.
 */
final class LocalizedSlugProjectionIntegrationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_PUBLIC_READ_INTEGRATION') !== '1') {
            self::markTestSkipped('Run composer test:integration to enable the MySQL public-read suite.');
        }
    }

    public function testEventDetailLoadsEveryConfiguredSlugWithTheDefaultProjectionOnMysql(): void
    {
        $db = $this->connect('event_readonly');
        try {
            PublicReadSlugFixture::createEventSchema($db);
            PublicReadSlugFixture::insertEvent($db);

            $result = (new PublicReadEventReader($db, $this->mediaHydrator()))
                ->show('fr', 'festival-fr', []);

            self::assertTrue($result->body['ok']);
            $actual = $result->body['data']['slugs'];
            ksort($actual);
            $expected = PublicReadSlugFixture::slugs('festival');
            ksort($expected);
            self::assertSame($expected, $actual);
        } finally {
            $this->dropEventSchema($db);
            $db->close();
        }
    }

    public function testCatalogDetailLoadsEveryConfiguredSlugWithTheDefaultProjectionOnMysql(): void
    {
        $db = $this->connect('catalog_readonly');
        try {
            PublicReadSlugFixture::createCatalogSchema($db);
            PublicReadSlugFixture::insertCatalog($db);

            $result = (new PublicReadCollectionItemReader($db, $this->mediaHydrator()))
                ->show('fr', 'piece-fr', []);

            self::assertTrue($result->body['ok']);
            $actual = $result->body['data']['slugs'];
            ksort($actual);
            $expected = PublicReadSlugFixture::slugs('piece');
            ksort($expected);
            self::assertSame($expected, $actual);
        } finally {
            $this->dropCatalogSchema($db);
            $db->close();
        }
    }

    /** @return BaseConnection<mixed, mixed> */
    private function connect(string $group): BaseConnection
    {
        if ($group === '') {
            throw new \InvalidArgumentException('A database group is required.');
        }

        /** @var BaseConnection<mixed, mixed> $db */
        $db = Database::connect($group, false);

        return $db;
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

    /** @param BaseConnection<mixed, mixed> $db */
    private function dropEventSchema(BaseConnection $db): void
    {
        foreach (['event_public_slugs', 'event_translations', 'venues', 'occurrences', 'events'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /** @param BaseConnection<mixed, mixed> $db */
    private function dropCatalogSchema(BaseConnection $db): void
    {
        foreach (['techniques', 'collection_item_technique', 'catalog_translations', 'catalog_public_slugs', 'collection_items'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
