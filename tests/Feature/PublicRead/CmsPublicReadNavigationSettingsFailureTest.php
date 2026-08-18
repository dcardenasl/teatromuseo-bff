<?php

declare(strict_types=1);

namespace Tests\Feature\PublicRead;

use App\PublicRead\Cms\BlockInstanceSerializer;
use App\PublicRead\Cms\EntryListingContentResolver;
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
use App\PublicRead\Cms\PublicReadSitemapReader;
use App\PublicRead\Cms\PublicReadTagReader;
use App\PublicRead\Cms\PublicRedirectResolver;
use App\PublicRead\Cms\SlugRouter;
use App\PublicRead\Cms\TranslationResolver;
use App\PublicRead\CmsPublicReadBundle;
use App\PublicRead\Support\DirectDbFileMetaResolver;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use Config\Database;
use Config\Services;

/**
 * Regression for the fix to `CmsPublicReadController::navigation()`/`::settings()`:
 * both methods used to be the only two (of 14) methods on this controller that
 * did not wrap their reader call in try/catch, so an unhandled exception fell
 * through to the framework's global exception handler — which, before the
 * `AppExceptionHandler` fix, leaked `$exception->getMessage()` verbatim to the
 * client even outside `development`. This test points the bundle at an empty
 * SQLite connection (no `cms_languages`/`cms_settings` tables), which makes
 * the reader throw a real `DatabaseException`, and asserts the controller now
 * returns the same sanitized `bff:unavailable` envelope its sibling methods
 * already return instead of letting the exception escape uncaught.
 */
final class CmsPublicReadNavigationSettingsFailureTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpControllerTestTrait();
        Services::resetSingle('publicReadCms');
        Services::injectMock('publicReadCms', $this->brokenBundle());
    }

    protected function tearDown(): void
    {
        Services::resetSingle('publicReadCms');
        parent::tearDown();
    }

    public function testNavigationReturnsSanitizedEnvelopeInsteadOfLeakingTheException(): void
    {
        $result = $this
            ->controller(\App\Controllers\Api\V1\PublicRead\CmsPublicReadController::class)
            ->execute('navigation', 'es');

        $body = json_decode((string) $result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(503, $result->response()->getStatusCode());
        $this->assertFalse($body['ok']);
        $this->assertNull($body['data']);
        $this->assertSame('unavailable', $body['source']['state']);
        $this->assertStringNotContainsString('cms_languages', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testSettingsReturnsSanitizedEnvelopeInsteadOfLeakingTheException(): void
    {
        $result = $this
            ->controller(\App\Controllers\Api\V1\PublicRead\CmsPublicReadController::class)
            ->execute('settings', 'es');

        $body = json_decode((string) $result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(503, $result->response()->getStatusCode());
        $this->assertFalse($body['ok']);
        $this->assertNull($body['data']);
        $this->assertSame('unavailable', $body['source']['state']);
        $this->assertStringNotContainsString('cms_languages', json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * Mirrors `PublicReadContainer::cms()`'s wiring exactly, but every reader
     * shares one empty in-memory SQLite connection instead of `cms_readonly`/
     * `hub_readonly`, so any query fails with a real, uncaught `DatabaseException`.
     */
    private function brokenBundle(): CmsPublicReadBundle
    {
        // `DBDebug` is deliberately `true` here (unlike the `tests` group's
        // default `false`): with debug off, a missing-table query just
        // returns `false` and the reader treats it as an empty result — no
        // exception is thrown, so the failure path this test exists to
        // cover never triggers. Debug on makes SQLite raise a real
        // `DatabaseException` for the missing `cms_languages`/`cms_settings`
        // tables, matching what a genuine connection failure looks like.
        $db = Database::connect([
            'DSN' => '',
            'hostname' => '',
            'username' => '',
            'password' => '',
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8',
            'DBCollat' => 'utf8_general_ci',
            'swapPre' => '',
            'encrypt' => false,
            'compress' => false,
            'strictOn' => false,
            'failover' => [],
            'port' => 0,
        ], false);
        $fileResolver = new FileUrlResolver(new DirectDbFileMetaResolver($db), 'http://localhost');
        $navigation = new PublicReadNavigationReader($db);
        $settings = new PublicReadSettingsReader($db, $fileResolver);
        $collections = new PublicReadCollectionReader($db);
        $categories = new PublicReadCategoryReader($db);
        $tags = new PublicReadTagReader($db);
        $forms = new PublicReadFormReader($db);
        $serializer = new BlockInstanceSerializer($db, $fileResolver);
        $pages = new PublicReadPageReader(
            db: $db,
            blockSerializer: $serializer,
            fileUrlResolver: $fileResolver,
        );
        $entries = new PublicReadEntryReader(
            $db,
            $fileResolver,
            $serializer,
            new EntryListingContentResolver($serializer),
        );
        $translationResolver = new TranslationResolver($fileResolver, $db);
        $redirects = new PublicRedirectResolver($db, $translationResolver, new SlugRouter($db));
        $sitemap = new PublicReadSitemapReader($db);

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
            sitemap: $sitemap,
        );
    }
}
