<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

/**
 * Computes the block-scoped translation badges from the workspace projection.
 *
 * The CMS domain owns the authoritative audit implementation. This bounded
 * read model intentionally mirrors only the contextual badge contract, using
 * rows that are already present in the single workspace query. It therefore
 * removes the Admin -> CMS audit request without making the BFF depend on the
 * CMS application's PHP classes or database connection.
 */
final class BlockTranslationStatusProjection
{
    /** @var list<string> */
    private const AUDITABLE_BLOCK_STRING_FIELDS = [
        'alt', 'caption', 'link_label', 'label', 'heading', 'subheading',
        'breadcrumb_label', 'title', 'subtitle', 'summary', 'bio',
        'button_label', 'cta_label', 'section_title', 'section_subtitle',
        'section_label', 'intro_title', 'item_label', 'count_label',
        'empty_message', 'featured_item_label', 'document_label',
        'fallback_title', 'message',
    ];

    /** @var list<string> */
    private const AUDITABLE_BLOCK_FIELD_TYPES = ['text', 'textarea', 'richtext'];

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<int|string, array<string, mixed>> $blockTypes
     * @param list<array<string, mixed>> $languages
     * @return array{blocks: array<int, array<string, array{language_id:int,status:string,detail:string}>>, summary: array<string, array{complete:int,total:int}>}
     */
    public function project(array $blocks, array $blockTypes, array $languages): array
    {
        $activeLanguages = array_values(array_filter(
            $languages,
            static fn (array $language): bool => (bool) ($language['is_active'] ?? true),
        ));
        $defaultLanguageId = $this->defaultLanguageId($activeLanguages);
        $summary = [];

        foreach ($activeLanguages as $language) {
            $code = trim((string) ($language['code'] ?? ''));
            $id = (int) ($language['id'] ?? 0);
            if ($code === '' || $id < 1) {
                continue;
            }
            $summary[$code] = ['complete' => 0, 'total' => 0];
        }

        $projected = [];
        foreach ($blocks as $block) {
            $instanceId = (int) ($block['id'] ?? 0);
            if ($instanceId < 1) {
                continue;
            }

            $blockTypeId = (int) ($block['block_id'] ?? 0);
            $blockType = $blockTypes[$blockTypeId] ?? $blockTypes[(string) $blockTypeId] ?? [];
            $fieldDefinitions = $this->fieldDefinitions(
                is_array($blockType['schema_definition'] ?? null) ? $blockType['schema_definition'] : [],
            );
            if ($fieldDefinitions === []) {
                continue;
            }

            $translations = [];
            foreach ($block['translations'] ?? [] as $translation) {
                if (! is_array($translation)) {
                    continue;
                }
                $languageId = (int) ($translation['language_id'] ?? 0);
                if ($languageId > 0) {
                    $translations[$languageId] = $translation;
                }
            }

            $reportMissing = $this->shouldReportMissing($translations, $fieldDefinitions);
            $perLanguage = [];
            foreach ($activeLanguages as $language) {
                $languageId = (int) ($language['id'] ?? 0);
                $languageCode = trim((string) ($language['code'] ?? ''));
                if ($languageId < 1 || $languageCode === '') {
                    continue;
                }

                $translation = $translations[$languageId] ?? null;
                if ($translation === null && ! $reportMissing) {
                    [$status, $detail] = ['complete', ''];
                } else {
                    [$status, $detail] = $this->evaluate(
                        $translation,
                        $translations,
                        $fieldDefinitions,
                        $languageId,
                        $languageId === $defaultLanguageId ? null : (string) ($block['updated_at'] ?? ''),
                        $defaultLanguageId,
                    );
                }

                $status = match ($status) {
                    'mismatch', 'untranslated' => 'incomplete',
                    'outdated' => 'complete',
                    default => $status,
                };
                $perLanguage[$languageCode] = [
                    'language_id' => $languageId,
                    'status' => $status,
                    'detail' => $detail,
                ];
                $summary[$languageCode] ??= ['complete' => 0, 'total' => 0];
                $summary[$languageCode]['complete'] += $status === 'complete' ? 1 : 0;
                $summary[$languageCode]['total']++;
            }

            if ($perLanguage !== []) {
                $projected[$instanceId] = $perLanguage;
            }
        }

        return ['blocks' => $projected, 'summary' => $summary];
    }

