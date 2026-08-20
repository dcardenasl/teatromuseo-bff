<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Page\BlockTreeResolver;
use App\PublicRead\Page\BlockTreeSourceInterface;
use dcardenasl\Ci4ApiCore\Support\ApiResult;
use PHPUnit\Framework\TestCase;

final class BlockTreeResolverTest extends TestCase
{
    public function testResolvesNestedBlocksWithIndependentResultsFacetsFormsAndScopes(): void
    {
        $source = new FakeBlockTreeSource();
        $resolver = new BlockTreeResolver($source);
        $result = $resolver->resolve([
            [
                'block_key' => 'collection_grid',
                'block_config' => ['source_type' => 'cms_collection', 'collection_key' => 'news'],
            ],
            [
                'block_key' => 'layout_columns',
                'children' => [[
                    'block_key' => 'event_item_feature',
                    'block_config' => ['event_slug' => 'opening-night'],
                ]],
            ],
            [
                'block_key' => 'collection_listing',
                'block_config' => ['collection_key' => 'catalog', 'show_categories' => true],
            ],
            [
                'block_key' => 'form_embed',
                'block_config' => ['form_key' => 'contact'],
            ],
        ], 'es', ['category' => 'painting', 'page' => '2', 'limit' => '5']);

        self::assertTrue($result['block_prefetch_complete']);
        self::assertSame(['0', '1.0', '2'], array_map('strval', array_keys($result['block_prefetch'])));
        self::assertSame([['id' => 1]], $result['block_prefetch'][0]['data']);
        self::assertSame('fresh', $result['block_prefetch'][0]['instance']['source']);
        self::assertSame([['id' => 9, 'slug' => 'opening-night']], $result['block_prefetch']['1.0']['data']);
        self::assertSame(2, $result['block_prefetch'][2]['instance']['page']);
        self::assertSame(5, $result['block_prefetch'][2]['instance']['limit']);
        self::assertSame([['id' => 7, 'slug' => 'painting']], $result['block_prefetch'][2]['facets']['categories']);
        self::assertSame(['form_key' => 'contact'], $result['form_definitions']['contact']);
        self::assertSame([
            'collections', 'entries', 'taxonomies', 'events', 'event_types',
            'collection_items', 'categories', 'forms',
        ], $result['cacheScopes']);
        self::assertSame(7, $source->lastCatalogQuery['category_id']);
    }

    public function testAFailedDetailSourceDoesNotInvalidateAnotherBlock(): void
    {
        $source = new FakeBlockTreeSource();
        $source->failEvents = true;
        $result = (new BlockTreeResolver($source))->resolve([
            [
                'block_key' => 'event_item_failure',
                'block_config' => ['event_slug' => 'missing'],
            ],
            [
                'block_key' => 'collection_grid',
                'block_config' => ['collection_key' => 'news'],
            ],
        ]);

        self::assertFalse($result['block_prefetch'][0]['ok']);
        self::assertSame(503, $result['block_prefetch'][0]['status']);
        self::assertSame('unavailable', $result['block_prefetch'][0]['instance']['source']);
        self::assertTrue($result['block_prefetch'][1]['ok']);
        self::assertSame([['id' => 1]], $result['block_prefetch'][1]['data']);
    }

    public function testSeededDetailDoesNotReadTheSource(): void
    {
        $source = new FakeBlockTreeSource();
        $result = (new BlockTreeResolver($source))->resolve([
            [
                'block_key' => 'catalog_item_seeded',
                'block_config' => ['collection_item_slug' => 'seeded'],
            ],
        ], 'es', [], [
            'catalog_items' => [['id' => 4, 'slug' => 'seeded', 'name' => 'Seeded item']],
        ]);

        self::assertSame([['id' => 4, 'slug' => 'seeded', 'name' => 'Seeded item']], $result['block_prefetch'][0]['data']);
        self::assertSame(0, $source->catalogItemCalls);
    }

    public function testPreviewIsForwardedToCmsCollectionBlocks(): void
    {
        $source = new FakeBlockTreeSource();

        (new BlockTreeResolver($source))->resolve([
            [
                'block_key' => 'collection_grid',
                'block_config' => ['source_type' => 'cms_collection', 'collection_key' => 'news'],
            ],
        ], 'es', [], [], true);

        self::assertTrue($source->cmsPreview);
    }

    public function testIdenticalBlockPlansShareListDetailAndFacetReads(): void
    {
        $source = new FakeBlockTreeSource();

        (new BlockTreeResolver($source))->resolve([
            [
                'block_key' => 'collection_listing',
                'block_config' => [
                    'source_type' => 'cms_collection',
                    'collection_key' => 'news',
                    'show_categories' => true,
                ],
            ],
            [
                'block_key' => 'collection_listing',
                'block_config' => [
                    'source_type' => 'cms_collection',
                    'collection_key' => 'news',
                    'show_categories' => true,
                ],
            ],
            [
                'block_key' => 'event_item_feature',
                'block_config' => ['event_slug' => 'opening-night'],
            ],
            [
                'block_key' => 'event_item_feature',
                'block_config' => ['event_slug' => 'opening-night'],
            ],
        ]);

        self::assertSame(1, $source->cmsEntriesCalls);
        self::assertSame(1, $source->cmsCategoryCalls);
        self::assertSame(1, $source->eventCalls);
    }
}

/** @internal Test source for the direct block composition port. */
final class FakeBlockTreeSource implements BlockTreeSourceInterface
{
    /** @var array<string, mixed> */
    public array $lastCatalogQuery = [];
    public int $catalogItemCalls = 0;
    public int $cmsEntriesCalls = 0;
    public int $cmsCategoryCalls = 0;
    public int $eventCalls = 0;
    public bool $failEvents = false;
    public bool $cmsPreview = false;

    public function collections(string $locale): array
    {
        return [['id' => 10, 'collection_key' => 'news']];
    }

    public function cmsEntries(string $locale, array $query, bool $preview = false): ApiResult
    {
        $this->cmsEntriesCalls++;
        $this->cmsPreview = $preview;

        return self::success([['id' => 1]], ['total' => 1]);
    }

    public function cmsCategories(string $locale, string $collectionKey): array
    {
        $this->cmsCategoryCalls++;

        return [];
    }

    public function cmsTags(string $locale, string $collectionKey): array
    {
        return [];
    }

    public function form(string $locale, string $formKey): array
    {
        return ['form_key' => $formKey];
    }

    public function catalogItems(string $locale, array $query): ApiResult
    {
        $this->lastCatalogQuery = $query;

        return self::success([['id' => 8]], ['total' => 1]);
    }

    public function catalogCategories(): array
    {
        return [['id' => 7, 'slug' => 'painting']];
    }

    public function catalogItem(string $locale, string $idOrSlug, array $fields): ApiResult
    {
        $this->catalogItemCalls++;

        return self::success(['id' => 8, 'slug' => $idOrSlug]);
    }

    public function events(string $locale, array $query): ApiResult
    {
        return self::success([['id' => 9]]);
    }

    public function event(string $locale, string $idOrSlug, array $fields): ApiResult
    {
        $this->eventCalls++;
        if ($this->failEvents) {
            throw new \RuntimeException('event source unavailable');
        }

        return self::success(['id' => 9, 'slug' => $idOrSlug]);
    }

    public function eventTypes(): array
    {
        return [];
    }

    /** @param array<int|string, mixed> $data
     *  @param array<string, mixed> $meta
     */
    private static function success(array $data, array $meta = []): ApiResult
    {
        return new ApiResult(['ok' => true, 'data' => $data, 'meta' => $meta, 'messages' => []]);
    }
}
