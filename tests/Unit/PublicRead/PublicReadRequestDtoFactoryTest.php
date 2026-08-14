<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemRequestDTO;
use App\PublicRead\Cms\PublicReadEntryRequestDTO;
use App\PublicRead\Cms\PublicReadPageRequestDTO;
use App\PublicRead\Event\PublicReadEventRequestDTO;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class PublicReadRequestDtoFactoryTest extends CIUnitTestCase
{
    public function testAllListingDtosCanBeConstructedThroughTheValidatedFactory(): void
    {
        $factory = Services::requestDtoFactory(false);

        $page = $factory->make(PublicReadPageRequestDTO::class, ['locale' => 'es']);
        $entry = $factory->make(PublicReadEntryRequestDTO::class, [
            'locale' => 'es',
            'collection' => 'news',
        ]);
        $catalog = $factory->make(PublicReadCollectionItemRequestDTO::class, ['locale' => 'es']);
        $event = $factory->make(PublicReadEventRequestDTO::class, ['locale' => 'es']);

        self::assertSame('es', $page->locale);
        self::assertSame('news', $entry->collection);
        self::assertSame(20, $catalog->perPage);
        self::assertSame('agenda', $event->sort);
    }
}
