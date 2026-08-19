<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\PublicRead;

use App\PublicRead\Event\PublicReadEventRequestDTO;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/** Direct, read-only Event public-read controller. */
final class EventPublicReadController extends PublicReadSupport
{
    /** @var list<string> */
    private const LIST_FIELDS = [
        'id', 'uuid', 'title', 'event_type', 'slug', 'cover_file_id', 'cover_image', 'localized',
        'next_occurrence_at', 'last_occurrence_at', 'status',
    ];

    /** @var list<string> */
    private const DETAIL_FIELDS = [
        'id', 'uuid', 'title', 'event_type', 'description', 'slug', 'slugs', 'cover_file_id',
        'cover_image', 'gallery_file_ids', 'gallery_images', 'translations', 'localized',
        'occurrences', 'status', 'created_at', 'updated_at',
    ];

    public function index(string $locale): ResponseInterface
    {
        try {
            $request = Services::requestDtoFactory()->make(
                PublicReadEventRequestDTO::class,
                $this->query(['locale' => $locale]),
            );

            return $this->result(Services::publicReadEvents()->index($request, $this->fields(self::LIST_FIELDS, self::LIST_FIELDS)));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function item(string $locale, string $idOrSlug): ResponseInterface
    {
        try {
            return $this->result(Services::publicReadEvents()->show($locale, $idOrSlug, $this->fields(self::DETAIL_FIELDS, self::DETAIL_FIELDS)));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function types(): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadEventTypes()->list());
        } catch (Throwable $exception) {
            return $this->failure('en', $exception);
        }
    }
}
