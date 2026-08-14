<?php

declare(strict_types=1);

namespace App\PublicRead\Catalog;

use CodeIgniter\Validation\ValidationInterface;
use dcardenasl\Ci4ApiCore\Dto\BaseRequestDTO;

/** Request contract for the canonical collection-item listing. */
readonly class PublicReadCollectionItemRequestDTO extends BaseRequestDTO
{
    private const MAX_PAGE = 10_000;

    private const MAX_PER_PAGE = 100;

    public function __construct(array $data, ?ValidationInterface $validation = null)
    {
        parent::__construct($data, $validation);

        $this->locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $this->page = min(self::MAX_PAGE, max(1, (int) ($data['page'] ?? 1)));
        $this->perPage = min(self::MAX_PER_PAGE, max(1, (int) ($data['per_page'] ?? 20)));
        $this->search = trim((string) ($data['search'] ?? ''));
        $this->categorySlug = ($data['category'] ?? '') !== '' ? trim((string) $data['category']) : null;
        $this->categoryId = isset($data['category_id']) && $data['category_id'] !== ''
            ? (int) $data['category_id']
            : null;
        $this->technique = ($data['technique'] ?? '') !== '' ? trim((string) $data['technique']) : null;
        $this->techniqueId = isset($data['technique_id']) && $data['technique_id'] !== ''
            ? (int) $data['technique_id']
            : null;
        $this->sort = trim((string) ($data['sort'] ?? 'name'));
    }

    public string $locale;
    public int $page;
    public int $perPage;
    public string $search;
    public ?string $categorySlug;
    public ?int $categoryId;
    public ?string $technique;
    public ?int $techniqueId;
    public string $sort;

    public function rules(): array
    {
        return [
            'locale' => 'required|regex_match[/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i]',
            'page' => 'permit_empty|is_natural_no_zero|less_than[10001]',
            'per_page' => 'permit_empty|is_natural_no_zero|less_than[101]',
            'search' => 'permit_empty|string|max_length[120]',
            'category' => 'permit_empty|string|max_length[120]',
            'category_id' => 'permit_empty|is_natural_no_zero',
            'technique' => 'permit_empty|string|max_length[500]',
            'technique_id' => 'permit_empty|is_natural_no_zero',
            'sort' => 'permit_empty|in_list[name,created_at,id]',
        ];
    }

    protected function map(array $data): void
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'search' => $this->search,
            'category' => $this->categorySlug,
            'category_id' => $this->categoryId,
            'technique' => $this->technique,
            'technique_id' => $this->techniqueId,
            'sort' => $this->sort,
        ];
    }
}
