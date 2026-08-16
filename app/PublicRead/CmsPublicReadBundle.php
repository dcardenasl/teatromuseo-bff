<?php

declare(strict_types=1);

namespace App\PublicRead;

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
use CodeIgniter\Database\BaseConnection;

/** Immutable CMS read graph assembled against one read-only connection. */
final readonly class CmsPublicReadBundle
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        public BaseConnection $db,
        public PublicReadNavigationReader $navigation,
        public PublicReadSettingsReader $settings,
        public PublicReadCollectionReader $collections,
        public PublicReadCategoryReader $categories,
        public PublicReadTagReader $tags,
        public PublicReadFormReader $forms,
        public PublicReadEntryReader $entries,
        public PublicReadPageReader $pages,
        public PageBootstrapCompositionReader $pageBootstrap,
        public LayoutCompositionReader $layout,
        public PublicRedirectResolver $redirects,
    ) {
    }
}
