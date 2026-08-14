<?php

declare(strict_types=1);

namespace App\PublicRead\Support;

use DateTimeImmutable;
use DateTimeZone;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Canonical versioned envelope shared by all public-read packages. */
final class PublicReadEnvelope
{
    /**
     * @param array<int|string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function success(
        string $locale,
        array $data,
        string $sourceRevision,
        string $domain,
        ?int $page = null,
        ?int $perPage = null,
        ?int $total = null,
        array $meta = [],
    ): ApiResult {
        return new ApiResult([
            'version' => 1,
            'ok' => true,
            'data' => $data,
            'meta' => array_merge([
                'locale' => $locale,
                'source_revision' => $sourceRevision,
                'snapshot_revision' => null,
                'fields' => [],
                'generated_at' => self::now(),
                'expires_at' => null,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ], $meta),
            'source' => ['domain' => $domain, 'state' => 'fresh', 'stale' => false],
            'messages' => [],
        ]);
    }

    /** @param array<string, mixed> $meta */
    public static function unavailable(
        string $locale,
        string $message,
        string $sourceRevision,
        string $domain,
        array $meta = [],
    ): ApiResult {
        return new ApiResult([
            'version' => 1,
            'ok' => false,
            'data' => null,
            'meta' => array_merge([
                'locale' => $locale,
                'source_revision' => $sourceRevision,
                'snapshot_revision' => null,
                'fields' => [],
                'generated_at' => self::now(),
                'expires_at' => null,
                'page' => null,
                'per_page' => null,
                'total' => null,
            ], $meta),
            'source' => ['domain' => $domain, 'state' => 'unavailable', 'stale' => false],
            'messages' => [$message],
        ], 503);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
