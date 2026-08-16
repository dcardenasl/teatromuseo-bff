<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Page\CatalogItemReaderInterface;
use App\PublicRead\Page\CollectionReaderInterface;
use App\PublicRead\Page\EntryReaderInterface;
use App\PublicRead\Page\EventReaderInterface;
use App\PublicRead\Page\PageReaderInterface;
use App\PublicRead\Page\PageResolver;
use App\PublicRead\Page\PublicPagePaths;
use App\PublicRead\Page\RedirectReaderInterface;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

final class PublicPagePathsTest extends CIUnitTestCase
{
    /** @dataProvider aliasProvider */
    public function testCanonicalizesEveryKnownAlias(string $alias, string $locale, ?string $expected): void
    {
        self::assertSame($expected, PublicPagePaths::canonicalPath($alias, $locale));
    }

    public function testReturnsAllEquivalentAliasesWithoutTheRequestedPath(): void
    {
        $aliases = PublicPagePaths::aliasesFor('programming', 'fr');
        sort($aliases);

        self::assertSame(
            ['cartelera', 'eventos', 'events', 'programacao', 'programmation', 'programme'],
            $aliases,
        );
    }

    public function testRecognizesTheStalePublicBasePath(): void
    {
        self::assertTrue(PublicPagePaths::isLegacyPublicBasePath('/public/es', 'es'));
        self::assertFalse(PublicPagePaths::isLegacyPublicBasePath('/public/en', 'es'));
    }

    public function testExportsTheVersionedRouteContract(): void
    {
        $contract = PublicPagePaths::publicRouteContract();

        self::assertSame(1, $contract['version']);
        self::assertSame(['es', 'en', 'fr', 'pt'], $contract['locales']);
        self::assertSame('programmation', $contract['routes']['events']['fr']);
        self::assertContains('programmation', $contract['aliases']['events']);
        self::assertSame('musee/collection', $contract['routes']['catalog']['fr']);
    }

    public function testResolvesCmsPageAndPreservesTheSourcePageType(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->expects(self::once())->method('show')->with('es', 'contacto', [], false)->willReturn(
            new ApiResult([
                'ok' => true,
                'data' => ['id' => 4, 'page_type' => 'contact'],
            ], 200),
        );

        $result = (new PageResolver($redirects, $pages))->resolve('es', 'contacto');

        self::assertSame('page', $result['outcome']);
        self::assertSame('cms_page', $result['page']['page_type']);
        self::assertSame('contact', $result['page']['source_page_type']);
    }

    public function testUsesTheCanonicalPageWhenTheRequestedPathIsAnAlias(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $paths = [];
        $pages->expects(self::exactly(2))->method('show')->willReturnCallback(
            function (string $locale, string $path, array $fields, bool $preview) use (&$paths): ApiResult {
                $paths[] = [$locale, $path, $fields, $preview];

                return count($paths) === 1
                    ? new ApiResult(['ok' => false, 'data' => null], 404)
                    : new ApiResult(['ok' => true, 'data' => ['page_type' => 'events']], 200);
            },
        );

        $result = (new PageResolver($redirects, $pages))->resolve('en', 'cartelera');

        self::assertSame('page', $result['outcome']);
        self::assertSame('cms_page', $result['page']['page_type']);
        self::assertSame('events', $result['page']['source_page_type']);
        self::assertSame([
            ['en', 'cartelera', [], false],
            ['en', 'programming', [], false],
        ], $paths);
    }

    public function testReturnsARedirectWithoutReadingThePage(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->expects(self::once())->method('resolve')->with(['contacto'])->willReturn([
            'new_url' => '/contact',
            'redirect_type' => 302,
        ]);
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->expects(self::never())->method('show');

        $result = (new PageResolver($redirects, $pages))->resolve('es', 'contacto');

        self::assertSame('redirect', $result['outcome']);
        self::assertSame(['path' => '/contacto', 'status' => 302], $result['redirect']);
    }

