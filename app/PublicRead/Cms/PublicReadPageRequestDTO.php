<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Validation\ValidationInterface;
use dcardenasl\Ci4ApiCore\Dto\BaseRequestDTO;

/** Request contract for the canonical CMS page collection. */
readonly class PublicReadPageRequestDTO extends BaseRequestDTO
{
    public function __construct(array $data, ?ValidationInterface $validation = null)
    {
        parent::__construct($data, $validation);
        $this->locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $this->page = max(1, (int) ($data['page'] ?? 1));
        $this->perPage = min(100, max(1, (int) ($data['per_page'] ?? 100)));
    }

    public string $locale;
    public int $page;
    public int $perPage;

    public function rules(): array
    {
        return [
            'locale' => 'required|regex_match[/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i]',
            'page' => 'permit_empty|is_natural_no_zero',
            'per_page' => 'permit_empty|is_natural_no_zero|less_than[101]',
        ];
    }

    protected function map(array $data): void
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['locale' => $this->locale, 'page' => $this->page, 'per_page' => $this->perPage];
    }
}
