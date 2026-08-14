<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use dcardenasl\Ci4ApiCore\Support\ApiResult;

/**
 * Read-only source boundary for page block composition.
 *
 * Keeping the resolver behind this small port makes the block algorithm
 * independently testable and prevents it from reaching into framework
 * services or the old Web HTTP client.
 */
interface BlockTreeSourceInterface
{
    /** @return list<array<string, mixed>> */
    public function collections(string $locale): array;

    /** @param array<string, mixed> $query */
    public function cmsEntries(string $locale, array $query): ApiResult;

    /** @return list<array<string, mixed>> */
    public function cmsCategories(string $locale, string $collectionKey): array;

    /** @return list<array<string, mixed>> */
    public function cmsTags(string $locale, string $collectionKey): array;

    /** @return array<string, mixed> */
    public function form(string $locale, string $formKey): array;

    /** @param array<string, mixed> $query */
    public function catalogItems(string $locale, array $query): ApiResult;

    /** @return list<array<string, mixed>> */
    public function catalogCategories(): array;

    /** @param list<string> $fields */
    public function catalogItem(string $locale, string $idOrSlug, array $fields): ApiResult;

    /** @param array<string, mixed> $query */
    public function events(string $locale, array $query): ApiResult;

    /** @param list<string> $fields */
    public function event(string $locale, string $idOrSlug, array $fields): ApiResult;

    /** @return list<array<string, mixed>> */
    public function eventTypes(): array;
}