    public function testReturnsNotFoundAfterTheCmsAndAliasCandidatesMiss(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));

        $result = (new PageResolver($redirects, $pages))->resolve('es', 'does-not-exist');

        self::assertSame('not_found', $result['outcome']);
        self::assertNull($result['page']);
    }

    public function testResolvesAnEventDetailThroughTheSingletonTemplateAndSeedsItsContext(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));
        $pages->expects(self::once())->method('byType')->with('es', 'template_event_item')->willReturn(
            new ApiResult([
                'ok' => true,
                'data' => [
                    'page_type' => 'template_event_item',
                    'blocks' => [['block_key' => 'event_item_header']],
                ],
            ], 200),
        );
        $events = $this->createMock(EventReaderInterface::class);
        $events->expects(self::once())->method('show')->with('es', 'festival-uno', [])->willReturn(
            new ApiResult([
                'ok' => true,
                'data' => [
                    'id' => 201,
                    'title' => 'Festival Uno',
                    'description' => 'Descripción del festival uno.',
                    'slug' => 'festival-uno',
                    'slugs' => ['es' => 'festival-uno'],
                    'localized' => [
                        'title' => 'Festival Uno',
                        'description' => 'Descripción del festival uno.',
                    ],
                ],
            ], 200),
        );

        $result = (new PageResolver(
            redirects: $redirects,
            pages: $pages,
            events: $events,
        ))->resolve('es', 'cartelera/festival-uno');

        self::assertSame('page', $result['outcome']);
        self::assertSame('cms_page', $result['page']['page_type']);
        self::assertSame('template_event_item', $result['page']['source_page_type']);
        self::assertSame('Festival Uno', $result['page']['title']);
        self::assertSame('/es/cartelera/festival-uno', $result['page']['canonical_url']);
        self::assertSame('Festival Uno', $result['context']['event_item']['localized']['title']);
    }

    public function testResolvesACatalogDetailThroughTheSingletonTemplateAndSeedsItsContext(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));
        $pages->expects(self::once())->method('byType')->with('es', 'template_catalog_item')->willReturn(
            new ApiResult([
                'ok' => true,
                'data' => [
                    'page_type' => 'template_catalog_item',
                    'blocks' => [['block_key' => 'catalog_item_header']],
                ],
            ], 200),
        );
        $catalogItems = $this->createMock(CatalogItemReaderInterface::class);
        $catalogItems->expects(self::once())->method('show')->with('es', 'TMP-001', [])->willReturn(
            new ApiResult([
                'ok' => true,
                'data' => [
                    'id' => 101,
                    'name' => 'Pieza de prueba',
                    'summary' => 'Resumen de prueba.',
                    'slug' => 'pieza-de-prueba',
                    'slugs' => ['es' => 'pieza-de-prueba'],
                    'localized' => [
                        'name' => 'Pieza localizada',
                        'summary' => 'Resumen localizado.',
                    ],
                ],
            ], 200),
        );

        $result = (new PageResolver(
            redirects: $redirects,
            pages: $pages,
            catalogItems: $catalogItems,
        ))->resolve('es', 'museo/coleccion/TMP-001');

        self::assertSame('page', $result['outcome']);
        self::assertSame('template_catalog_item', $result['page']['source_page_type']);
        self::assertSame('Pieza localizada', $result['page']['title']);
        self::assertSame('/es/museo/coleccion/pieza-de-prueba', $result['page']['canonical_url']);
        self::assertSame('Pieza localizada', $result['context']['catalog_item']['localized']['name']);
    }

    public function testDoesNotFallThroughWhenAConfiguredDomainDetailRouteIsMalformed(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));
        $events = $this->createMock(EventReaderInterface::class);
        $events->expects(self::never())->method('show');

        $result = (new PageResolver(
            redirects: $redirects,
            pages: $pages,
            events: $events,
        ))->resolve('es', 'cartelera/festival-uno/extra');

        self::assertSame('not_found', $result['outcome']);
        self::assertNull($result['page']);
    }

    public function testResolvesCollectionEntryAndAttachesRelatedEntries(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));
        $collections = $this->createMock(CollectionReaderInterface::class);
        $collections->expects(self::once())->method('list')->with('es')->willReturn([
            [
                'id' => 7,
                'collection_key' => 'news',
                'index_page' => ['localized_slugs' => ['es' => 'noticias']],
            ],
        ]);
        $entries = $this->createMock(EntryReaderInterface::class);
        $entries->expects(self::once())->method('show')->with('es', 'news', 'current', [])->willReturn(
            new ApiResult(['ok' => true, 'data' => ['slug' => 'current', 'title' => 'Current']], 200),
        );
        $entries->expects(self::once())->method('related')->with('es', 'news', ['slug' => 'current', 'title' => 'Current'], 3)->willReturn([
            ['slug' => 'related'],
        ]);

        $result = (new PageResolver($redirects, $pages, $collections, $entries))->resolve('es', 'noticias/current');

        self::assertSame('page', $result['outcome']);
        self::assertSame('collection_entry', $result['page']['page_type']);
        self::assertSame('news', $result['page']['collection']['collection_key']);
        self::assertSame([['slug' => 'related']], $result['page']['related_entries']);
    }

    public function testRelatedFailureDoesNotDiscardTheCollectionEntry(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));
        $collections = $this->createMock(CollectionReaderInterface::class);
        $collections->method('list')->willReturn([['collection_key' => 'news']]);
        $entries = $this->createMock(EntryReaderInterface::class);
        $entries->method('show')->willReturn(new ApiResult(['ok' => true, 'data' => ['slug' => 'current']], 200));
        $entries->method('related')->willThrowException(new \RuntimeException('related source unavailable'));

        $result = (new PageResolver($redirects, $pages, $collections, $entries))->resolve('es', 'news/current');

        self::assertSame('collection_entry', $result['page']['page_type']);
        self::assertSame([], $result['page']['related_entries']);
    }

    public function testPreviewForwardsToCollectionEntryAndRelatedReads(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));
        $collections = $this->createMock(CollectionReaderInterface::class);
        $collections->method('list')->willReturn([[
            'collection_key' => 'news',
            'index_page' => ['localized_slugs' => ['es' => 'noticias']],
        ]]);
        $entries = $this->createMock(EntryReaderInterface::class);
        $entries->expects(self::once())->method('show')->with('es', 'news', 'draft', [], true)->willReturn(
            new ApiResult(['ok' => true, 'data' => ['slug' => 'draft']], 200),
        );
        $entries->expects(self::once())->method('related')->with('es', 'news', ['slug' => 'draft'], 3, true)->willReturn([]);

        $result = (new PageResolver($redirects, $pages, $collections, $entries))->resolve('es', 'noticias/draft', true);

        self::assertSame('collection_entry', $result['page']['page_type']);
    }

    public function testSynthesizesTheFallbackCollectionIndexWhenNoCmsIndexExists(): void
    {
        $redirects = $this->createMock(RedirectReaderInterface::class);
        $redirects->method('resolve')->willThrowException(new NotFoundException());
        $pages = $this->createMock(PageReaderInterface::class);
        $pages->method('show')->willReturn(new ApiResult(['ok' => false, 'data' => null], 404));
        $collections = $this->createMock(CollectionReaderInterface::class);
        $collections->expects(self::once())->method('list')->with('es')->willReturn([
            [
                'id' => 4,
                'collection_key' => 'cartelera',
                'name' => 'Cartelera',
                'listing_intro' => 'Obras publicadas.',
                'localized_slugs' => ['es' => 'cartelera', 'en' => 'billboard'],
                'index_page' => null,
            ],
        ]);
        $entries = $this->createMock(EntryReaderInterface::class);

        $result = (new PageResolver($redirects, $pages, $collections, $entries))->resolve('es', 'cartelera');

        self::assertSame('collection_fallback_index', $result['page']['page_type']);
        self::assertSame('Cartelera', $result['page']['title']);
        self::assertSame('/es/cartelera', $result['page']['canonicalUrl']);
        self::assertSame('/en/cartelera', $result['page']['localized_urls']['en']);
        self::assertSame('collection_listing', $result['page']['blocks'][0]['block_key']);
        self::assertSame(12, $result['page']['blocks'][0]['block_config']['items_limit']);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function aliasProvider(): iterable
    {
        yield 'homepage' => ['home', 'es', 'inicio'];
        yield 'events' => ['cartelera', 'en', 'programming'];
        yield 'catalog' => ['museo/coleccion', 'pt', 'museu/colecao'];
        yield 'contact' => ['contacto', 'en', 'contact'];
        yield 'history' => ['histoire', 'es', 'historia'];
        yield 'theatre school' => ['cursos', 'fr', 'theatreecole'];
        yield 'unknown' => ['custom-destination', 'es', null];
    }
}
