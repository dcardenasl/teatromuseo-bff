<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

final class SchemaDefaults
{
    /**
     * Apply field defaults from a schema definition to a payload.
     *
     * Defaults are only written when a key is missing or null. Explicit empty
     * strings/arrays are preserved so editors can still intentionally clear a
     * value after it has been seeded.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function apply(array $payload, array $fields): array
    {
        foreach ($fields as $fieldKey => $definition) {
            if (! is_string($fieldKey) || ! is_array($definition)) {
                continue;
            }

            $hasValue = array_key_exists($fieldKey, $payload) && $payload[$fieldKey] !== null;
            if (! $hasValue && array_key_exists('default', $definition)) {
                $payload[$fieldKey] = $definition['default'];
            }

            $fieldType = strtolower((string) ($definition['type'] ?? ''));
            if ($fieldType === 'repeater' && is_array($payload[$fieldKey] ?? null)) {
                $itemFields = is_array($definition['item_fields'] ?? null) ? (array) $definition['item_fields'] : [];
                if ($itemFields === []) {
                    continue;
                }

                $items = [];
                foreach ($payload[$fieldKey] as $item) {
                    $items[] = is_array($item)
                        ? self::apply($item, $itemFields)
                        : $item;
                }

                $payload[$fieldKey] = $items;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function applyConfigDefaults(array $schemaDefinition, array $config = []): array
    {
        $configFields = is_array($schemaDefinition['config_fields'] ?? null)
            ? (array) $schemaDefinition['config_fields']
            : [];

        foreach ($configFields as $fieldKey => $definition) {
            if (! is_string($fieldKey) || ! is_array($definition)) {
                continue;
            }

            if (array_key_exists($fieldKey, $config) && $config[$fieldKey] !== null) {
                continue;
            }

            if (array_key_exists('default', $definition)) {
                $config[$fieldKey] = $definition['default'];
            }
        }

        return $config;
    }
}
