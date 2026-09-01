<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use App\PublicRead\Catalog\PublicReadCollectionItemReader;
use App\PublicRead\Catalog\PublicReadCollectionItemRequestDTO;
use App\PublicRead\CatalogFacetReader;
use App\PublicRead\Cms\PublicReadEntryRequestDTO;
use App\PublicRead\CmsPublicReadBundle;
use App\PublicRead\Event\PublicReadEventReader;
use App\PublicRead\Event\PublicReadEventRequestDTO;
use dcardenasl\Ci4ApiCore\Support\ApiResult;
use dcardenasl\Ci4ApiCore\Support\RequestDtoFactory;

/** Adapts the BFF's direct public readers to the block composition port. */
final readonly class PublicReadBlockTreeSource implements BlockTreeSourceInterface
{
    public function __construct(
        private CmsPublicReadBundle $cms,
        private PublicReadCollectionItemReader $catalog,
        private CatalogFacetReader $catalogFacets,
        private PublicReadEventReader $eventsReader,
        private \App\PublicRead\EventTypeReader $eventTypesReader,
        private RequestDtoFactory $requestDtos,
    ) {
    }

    public function collections(string $locale): array
    {
        return $this->cms->collections->list($locale);
    }

    public function cmsEntries(string $locale, array $query, bool $preview = false): ApiResult
    {
        $query['locale'] = $locale;
        $dto = $this->requestDtos->make(PublicReadEntryRequestDTO::class, $query);

        return $this->cms->entries->index($dto, $this->fields($query), $preview);
    }

    public function cmsCategories(string $locale, string $collectionKey): array
    {
        return $this->cms->categories->list($locale, $collectionKey);
    }

    public function cmsTags(string $locale, string $collectionKey): array
    {
        return $this->cms->tags->list($locale, $collectionKey);
    }

    public function form(string $locale, string $formKey): array
    {
        return $this->cms->forms->show($locale, $formKey);
    }

    public function catalogItems(string $locale, array $query): ApiResult
    {
        $query['locale'] = $locale;
        $dto = $this->requestDtos->make(PublicReadCollectionItemRequestDTO::class, $query);

        return $this->catalog->index($dto, $this->fields($query));
    }

    public function catalogCategories(): array
    {
        return $this->catalogFacets->categories();
    }

    public function catalogItem(string $locale, string $idOrSlug, array $fields): ApiResult
    {
        return $this->catalog->show($locale, $idOrSlug, $fields);
    }

    public function events(string $locale, array $query): ApiResult
    {
        $query['locale'] = $locale;
        $dto = $this->requestDtos->make(PublicReadEventRequestDTO::class, $query);

        return $this->eventsReader->index($dto, $this->fields($query));
    }

    public function event(string $locale, string $idOrSlug, array $fields): ApiResult
    {
        return $this->eventsReader->show($locale, $idOrSlug, $fields);
    }

    public function eventTypes(): array
    {
        return $this->eventTypesReader->list();
    }

    /** @param array<string, mixed> $query
     *  @return list<string>
     */
    private function fields(array $query): array
    {
        $raw = $query['fields'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $field): string => trim((string) $field),
            is_array($raw) ? $raw : [],
        ), static fn (string $field): bool => $field !== ''));
    }
}
