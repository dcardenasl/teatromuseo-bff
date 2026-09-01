<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Support\PublicReadEnvelope;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Composes the internal CMS page/redirect bootstrap for page resolution. */
final class PageBootstrapCompositionReader
{
    public function __construct(
        private readonly PublicRedirectResolver $redirectResolver,
        private readonly PublicReadPageReader $pageReader,
    ) {
    }

    /** @param list<string> $fields */
    public function show(string $locale, string $path, array $fields = [], bool $preview = false): ApiResult
    {
        $redirect = null;
        try {
            $redirect = $this->redirectResolver->resolve([trim($path, '/')]);
        } catch (NotFoundException) {
        }
        $pageResult = $this->pageReader->show($locale, $path, $fields, $preview);
        $pageFound = (bool) ($pageResult->body['ok'] ?? false);
        $data = ['redirect' => $redirect, 'page' => $pageFound ? $pageResult->body['data'] : null];
        $revision = sprintf(
            'cms-page-bootstrap:%s|%s',
            (string) ($pageResult->body['meta']['source_revision'] ?? 'empty'),
            $redirect !== null ? 'redirect' : 'no-redirect',
        );

        return PublicReadEnvelope::success(
            locale: $locale,
            data: $data,
            sourceRevision: $revision,
            domain: 'cms',
            meta: ['fields' => $fields, 'query' => ['path' => $path, 'preview' => $preview]],
        );
    }
}
