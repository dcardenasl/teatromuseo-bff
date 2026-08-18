<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\PublicRead;

use App\PublicRead\Cms\PreviewToken;
use App\PublicRead\Cms\PublicReadEntryRequestDTO;
use App\PublicRead\Cms\PublicReadPageRequestDTO;
use App\PublicRead\Cms\PublicReadPageShowRequestDTO;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;
use Throwable;

/** Direct, read-only CMS public-read controller. */
final class CmsPublicReadController extends PublicReadSupport
{
    public function navigation(string $locale): ResponseInterface
    {
        return $this->result(Services::publicReadCms()->navigation->show($locale));
    }

    public function settings(string $locale): ResponseInterface
    {
        return $this->result(Services::publicReadCms()->settings->show($locale));
    }

    public function languages(): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCmsLanguages()->list());
        } catch (Throwable $exception) {
            return $this->failure('en', $exception);
        }
    }

    public function pages(string $locale): ResponseInterface
    {
        try {
            $request = Services::requestDtoFactory()->make(
                PublicReadPageRequestDTO::class,
                $this->query(['locale' => $locale]),
            );

            return $this->result(Services::publicReadCms()->pages->index($request, $this->fields([], [])));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function page(string $locale, string $path): ResponseInterface
    {
        try {
            return $this->result(Services::publicReadCms()->pages->show($locale, $path, $this->fields([], []), $this->verifiedPagePreview($locale, $path)));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function pageByType(string $locale, string $type): ResponseInterface
    {
        try {
            return $this->result(Services::publicReadCms()->pages->byType($locale, $type));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function entries(string $locale, string $collection): ResponseInterface
    {
        try {
            $request = Services::requestDtoFactory()->make(
                PublicReadEntryRequestDTO::class,
                $this->query(['locale' => $locale, 'collection' => $collection]),
            );

            return $this->result(Services::publicReadCms()->entries->index($request, $request->fields));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function entry(string $locale, string $collection, string $slug): ResponseInterface
    {
        try {
            return $this->result(Services::publicReadCms()->entries->show($locale, $collection, $slug, $this->fields([], [])));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function collections(string $locale): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCms()->collections->list($locale));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function sitemap(string $locale): ResponseInterface
    {
        try {
            return $this->result(Services::publicReadCms()->sitemap->show($locale));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function categories(string $locale, string $collectionKey): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCms()->categories->list($locale, $collectionKey));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function tags(string $locale, string $collectionKey): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCms()->tags->list($locale, $collectionKey));
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function form(string $locale, string $formKey): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCms()->forms->show($locale, $formKey));
        } catch (NotFoundException $exception) {
            return $this->failure($locale, $exception, 404);
        } catch (Throwable $exception) {
            return $this->failure($locale, $exception);
        }
    }

    public function redirect(string $path): ResponseInterface
    {
        try {
            return $this->data(Services::publicReadCms()->redirects->resolve([$path]));
        } catch (NotFoundException $exception) {
            return $this->failure('en', $exception, 404);
        } catch (Throwable $exception) {
            return $this->failure('en', $exception);
        }
    }

    private function verifiedPagePreview(string $locale, string $path): bool
    {
        $request = Services::requestDtoFactory()->make(
            PublicReadPageShowRequestDTO::class,
            $this->query([]),
        );

        return $request->previewRequested
            && PreviewToken::verify(
                'page',
                strtolower($locale) . ':' . trim($path, '/'),
                $request->previewExpires,
                $request->previewSig,
            );
    }
}
