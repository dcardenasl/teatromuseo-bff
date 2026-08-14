<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

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
