<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Support\PublicReadEnvelope;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Composes CMS-only layout reads without invoking CRUD services. */
final class PublicReadLayoutReader
{
    public function __construct(
        private readonly PublicReadNavigationReader $navigationReader,
        private readonly PublicReadSettingsReader $settingsReader,
        private readonly PublicReadCollectionReader $collectionReader,
    ) {
    }

    public function show(string $locale): ApiResult
    {
        $navigation = $this->navigationReader->show($locale);
        $settings = $this->settingsReader->show($locale);
        $collections = $this->collectionReader->list($locale);
        $data = [
            'navigation' => $navigation->body['data'] ?? ['main' => null, 'footer' => null, 'legal' => null],
            'collections' => $collections,
            'settings' => $settings->body['data'] ?? [],
        ];
        $revision = sprintf(
            'cms-layout:%s|%s|collections:%d',
            (string) ($navigation->body['meta']['source_revision'] ?? 'empty'),
            (string) ($settings->body['meta']['source_revision'] ?? 'empty'),
            count($collections),
        );

        return PublicReadEnvelope::success(
            locale: $locale,
            data: $data,
            sourceRevision: $revision,
            domain: 'cms',
            meta: ['fields' => [], 'query' => ['resource' => 'layout']],
        );
    }
}
