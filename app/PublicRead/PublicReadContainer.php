<?php

declare(strict_types=1);

namespace App\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemReader;
use App\PublicRead\Cms\BlockInstanceSerializer;
use App\PublicRead\Cms\FileUrlResolver;
use App\PublicRead\Cms\LayoutCompositionReader;
use App\PublicRead\Cms\PageBootstrapCompositionReader;
use App\PublicRead\Cms\PublicReadCategoryReader;
use App\PublicRead\Cms\PublicReadCollectionReader;
use App\PublicRead\Cms\PublicReadEntryReader;
use App\PublicRead\Cms\PublicReadFormReader;
use App\PublicRead\Cms\PublicReadNavigationReader;
use App\PublicRead\Cms\PublicReadPageReader;
use App\PublicRead\Cms\PublicReadSettingsReader;
use App\PublicRead\Cms\PublicReadTagReader;
use App\PublicRead\Cms\PublicRedirectResolver;
use App\PublicRead\Cms\SlugRouter;
use App\PublicRead\Cms\TranslationResolver;
use App\PublicRead\Event\PublicReadEventReader;
use App\PublicRead\Support\DirectDbFileMetaResolver;
use App\PublicRead\Support\MediaHydrator;
use CodeIgniter\Database\BaseConnection;

/** Construction seam for all direct public-read dependencies. */
final class PublicReadContainer
{
    /** @return BaseConnection<mixed, mixed> */
    public static function database(string $group): BaseConnection
    {
        if ($group === '') {
            throw new \InvalidArgumentException('A public-read database group is required.');
        }

        /** @var BaseConnection<mixed, mixed> $connection */
        $connection = \Config\Database::connect($group);

        return $connection;
    }

    public static function cms(): CmsPublicReadBundle
    {
        $db = self::database('cms_readonly');
        $fileResolver = new FileUrlResolver(self::fileMetaResolver(), (string) config('Bff')->hubPublicBaseUrl);
        $navigation = new PublicReadNavigationReader($db, self::fallbackLocale());
        $settings = new PublicReadSettingsReader($db, $fileResolver, self::fallbackLocale());
        $collections = new PublicReadCollectionReader($db, self::fallbackLocale());
        $categories = new PublicReadCategoryReader($db, self::fallbackLocale());
        $tags = new PublicReadTagReader($db, self::fallbackLocale());
        $forms = new PublicReadFormReader($db, self::fallbackLocale());
        $serializer = new BlockInstanceSerializer($db, $fileResolver);
        $pages = new PublicReadPageReader(
            db: $db,
            blockSerializer: $serializer,
            fileUrlResolver: $fileResolver,
            fallbackLocale: self::fallbackLocale(),
        );
        $entries = new PublicReadEntryReader(
            $db,
            $fileResolver,
            $serializer,
            new \App\PublicRead\Cms\EntryListingContentResolver($serializer),
            self::fallbackLocale(),
        );
        $translationResolver = new TranslationResolver($fileResolver, $db);
        $redirects = new PublicRedirectResolver($db, $translationResolver, new SlugRouter($db));

        return new CmsPublicReadBundle(
            db: $db,
            navigation: $navigation,
            settings: $settings,
            collections: $collections,
            categories: $categories,
            tags: $tags,
            forms: $forms,
            entries: $entries,
            pages: $pages,
            pageBootstrap: new PageBootstrapCompositionReader($redirects, $pages),
            layout: new LayoutCompositionReader($navigation, $settings, $collections),
            redirects: $redirects,
        );
    }

    public static function catalog(): PublicReadCollectionItemReader
    {
        return new PublicReadCollectionItemReader(
            self::database('catalog_readonly'),
            new MediaHydrator(self::fileMetaResolver()),
            self::fallbackLocale(),
        );
    }

    public static function catalogFacets(): CatalogFacetReader
    {
        return new CatalogFacetReader(self::database('catalog_readonly'));
    }

    public static function events(): PublicReadEventReader
    {
        return new PublicReadEventReader(
            self::database('event_readonly'),
            new MediaHydrator(self::fileMetaResolver()),
            (string) env('EVENT_SCHEDULE_TIMEZONE', 'America/Santiago'),
            self::fallbackLocale(),
        );
    }

    public static function eventTypes(): EventTypeReader
    {
        return new EventTypeReader(self::database('event_readonly'));
    }

    public static function blockTree(): Page\BlockTreeResolver
    {
        return self::blockTreeFor(self::cms());
    }

    public static function pageEnvelope(): Page\PageEnvelope
    {
        $cms = self::cms();

        return new Page\PageEnvelope(
            resolver: new Page\PageResolver(
                redirects: $cms->redirects,
                pages: $cms->pages,
                collections: $cms->collections,
                entries: $cms->entries,
                events: self::events(),
                catalogItems: self::catalog(),
            ),
            layout: $cms->layout,
            blocks: self::blockTreeFor($cms),
            menuUrls: new Page\PublicMenuUrlResolver(),
        );
    }

    private static function blockTreeFor(CmsPublicReadBundle $cms): Page\BlockTreeResolver
    {
        return new Page\BlockTreeResolver(new Page\PublicReadBlockTreeSource(
            cms: $cms,
            catalog: self::catalog(),
            catalogFacets: self::catalogFacets(),
            eventsReader: self::events(),
            eventTypesReader: self::eventTypes(),
            requestDtos: \Config\Services::requestDtoFactory(false),
        ));
    }

    private static function fileMetaResolver(): DirectDbFileMetaResolver
    {
        $bff = config('Bff');

        return new DirectDbFileMetaResolver(
            self::database('hub_readonly'),
            (string) $bff->hubPublicBaseUrl,
        );
    }

    private static function fallbackLocale(): string
    {
        return strtolower((string) env('PUBLIC_READ_FALLBACK_LOCALE', 'es'));
    }
}
