<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Validation\ValidationInterface;
use dcardenasl\Ci4ApiCore\Dto\BaseRequestDTO;

/** Bounded public entry listing request; filters are applied in SQL. */
readonly class PublicReadEntryRequestDTO extends BaseRequestDTO
{
    private const LISTING_CONTENT_FIELDS = [
        'rich_text', 'image', 'hover_image', 'secondary_action', 'documents',
        'publication_date', 'date_fields', 'fields', 'video',
    ];

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
    public ?string $listingField;
    public ?string $filterBy;
    public ?string $filterValue;
    public string $filterOperator;
    public bool $includeListingContent;
    public string $rawInclude;
    /** @var list<string> */
    public array $listingContentFields;
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
        $rawSearch = $data['q'] ?? ($data['search'] ?? '');
        $this->search = $rawSearch !== '' ? trim((string) $rawSearch) : null;
        $rawOrderBy = (string) ($data['order_by'] ?? 'sort_order');
        $this->listingField = str_starts_with($rawOrderBy, 'field:') ? substr($rawOrderBy, 6) : null;
        $this->orderBy = $this->listingField !== null
            ? 'listing_field'
            : (in_array($rawOrderBy, ['sort_order', 'published_at', 'created_at', 'title'], true) ? $rawOrderBy : 'sort_order');
        $direction = strtoupper((string) ($data['order_direction'] ?? 'ASC'));
        $this->orderDirection = match ($direction) {
            'DESC' => 'DESC',
            'UPCOMING' => 'UPCOMING',
            default => 'ASC',
        };
        $rawFilterBy = trim((string) ($data['filter_by'] ?? ''));
        $this->filterBy = $rawFilterBy !== '' ? $rawFilterBy : null;
        $rawFilterValue = trim((string) ($data['filter_value'] ?? ''));
        $this->filterValue = $rawFilterValue !== '' ? $rawFilterValue : null;
        $rawFilterOperator = (string) ($data['filter_operator'] ?? 'equals');
        $this->filterOperator = in_array($rawFilterOperator, ['equals', 'contains'], true) ? $rawFilterOperator : 'equals';
        $this->rawInclude = trim((string) ($data['include'] ?? ''));
        [$this->includeListingContent, $this->listingContentFields] = $this->parseInclude($this->rawInclude);
        $rawFields = is_string($data['fields'] ?? null) ? explode(',', (string) $data['fields']) : (array) ($data['fields'] ?? []);
        $this->fields = array_values(array_filter(array_map('trim', $rawFields), static fn (string $field): bool => $field !== ''));
    }

    public function rules(): array
    {
        return [
            'locale' => 'required|regex_match[/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i]',
            'collection' => 'required|string|max_length[50]',
            'page' => 'permit_empty|is_natural_no_zero',
            'per_page' => 'permit_empty|is_natural_no_zero|less_than[101]',
            'category' => 'permit_empty|string|max_length[150]',
            'category_id' => 'permit_empty|is_natural_no_zero',
            'tag' => 'permit_empty|string|max_length[100]',
            'q' => 'permit_empty|string|max_length[255]',
            'search' => 'permit_empty|string|max_length[255]',
            'order_by' => 'permit_empty|regex_match[/^(published_at|sort_order|created_at|title|field:[a-z][a-z0-9_]{0,49}|field:(entry|block|taxonomy)\.[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)$/]',
            'order_direction' => 'permit_empty|in_list[asc,desc,upcoming,ASC,DESC,UPCOMING]',
            'fields' => 'permit_empty|string|max_length[2000]',
            // Mirrors the facet-key shape `order_by`'s `field:` branch already
            // allow-lists (see PublicReadEntryReader::classifyField(), which
            // both `filter_by` and the stripped `order_by=field:...` value
            // feed into) — an `entry.<column>` reference, a `taxonomy.`/
            // `block.` namespaced facet key, or a bare facet key. Previously
            // this only had a length cap; the shape allow-list here is
            // defense-in-depth (classifyField() already routes anything
            // outside these shapes into a safely-escaped facet lookup or a
            // deny-all filter), not the only thing preventing injection.
            'filter_by' => 'permit_empty|regex_match[/^([a-z][a-z0-9_]{0,49}|(entry|block|taxonomy)\.[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)$/]',
            'filter_value' => 'permit_empty|string|max_length[255]',
            'filter_operator' => 'permit_empty|in_list[equals,contains]',
            'include' => 'permit_empty|string|max_length[300]',
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
            'collection' => $this->collection,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'category' => $this->category,
            'category_id' => $this->categoryId,
            'tag' => $this->tag,
            'q' => $this->search,
            'order_by' => $this->listingField !== null ? 'field:' . $this->listingField : $this->orderBy,
            'order_direction' => $this->orderDirection,
            'filter_by' => $this->filterBy,
            'filter_value' => $this->filterValue,
            'filter_operator' => $this->filterOperator,
            'include' => $this->rawInclude !== '' ? $this->rawInclude : null,
            'fields' => $this->fields,
        ];
    }

    /** @return array{0: bool, 1: list<string>} */
    private function parseInclude(string $raw): array
    {
        $include = false;
        $fields = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if ($token === 'listing_content') {
                $include = true;
                continue;
            }
            if (! str_starts_with($token, 'listing_content.')) {
                continue;
            }
            $field = substr($token, strlen('listing_content.'));
            if (in_array($field, self::LISTING_CONTENT_FIELDS, true)) {
                $include = true;
                $fields[] = $field;
            }
        }

        return [$include, array_values(array_unique($fields))];
    }
}
