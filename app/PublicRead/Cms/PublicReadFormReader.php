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
        $query = $this->db->table('cms_forms')->select('*')->where('form_key', trim($formKey))->where('is_active', 1)->get();
        $form = $query !== false ? $query->getRowArray() : null;
        if (! is_array($form)) {
            throw new NotFoundException('Public form not found.');
        }
        $languages = (new PublicLocaleResolver($this->db, $this->fallbackLocale))->all();
        $requestedId = isset($languages['by_code'][$locale]) ? (int) $languages['by_code'][$locale]['id'] : null;
        $defaultId = isset($languages['by_code'][$languages['default']]) ? (int) $languages['by_code'][$languages['default']]['id'] : null;
        $translation = $this->translation('cms_form_translations', 'form_id', (int) $form['id'], $requestedId, $defaultId);

        $fieldQuery = $this->db->table('cms_form_fields')->select('*')->where('form_id', (int) $form['id'])->where('is_active', 1)->orderBy('display_order', 'ASC')->orderBy('id', 'ASC')->get();
        $fields = $fieldQuery !== false ? $fieldQuery->getResultArray() : [];
        $publicFields = [];
        foreach ($fields as $field) {
            $fieldTranslation = $this->translation('cms_form_field_translations', 'form_field_id', (int) $field['id'], $requestedId, $defaultId);
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
    private function translation(string $table, string $foreignKey, int $id, ?int $requestedId, ?int $defaultId): array
    {
        $builder = $this->db->table($table)->where($foreignKey, $id);
        if ($requestedId !== null) {
            $result = $builder->where('language_id', $requestedId)->get();
            $row = $result !== false ? $result->getRowArray() : null;
            if (is_array($row)) {
                return $row;
            }
        }
        if ($defaultId !== null) {
            $result = $this->db->table($table)->where($foreignKey, $id)->where('language_id', $defaultId)->get();
            $row = $result !== false ? $result->getRowArray() : null;
            if (is_array($row)) {
                return $row;
            }
        }
        $result = $this->db->table($table)->where($foreignKey, $id)->orderBy('id', 'ASC')->get();
        $row = $result !== false ? $result->getRowArray() : null;

        return is_array($row) ? $row : [];
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
