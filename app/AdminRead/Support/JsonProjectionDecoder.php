<?php

declare(strict_types=1);

namespace App\AdminRead\Support;

/**
 * Decodes a `JSON_ARRAYAGG`/`json_group_array` projection column (already
 * produced by {@see JsonArrayAggregateSql}) back into a list of associative
 * rows, tolerating a `null`/malformed value as an empty list. Extracted from
 * `AdminCatalogCollectionItemSource` and `AdminEventWorkspaceSource`, which
 * reimplemented this identically.
 */
final class JsonProjectionDecoder
{
    /** @return list<array<string,mixed>> */
    public static function decodeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
