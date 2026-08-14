<?php

declare(strict_types=1);

namespace App\PublicRead\Event;

use CodeIgniter\Validation\ValidationInterface;
use dcardenasl\Ci4ApiCore\Dto\BaseRequestDTO;
use dcardenasl\Ci4ApiCore\Exceptions\ValidationException;

/** Explicit request contract for the public events listing. */
readonly class PublicReadEventRequestDTO extends BaseRequestDTO
{
    public function __construct(array $data, ?ValidationInterface $validation = null)
    {
        parent::__construct($data, $validation);

        $this->locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $this->page = max(1, (int) ($data['page'] ?? 1));
        $this->perPage = min(100, max(1, (int) ($data['per_page'] ?? 20)));
        $this->search = trim((string) ($data['search'] ?? ''));
        $this->eventType = trim((string) ($data['event_type'] ?? ''));
        $this->from = trim((string) ($data['from'] ?? ''));
        $this->to = trim((string) ($data['to'] ?? ''));
        $this->sort = trim((string) ($data['sort'] ?? 'agenda'));
    }

    public string $locale;
    public int $page;
    public int $perPage;
    public string $search;
    public string $eventType;
    public string $from;
    public string $to;
    public string $sort;

    public function rules(): array
    {
        return [
            'locale' => 'required|regex_match[/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i]',
            'page' => 'permit_empty|is_natural_no_zero',
            'per_page' => 'permit_empty|is_natural_no_zero|less_than[101]',
            'search' => 'permit_empty|string|max_length[120]',
            'event_type' => 'permit_empty|string|max_length[80]',
            'from' => 'permit_empty|valid_date[Y-m-d H:i:s]',
            'to' => 'permit_empty|valid_date[Y-m-d H:i:s]',
            'sort' => 'permit_empty|in_list[agenda,latest,id,title]',
        ];
    }

    protected function map(array $data): void
    {
    }

    /** @param array<string, mixed> $data */
    protected function validate(array $data): void
    {
        parent::validate($data);

        $from = trim((string) ($data['from'] ?? ''));
        $to = trim((string) ($data['to'] ?? ''));
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new ValidationException(
                lang('Api.validationFailed'),
                ['from' => [lang('Events.invalid_public_range')]],
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'search' => $this->search,
            'event_type' => $this->eventType,
            'from' => $this->from,
            'to' => $this->to,
            'sort' => $this->sort,
        ];
    }
}