    /** @param list<array<string, mixed>> $languages */
    private function defaultLanguageId(array $languages): ?int
    {
        foreach ($languages as $language) {
            if ((bool) ($language['is_default'] ?? false) && (int) ($language['id'] ?? 0) > 0) {
                return (int) $language['id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array{required:bool,type:string,data_key:string,compareToSource:bool}>
     */
    private function fieldDefinitions(array $schema): array
    {
        $definitions = [];
        foreach ($schema['fields'] ?? [] as $key => $field) {
            if (! is_array($field)) {
                continue;
            }
            $key = (string) $key;
            $type = strtolower((string) ($field['type'] ?? 'string'));
            $isAuditable = ($field['translatable'] ?? null) === true
                || in_array($key, self::AUDITABLE_BLOCK_STRING_FIELDS, true)
                || ($type !== 'string' && in_array($type, self::AUDITABLE_BLOCK_FIELD_TYPES, true));
            if (! $isAuditable) {
                continue;
            }
            $definitions[$key] = [
                'required' => (bool) ($field['required'] ?? false),
                'type' => $type,
                'data_key' => $key,
                'compareToSource' => $type !== 'url',
            ];
        }

        return $definitions;
    }

    /**
     * @param array<int, array<string, mixed>> $translations
     * @param array<string, array<string, mixed>> $fieldDefinitions
     */
    private function shouldReportMissing(array $translations, array $fieldDefinitions): bool
    {
        foreach ($fieldDefinitions as $definition) {
            if ((bool) ($definition['required'] ?? false)) {
                return true;
            }
        }

        foreach ($translations as $translation) {
            foreach ($fieldDefinitions as $key => $definition) {
                if (! $this->isBlank($this->fieldValue($translation, (string) $key, $definition))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $translation
     * @param array<int, array<string, mixed>> $translations
     * @param array<string, array<string, mixed>> $fieldDefinitions
     * @return array{0: string, 1: string}
     */
    private function evaluate(?array $translation, array $translations, array $fieldDefinitions, int $languageId, ?string $resourceUpdatedAt, ?int $defaultLanguageId): array
    {
        if ($translation === null) {
            return ['missing', 'Translation is missing completely'];
        }

        $source = null;
        if ($defaultLanguageId !== null && $defaultLanguageId !== $languageId) {
            $source = $translations[$defaultLanguageId] ?? null;
        }
        $missingRequired = [];
        $mismatchedOptional = [];
        $identicalToSource = [];
        $hasTranslatedContent = false;

        foreach ($fieldDefinitions as $key => $definition) {
            $current = $this->fieldValue($translation, $key, $definition);
            if ($this->isBlank($current)) {
                if ((bool) ($definition['required'] ?? false)) {
                    $missingRequired[] = $key;
                    continue;
                }
                foreach ($translations as $otherLanguageId => $otherTranslation) {
                    if ($otherLanguageId === $languageId) {
                        continue;
                    }
                    $other = $this->fieldValue($otherTranslation, $key, $definition);
                    if (! $this->isBlank($other)) {
                        $mismatchedOptional[] = $key;
                        break;
                    }
                }
                continue;
            }

            if ($source !== null && (bool) ($definition['compareToSource'] ?? false)) {
                $sourceValue = $this->fieldValue($source, $key, $definition);
                if (! $this->isBlank($sourceValue) && $this->valuesAreIdentical($current, $sourceValue)) {
                    $identicalToSource[] = $key;
                } else {
                    $hasTranslatedContent = true;
                }
            }
        }

        if ($missingRequired !== []) {
            return ['incomplete', 'Missing required fields: ' . implode(', ', array_unique($missingRequired))];
        }
        if ($identicalToSource !== [] && ! $hasTranslatedContent) {
            return ['untranslated', 'Same text as the default language: ' . implode(', ', array_unique($identicalToSource))];
        }
        if ($mismatchedOptional !== []) {
            return ['mismatch', 'Inconsistent fields: ' . implode(', ', array_unique($mismatchedOptional))];
        }
        if ($resourceUpdatedAt !== null && $resourceUpdatedAt !== '') {
            $sourceTimestamp = strtotime($resourceUpdatedAt);
            $translationTimestamp = strtotime((string) ($translation['updated_at'] ?? ''));
            if ($sourceTimestamp !== false && $translationTimestamp !== false && $translationTimestamp < $sourceTimestamp) {
                return ['outdated', 'Translation predates the latest source update'];
            }
        }

        return ['complete', ''];
    }

    /**
     * @param array<string, mixed> $translation
     * @param array<string, mixed> $definition
     */
    private function fieldValue(array $translation, string $key, array $definition): mixed
    {
        $data = $translation['block_data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (! is_array($data)) {
            return null;
        }

        return $data[(string) ($definition['data_key'] ?? $key)] ?? null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === []);
    }

    private function valuesAreIdentical(mixed $left, mixed $right): bool
    {
        if (is_string($left) && is_string($right)) {
            return trim($left) === trim($right);
        }

        return $left === $right;
    }
}
