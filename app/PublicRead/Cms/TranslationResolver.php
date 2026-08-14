<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;

class TranslationResolver
{
    /** @var BaseConnection<mixed, mixed> */
    private BaseConnection $db;

    private FileUrlResolver $fileUrlResolver;

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(FileUrlResolver $fileUrlResolver, BaseConnection $db)
    {
        $this->fileUrlResolver = $fileUrlResolver;
        $this->db = $db;
    }

    /**
     * Resolve translations for a resource.
     *
     * @param string $resourceType Type of resource (e.g. 'setting', 'page')
     * @param int $id The resource ID
     * @param string $langCode The target language code (e.g. 'es')
     * @return array<string, mixed> The translated payload with 'is_fallback' flag
     */
    public function resolve(string $resourceType, int $id, string $langCode): array
    {
        $config = TranslationResourceCatalog::definition($resourceType);
        if ($config === null) {
            throw new \InvalidArgumentException("Unsupported resource type: {$resourceType}");
        }

        // 1. Resolve target language
        $targetLang = $this->getLanguageByCode($langCode);

        // If target language is active and exists, try to get translation
        if ($targetLang && (int) $targetLang['is_active'] === 1) {
            $translation = $this->getTranslation((string) $config['table'], (string) $config['fk'], $id, (int) $targetLang['id']);
            if ($translation) {
                return array_merge($this->normalizePayload($resourceType, $translation, $config), ['is_fallback' => false]);
            }
        }

        // 2. Fallback to default language
        $defaultLang = $this->getDefaultLanguage();
        if ($defaultLang) {
            $translation = $this->getTranslation((string) $config['table'], (string) $config['fk'], $id, (int) $defaultLang['id']);
            if ($translation) {
                return array_merge($this->normalizePayload($resourceType, $translation, $config), ['is_fallback' => true]);
            }
        }

        // 3. Fallback to fallback language of the target language if defined
        if ($targetLang && isset($targetLang['fallback_language_id'])) {
            $translation = $this->getTranslation((string) $config['table'], (string) $config['fk'], $id, (int) $targetLang['fallback_language_id']);
            if ($translation) {
                return array_merge($this->normalizePayload($resourceType, $translation, $config), ['is_fallback' => true]);
            }
        }

        // 4. Return default empty structure
        $emptyPayload = [];
        foreach (array_keys($config['fields']) as $field) {
            $emptyPayload[$field] = null;
        }

        return array_merge($this->normalizePayload($resourceType, $emptyPayload, $config), ['is_fallback' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLanguageByCode(string $code): ?array
    {
        $result = $this->db->table('cms_languages')
            ->where('code', $code)
            ->get();

        return $result instanceof \CodeIgniter\Database\ResultInterface ? $result->getRowArray() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDefaultLanguage(): ?array
    {
        $result = $this->db->table('cms_languages')
            ->where('is_default', 1)
            ->where('is_active', 1)
            ->get();

        return $result instanceof \CodeIgniter\Database\ResultInterface ? $result->getRowArray() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getTranslation(string $table, string $fkColumn, int $resourceId, int $languageId): ?array
    {
        $result = $this->db->table($table)
            ->where($fkColumn, $resourceId)
            ->where('language_id', $languageId)
            ->get();

        return $result instanceof \CodeIgniter\Database\ResultInterface ? $result->getRowArray() : null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function sanitizeFields(array $data, array $fields): array
    {
        $sanitized = [];
        foreach ($fields as $field) {
            $sanitized[$field] = $data[$field] ?? null;
        }
        return $sanitized;
    }

    /**
     * @param array<string, mixed> $translation
     * @param array{table: string, fk: string, fields: array<string, array{required: bool}>} $config
     * @return array<string, mixed>
     */
    private function normalizePayload(string $resourceType, array $translation, array $config): array
    {
        $payload = $this->sanitizeFields($translation, array_keys($config['fields']));

        if ($resourceType === 'page') {
            $payload = $this->fileUrlResolver->normalizePageTranslation($payload);
        }

        return $payload;
    }
}
