<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Set-based public settings reader with one batch media resolution. */
final class PublicReadSettingsReader
{
    private const FALLBACK_LOCALE = 'es';

    /** @param BaseConnection<mixed,mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FileUrlResolver $fileUrlResolver,
        private readonly string $fallbackLocale = self::FALLBACK_LOCALE,
    ) {
    }

    public function show(string $locale): ApiResult
    {
        $languageQuery = $this->db->table('cms_languages')->select('id, code, is_default')->where('is_active', 1)->get();
        $languages = $languageQuery !== false ? $languageQuery->getResultArray() : [];
        $idsByCode = [];
        $default = strtolower($this->fallbackLocale);
        foreach ($languages as $language) {
            $code = strtolower((string) $language['code']);
            $idsByCode[$code] = (int) $language['id'];
            if ((int) $language['is_default'] === 1) {
                $default = $code;
            }
        }
        $languageIds = array_values(array_unique(array_filter([$idsByCode[$locale] ?? null, $idsByCode[$default] ?? null])));

        $settingQuery = $this->db->table('cms_settings')
            ->select('id, setting_key, setting_value, setting_meta, setting_type, is_translatable, updated_at')
            ->where('is_public', 1)->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get();
        $settings = $settingQuery !== false ? $settingQuery->getResultArray() : [];
        $settingIds = array_map(static fn (array $row): int => (int) $row['id'], $settings);
        $translations = [];
        if ($settingIds !== [] && $languageIds !== []) {
            $translationQuery = $this->db->table('cms_setting_translations')
                ->select('setting_id, language_id, setting_value')
                ->whereIn('setting_id', $settingIds)->whereIn('language_id', $languageIds)->get();
            $translationRows = $translationQuery !== false ? $translationQuery->getResultArray() : [];
            foreach ($translationRows as $row) {
                $code = array_search((int) $row['language_id'], $idsByCode, true);
                if (is_string($code)) {
                    $translations[(int) $row['setting_id']][$code] = $row['setting_value'];
                }
            }
        }

        $fileIds = [];
        foreach ($settings as $setting) {
            if ((string) $setting['setting_type'] === 'file_id') {
                $id = (int) ($setting['setting_value'] ?? 0);
                if ($id > 0) {
                    $fileIds[] = $id;
                }
            }
        }
        $media = $this->fileUrlResolver->resolveManyMeta(array_values(array_unique($fileIds)), 'public');
        $data = [];
        foreach ($settings as $setting) {
            $id = (int) $setting['id'];
            $rawValue = ((int) $setting['is_translatable'] === 1)
                ? ($translations[$id][$locale] ?? $translations[$id][$default] ?? $setting['setting_value'])
                : $setting['setting_value'];
            $data[(string) $setting['setting_key']] = $this->value($setting, $rawValue, $media);
        }

        $updated = '';
        $maxId = 0;
        foreach ($settings as $setting) {
            $updated = max($updated, (string) ($setting['updated_at'] ?? ''));
            $maxId = max($maxId, (int) $setting['id']);
        }
        return PublicReadEnvelope::success(
            $locale,
            $data,
            'cms-settings:' . ($updated !== '' ? $updated : 'empty') . ':' . $maxId,
            domain: 'cms',
            meta: ['fields' => [], 'query' => ['resource' => 'settings']],
        );
    }

    /**
     * @param array<string, mixed> $setting
     * @param array<int, array<string, mixed>> $media
     */
    private function value(array $setting, mixed $raw, array $media): mixed
    {
        $type = (string) $setting['setting_type'];
        if ($type === 'file_id') {
            $id = (int) $raw;
            return ['file_id' => $id, 'url' => $media[$id]['url'] ?? null, 'variants' => $media[$id]['variants'] ?? null];
        }
        if ($type === 'int') {
            return (int) $raw;
        }
        if ($type === 'bool') {
            return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
        }
        if ($type === 'json') {
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return $raw;
    }
}
