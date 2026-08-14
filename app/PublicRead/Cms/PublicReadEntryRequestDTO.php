<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Validation\ValidationInterface;
use dcardenasl\Ci4ApiCore\Dto\BaseRequestDTO;

/** Bounded public entry listing request; filters are applied in SQL. */
readonly class PublicReadEntryRequestDTO extends BaseRequestDTO
{
    public string $locale;
    public string $collection;
    public int $page;
    public int $perPage;
    public ?string $category;
    public ?int $categoryId;
    public ?string $tag;
    public ?string $search;
    public string $orderBy;
    public string $orderDirection;
    /** @var list<string> */
    public array $fields;

    public function __construct(array $data, ?ValidationInterface $validation = null)
    {
        parent::__construct($data, $validation);
        $this->locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $this->collection = trim((string) ($data['collection'] ?? ''));
        $this->page = max(1, (int) ($data['page'] ?? 1));
        $this->perPage = min(100, max(1, (int) ($data['per_page'] ?? 20)));
        $this->category = ($data['category'] ?? '') !== '' ? trim((string) $data['category']) : null;
        $this->categoryId = ($data['category_id'] ?? '') !== '' ? (int) $data['category_id'] : null;
        $this->tag = ($data['tag'] ?? '') !== '' ? trim((string) $data['tag']) : null;
        $this->search = ($data['q'] ?? '') !== '' ? trim((string) $data['q']) : null;
        $orderBy = (string) ($data['order_by'] ?? 'sort_order');
        $this->orderBy = in_array($orderBy, ['sort_order', 'published_at', 'created_at', 'title'], true) ? $orderBy : 'sort_order';
        $this->orderDirection = strtoupper((string) ($data['order_direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $rawFields = is_string($data['fields'] ?? null) ? explode(',', (string) $data['fields']) : (array) ($data['fields'] ?? []);
        $this->fields = array_values(array_filter(array_map('trim', $rawFields), static fn (string $field): bool => $field !== ''));
    }

    public function rules(): array
    {
        return [
            'locale' => 'required|string|max_length[10]',
            'collection' => 'required|string|max_length[80]',
            'page' => 'permit_empty|is_natural_no_zero',
            'per_page' => 'permit_empty|is_natural_no_zero|less_than[101]',
            'category' => 'permit_empty|string|max_length[150]',
            'category_id' => 'permit_empty|is_natural_no_zero',
            'tag' => 'permit_empty|string|max_length[100]',
            'q' => 'permit_empty|string|max_length[255]',
            'order_by' => 'permit_empty|in_list[sort_order,published_at,created_at,title]',
            'order_direction' => 'permit_empty|in_list[ASC,DESC,asc,desc]',
            'fields' => 'permit_empty|string|max_length[2000]',
        ];
    }

    protected function map(array $data): void
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['locale' => $this->locale, 'collection' => $this->collection, 'page' => $this->page, 'per_page' => $this->perPage, 'category' => $this->category, 'category_id' => $this->categoryId, 'tag' => $this->tag, 'q' => $this->search, 'order_by' => $this->orderBy, 'order_direction' => $this->orderDirection, 'fields' => $this->fields];
    }
}
