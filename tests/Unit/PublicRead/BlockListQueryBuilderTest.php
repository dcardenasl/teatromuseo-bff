<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Page\BlockListQueryBuilder;
use PHPUnit\Framework\TestCase;

final class BlockListQueryBuilderTest extends TestCase
{
    public function testCollectionListingRequestsItsVideoSlot(): void
    {
        $query = (new BlockListQueryBuilder())->build([
            'block_key' => 'collection_listing',
            'source_type' => 'cms_collection',
            'payload' => [
                'collection_key' => 'videos',
                'per_page' => 12,
            ],
        ], 'cms_collection');

        self::assertStringContainsString('listing_content.video', $query['include']);
        self::assertSame('id,slug,title,excerpt,published_at,featured_image,listing_content', $query['fields']);
        self::assertSame(12, $query['per_page']);
    }

    public function testCollectionGridRequestsOnlyItsFieldsAndVideoSlot(): void
    {
        $query = (new BlockListQueryBuilder())->build([
            'block_key' => 'collection_grid',
            'source_type' => 'cms_collection',
            'payload' => [
                'collection_key' => 'videos',
                'items_limit' => 6,
            ],
        ], 'cms_collection');

        self::assertSame('listing_content.fields,listing_content.video', $query['include']);
        self::assertSame('id,slug,title,excerpt,published_at,featured_image,listing_content', $query['fields']);
        self::assertSame(6, $query['per_page']);
    }
}
