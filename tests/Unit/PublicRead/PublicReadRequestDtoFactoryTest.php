<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemRequestDTO;
use App\PublicRead\Cms\PublicReadEntryRequestDTO;
use App\PublicRead\Cms\PublicReadPageRequestDTO;
use App\PublicRead\Cms\PublicReadPageShowRequestDTO;
use App\PublicRead\Event\PublicReadEventRequestDTO;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class PublicReadRequestDtoFactoryTest extends CIUnitTestCase
{
    public function testAllListingDtosCanBeConstructedThroughTheValidatedFactory(): void
    {
        $factory = Services::requestDtoFactory(false);

        $page = $factory->make(PublicReadPageRequestDTO::class, ['locale' => 'es']);
        $preview = $factory->make(PublicReadPageShowRequestDTO::class, [
            'preview' => '1',
            'preview_expires' => (string) (time() + 60),
            'preview_sig' => str_repeat('a', 64),
        ]);
        $entry = $factory->make(PublicReadEntryRequestDTO::class, [
            'locale' => 'es',
            'collection' => 'news',
            'order_by' => 'field:block.hero.date',
            'order_direction' => 'upcoming',
            'filter_by' => 'block.hero.date',
            'filter_value' => '2026-08-13',
            'filter_operator' => 'equals',
            'include' => 'listing_content.image,listing_content.date_fields',
            'fields' => 'id,title,listing_content',
        ]);
        $catalog = $factory->make(PublicReadCollectionItemRequestDTO::class, ['locale' => 'es']);
        $event = $factory->make(PublicReadEventRequestDTO::class, ['locale' => 'es']);

        self::assertSame('es', $page->locale);
        self::assertTrue($preview->previewRequested);
        self::assertSame('news', $entry->collection);
        self::assertSame('block.hero.date', $entry->listingField);
        self::assertSame('UPCOMING', $entry->orderDirection);
        self::assertSame('block.hero.date', $entry->filterBy);
        self::assertSame(['image', 'date_fields'], $entry->listingContentFields);
        self::assertSame(['id', 'title', 'listing_content'], $entry->fields);
        self::assertSame(20, $catalog->perPage);
        self::assertSame('agenda', $event->sort);
    }
}
