<?php

declare(strict_types=1);

namespace App\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemReader;
use App\PublicRead\Cms\BlockInstanceSerializer;
use App\PublicRead\Cms\FileUrlResolver;
use App\PublicRead\Cms\PublicReadCategoryReader;
use App\PublicRead\Cms\PublicReadCollectionReader;
use App\PublicRead\Cms\PublicReadEntryReader;
use App\PublicRead\Cms\PublicReadFormReader;
use App\PublicRead\Cms\PublicReadLayoutReader;
use App\PublicRead\Cms\PublicReadNavigationReader;
use App\PublicRead\Cms\PublicReadPageBootstrapReader;
use App\PublicRead\Cms\PublicReadPageReader;
use App\PublicRead\Cms\PublicReadSettingsReader;
use App\PublicRead\Cms\PublicReadTagReader;
use App\PublicRead\Cms\PublicRedirectResolver;
use App\PublicRead\Cms\SlugRouter;
use App\PublicRead\Cms\TranslationResolver;
use App\PublicRead\Event\PublicReadEventReader;
use App\PublicRead\Support\DirectDbFileMetaResolver;
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
        $pages = new PublicReadPageReader($db, $serializer, self::fallbackLocale());
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
            pageBootstrap: new PublicReadPageBootstrapReader($redirects, $pages),
            layout: new PublicReadLayoutReader($navigation, $settings, $collections),
            redirects: $redirects,
        );
    }

    public static function catalog(): PublicReadCollectionItemReader
    {
        return new PublicReadCollectionItemReader(self::database('catalog_readonly'), self::fileMetaResolver(), self::fallbackLocale());
    }

    public static function catalogFacets(): CatalogFacetReader
    {
        return new CatalogFacetReader(self::database('catalog_readonly'));
    }

    public static function events(): PublicReadEventReader
    {
        return new PublicReadEventReader(
            self::database('event_readonly'),
            self::fileMetaResolver(),
            (string) env('EVENT_SCHEDULE_TIMEZONE', 'America/Santiago'),
            self::fallbackLocale(),
        );
    }

    public static function eventTypes(): EventTypeReader
    {
        return new EventTypeReader(self::database('event_readonly'));
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
