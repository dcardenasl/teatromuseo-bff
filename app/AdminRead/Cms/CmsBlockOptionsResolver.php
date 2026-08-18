<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

/**
 * Fills the dynamic selects the block create/edit form needs (collection,
 * page, entry, category pickers; `entry_reference`/`entry_reference_list`
 * field options) from the already-hydrated workspace sections. Pure data
 * transform — no database access.
 *
 * Extracted from `AdminCmsWorkspaceSource::withDynamicOptions()` and its
 * private helpers as a pure move: no option-resolution logic changed.
 */
final class CmsBlockOptionsResolver
{
    /**
     * @param array<int, array<string, mixed>> $blockTypes
     * @param list<array<string, mixed>> $collections
     * @param list<string> $permissions
     * @param list<string> $preloadedForms
     * @param list<array<string, mixed>> $preloadedPages
     * @param list<array<string, mixed>> $preloadedEntries
     * @param list<array{value: string, label: string}> $preloadedCategories
     * @return array<int, array<string, mixed>>
     */
    public function withDynamicOptions(
        array $blockTypes,
        array $collections,
        array $permissions,
        array $preloadedForms,
        array $preloadedPages,
        array $preloadedEntries,
        array $preloadedCategories,
    ): array {
        $blockTypes = $this->withCollectionOptions($blockTypes, $collections);
        $collectionKeys = [];
        $collectionIds = [];
        foreach ($collections as $collection) {
            $key = trim((string) ($collection['collection_key'] ?? ''));
            $id = (int) ($collection['id'] ?? 0);
            if ($key !== '') {
                $collectionKeys[] = $key;
            }
            if ($id > 0) {
                // $key is always a defined string (never null, just possibly
                // '' when collection_key is missing) — `?? $id` never ran as
                // a fallback for an empty key, so an empty collection_key
                // used to produce an empty label instead of falling back to
                // the id.
                $label = $collection['name'] ?? ($key !== '' ? $key : $id);
                $collectionIds[] = ['value' => $id, 'label' => (string) $label];
            }
        }
        $pages = $preloadedPages;
        $entries = $preloadedEntries;
        $forms = $preloadedForms;
        $categories = $preloadedCategories;

        foreach ($blockTypes as &$blockType) {
            $schema = is_array($blockType['schema_definition'] ?? null)
                ? $blockType['schema_definition']
                : $this->decodeJson($blockType['schema_definition'] ?? null);
            if (! is_array($schema)) {
                $schema = [];
            }
            $configFields = array_replace(
                is_array($schema['config_fields'] ?? null) ? $schema['config_fields'] : [],
                is_array($blockType['config_fields'] ?? null) ? $blockType['config_fields'] : [],
            );
            $schema['config_fields'] = $configFields;
            $blockType['config_fields'] = $configFields;
            $blockType['fields'] = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

            $this->setSelectOptions($configFields, 'collection_key', $collectionKeys);
            $this->setSelectOptions($configFields, 'collection_id', $collectionIds);
            if (isset($configFields['form_key'])) {
                $this->setSelectOptions($configFields, 'form_key', $forms !== [] ? $forms : ['contact']);
            }
            if (isset($configFields['page_id'])) {
                $this->setSelectOptions($configFields, 'page_id', $this->optionRows($pages, ['name', 'title', 'label', 'slug']));
            }
            if (isset($configFields['entry_id'])) {
                $this->setSelectOptions($configFields, 'entry_id', $this->optionRows($entries, ['title', 'name', 'slug']));
            }
            if (isset($configFields['category_id']) && in_array('cms.categories.read', $permissions, true)) {
                $this->setSelectOptions($configFields, 'category_id', $categories);
            }

            foreach ($blockType['fields'] as $fieldKey => &$field) {
                if (! is_array($field)) {
                    continue;
                }
                $fieldType = (string) ($field['type'] ?? '');
                if (! in_array($fieldType, ['entry_reference', 'entry_reference_list'], true)) {
                    continue;
                }
                $allowed = $field['collection_keys'] ?? $field['allowed_collections'] ?? [];
                if (isset($field['collection_key']) && is_string($field['collection_key'])) {
                    $allowed = [$field['collection_key']];
                }
                $field['options'] = [];
                foreach (is_array($allowed) ? $allowed : [] as $collectionKey) {
                    $collectionKey = trim((string) $collectionKey);
                    foreach ($collections as $collection) {
                        if ((string) ($collection['collection_key'] ?? '') !== $collectionKey) {
                            continue;
                        }
                        $collectionEntries = array_values(array_filter(
                            $entries,
                            static fn (array $entry): bool => (int) ($entry['collection_id'] ?? 0) === (int) ($collection['id'] ?? 0),
                        ));
                        foreach ($this->optionRows($collectionEntries, ['title', 'name', 'slug']) as $option) {
                            $field['options'][] = [
                                'value' => $collectionKey . ':' . $option['value'],
                                'label' => $option['label'] . ' · ' . $collectionKey,
                            ];
                        }
                    }
                }
            }
            unset($field);
            $schema['config_fields'] = $configFields;
            $blockType['schema_definition'] = $schema;
        }
        unset($blockType);

        return $blockTypes;
    }

    /**
     * @param array<int, array<string, mixed>> $blockTypes
     * @param list<array<string, mixed>> $collections
     * @return array<int, array<string, mixed>>
     */
    private function withCollectionOptions(array $blockTypes, array $collections): array
    {
        $options = [];
        foreach ($collections as $collection) {
            $key = trim((string) ($collection['collection_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options[] = $key;
        }

        foreach ($blockTypes as &$blockType) {
            $configFields = is_array($blockType['config_fields'] ?? null) ? $blockType['config_fields'] : [];
            if (! isset($configFields['collection_key']) || ! is_array($configFields['collection_key'])) {
                continue;
            }
            if ($options !== []) {
                $configFields['collection_key']['type'] = 'select';
                $configFields['collection_key']['options'] = $options;
            }
            $blockType['config_fields'] = $configFields;
            $schema = is_array($blockType['schema_definition'] ?? null) ? $blockType['schema_definition'] : [];
            $schema['config_fields'] = $configFields;
            $blockType['schema_definition'] = $schema;
        }
        unset($blockType);

        return $blockTypes;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<mixed> $options
     */
    private function setSelectOptions(array &$fields, string $key, array $options): void
    {
        if (! isset($fields[$key]) || ! is_array($fields[$key])) {
            return;
        }
        $fields[$key]['type'] = 'select';
        $fields[$key]['options'] = $options;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fallbackKeys
     * @return list<array{value: string, label: string}>
     */
    private function optionRows(array $rows, array $fallbackKeys): array
    {
        $options = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $label = null;
            foreach ($row['translations'] ?? [] as $translation) {
                if (is_array($translation) && trim((string) ($translation['title'] ?? '')) !== '') {
                    $label = (string) $translation['title'];
                    break;
                }
            }
            foreach ($fallbackKeys as $key) {
                if ($label === null && trim((string) ($row[$key] ?? '')) !== '') {
                    $label = (string) $row[$key];
                }
            }
            $options[] = ['value' => (string) $id, 'label' => $label ?? (string) $id];
        }

        return $options;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value ?? [];
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
