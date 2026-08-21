<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;

/** Direct read model for the public form definition contract. */
final class PublicReadFormReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db, private readonly string $fallbackLocale = 'es')
    {
    }

    /** @return array<string, mixed> */
    public function show(string $locale, string $formKey): array
    {
        $query = $this->db->table('cms_forms')
            ->select('id, form_key, has_captcha, autoreply_enabled, autoreply_email_field')
            ->where('form_key', trim($formKey))
            ->where('is_active', 1)
            ->get();
        $form = $query !== false ? $query->getRowArray() : null;
        if (! is_array($form)) {
            throw new NotFoundException('Public form not found.');
        }
        $languages = (new PublicLocaleResolver($this->db, $this->fallbackLocale))->all();
        $requestedId = isset($languages['by_code'][$locale]) ? (int) $languages['by_code'][$locale]['id'] : null;
        $defaultId = isset($languages['by_code'][$languages['default']]) ? (int) $languages['by_code'][$languages['default']]['id'] : null;
        $translation = $this->translation(
            'cms_form_translations',
            'form_id',
            (int) $form['id'],
            $requestedId,
            $defaultId,
            'id, form_id, language_id, name, description, submit_label, success_message, error_message',
        );

        $fieldQuery = $this->db->table('cms_form_fields')
            ->select('id, field_key, field_type, options, display_order, is_required')
            ->where('form_id', (int) $form['id'])
            ->where('is_active', 1)
            ->orderBy('display_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        $fields = $fieldQuery !== false ? $fieldQuery->getResultArray() : [];
        $fieldTranslations = $this->fieldTranslations(
            array_values(array_map(static fn (array $field): int => (int) $field['id'], $fields)),
            $requestedId,
            $defaultId,
        );
        $publicFields = [];
        foreach ($fields as $field) {
            $fieldTranslation = $fieldTranslations[(int) $field['id']] ?? [];
            $optionValues = $this->jsonList($field['options'] ?? null);
            $optionLabels = $this->jsonMap($fieldTranslation['option_labels'] ?? null);
            $options = array_map(static fn (string $value): array => ['value' => $value, 'label' => (string) ($optionLabels[$value] ?? $value)], $optionValues);
            $publicFields[] = [
                'field_key' => (string) $field['field_key'],
                'field_type' => (string) $field['field_type'],
                'options' => $options,
                'is_required' => (bool) $field['is_required'],
                'display_order' => (int) $field['display_order'],
                'label' => (string) ($fieldTranslation['label'] ?? $field['field_key']),
                'placeholder' => $fieldTranslation['placeholder'] ?? null,
                'help_text' => $fieldTranslation['help_text'] ?? null,
                'error_required' => $fieldTranslation['error_required'] ?? null,
                'error_invalid' => $fieldTranslation['error_invalid'] ?? null,
            ];
        }

        return [
            'form_key' => (string) $form['form_key'],
            'has_captcha' => (bool) $form['has_captcha'],
            'autoreply_enabled' => (bool) $form['autoreply_enabled'],
            'autoreply_email_field' => $form['autoreply_email_field'] !== null && $form['autoreply_email_field'] !== '' ? (string) $form['autoreply_email_field'] : null,
            'name' => (string) ($translation['name'] ?? ''),
            'description' => $translation['description'] ?? null,
            'submit_label' => (string) ($translation['submit_label'] ?? 'Enviar'),
            'success_message' => $translation['success_message'] ?? null,
            'error_message' => $translation['error_message'] ?? null,
            'fields' => $publicFields,
        ];
    }

    /** @return array<string, mixed> */
    private function translation(
        string $table,
        string $foreignKey,
        int $id,
        ?int $requestedId,
        ?int $defaultId,
        string $columns = '*',
    ): array {
        $builder = $this->db->table($table)->select($columns)->where($foreignKey, $id);
        if ($requestedId !== null) {
            $result = $builder->where('language_id', $requestedId)->get();
            $row = $result !== false ? $result->getRowArray() : null;
            if (is_array($row)) {
                return $row;
            }
        }
        if ($defaultId !== null) {
            $result = $this->db->table($table)
                ->select($columns)
                ->where($foreignKey, $id)
                ->where('language_id', $defaultId)
                ->get();
            $row = $result !== false ? $result->getRowArray() : null;
            if (is_array($row)) {
                return $row;
            }
        }
        $result = $this->db->table($table)
            ->select($columns)
            ->where($foreignKey, $id)
            ->orderBy('id', 'ASC')
            ->get();
        $row = $result !== false ? $result->getRowArray() : null;

        return is_array($row) ? $row : [];
    }

    /**
     * Resolve every field translation with one bounded query.
     *
     * The previous implementation performed up to three queries per field
     * (requested language, default language, then first available row). A
     * public form is a bounded projection, so all its field translations can
     * be loaded together and the same preference order applied in memory.
     *
     * @param list<int> $fieldIds
     * @return array<int, array<string, mixed>>
     */
    private function fieldTranslations(array $fieldIds, ?int $requestedId, ?int $defaultId): array
    {
        if ($fieldIds === []) {
            return [];
        }

        $query = $this->db->table('cms_form_field_translations')
            ->select('id, form_field_id, language_id, label, placeholder, help_text, option_labels, error_required, error_invalid')
            ->whereIn('form_field_id', $fieldIds)
            ->orderBy('id', 'ASC')
            ->get();
        $rows = $query !== false ? $query->getResultArray() : [];

        $byFieldAndLanguage = [];
        $firstByField = [];
        foreach ($rows as $row) {
            $fieldId = (int) $row['form_field_id'];
            $languageId = (int) $row['language_id'];
            $byFieldAndLanguage[$fieldId][$languageId] = $row;
            $firstByField[$fieldId] ??= $row;
        }

        $result = [];
        foreach ($fieldIds as $fieldId) {
            $translation = [];
            if ($requestedId !== null) {
                $translation = $byFieldAndLanguage[$fieldId][$requestedId] ?? [];
            }
            if ($translation === [] && $defaultId !== null) {
                $translation = $byFieldAndLanguage[$fieldId][$defaultId] ?? [];
            }
            $result[$fieldId] = $translation !== [] ? $translation : ($firstByField[$fieldId] ?? []);
        }

        return $result;
    }

    /** @return list<string> */
    private function jsonList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @return array<string, string> */
    private function jsonMap(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_map('strval', $raw);
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
