<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\PublicRead;

use App\PublicRead\Catalog\PublicReadCollectionItemRequestDTO;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;
use Throwable;

/** Direct, read-only Catalog public-read controller. */
final class CatalogPublicReadController extends PublicReadSupport
{
    /** @var list<string> */
    private const LIST_FIELDS = [
        'id', 'name', 'category_id', 'inventory_code', 'status', 'summary', 'cover_file_id',
        'cover_image', 'slug', 'localized', 'category', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    private const DETAIL_FIELDS = [
        'id', 'name', 'category_id', 'inventory_code', 'status', 'summary', 'curiosidad', 'contenido',
        'origin', 'period', 'creator', 'ubicacion', 'materials', 'cover_file_id', 'cover_image',
        'gallery_file_ids', 'gallery_images', 'collection_number', 'collection_group',
        'physical_description', 'dimensions', 'ingress_type', 'donated_by', 'tags', 'links',
        'company_history', 'localized', 'translations', 'slug', 'slugs', 'category', 'techniques',
        'created_at', 'updated_at',
    ];

    public function index(string $locale): ResponseInterface
    {
        try {
            $request = Services::requestDtoFactory()->make(
                PublicReadCollectionItemRequestDTO::class,
                $this->query(['locale' => $locale]),
            );

            return $this->result(Services::publicReadCatalog()->index($request, $this->fields(self::LIST_FIELDS, self::LIST_FIELDS)));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function item(string $locale, string $idOrSlug): ResponseInterface
    {
        try {
            return $this->result(Services::publicReadCatalog()->show($locale, $idOrSlug, $this->fields(self::DETAIL_FIELDS, self::DETAIL_FIELDS)));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function categories(): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCatalogFacets()->categories($this->withCounts()));
        } catch (Throwable $exception) {
            return $this->failure('en', $exception);
        }
    }

    public function techniques(): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCatalogFacets()->techniques($this->withCounts()));
        } catch (Throwable $exception) {
            return $this->failure('en', $exception);
        }
    }

    public function technique(string $idOrSlug): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCatalogFacets()->technique($idOrSlug));
        } catch (NotFoundException $exception) {
            return $this->failure('en', $exception, 404);
        } catch (Throwable $exception) {
            return $this->failure('en', $exception);
        }
    }

    private function withCounts(): bool
    {
        $raw = $this->request->getGet('with_counts');

        return is_string($raw) && in_array(strtolower(trim($raw)), ['1', 'true'], true);
    }
}
