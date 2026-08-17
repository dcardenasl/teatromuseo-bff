<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\PublicRead;

use App\PublicRead\Cms\PreviewToken;
use App\Support\RequestTelemetry;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/** Thin public endpoint for one-request page delivery composition. */
final class PageResolutionController extends PublicReadSupport
{
    public function show(string $locale, string ...$routeSegments): ResponseInterface
    {
        $startedAt = hrtime(true);
        try {
            $route = trim(implode('/', $routeSegments), '/');
            $query = $this->query([]);
            $preview = $this->verifiedPreview($locale, $route, $query);
            $pageEnvelope = Services::publicReadPageEnvelope();
            $envelope = $pageEnvelope->resolve($locale, $route, $preview, $query);

            $response = $this->response
                ->setJSON($envelope)
                ->setStatusCode($pageEnvelope->httpStatus($envelope));
            RequestTelemetry::recordSource(
                'public-read.page-resolve',
                (hrtime(true) - $startedAt) / 1_000_000,
                $response->getStatusCode() >= 400 ? 'unavailable' : 'ok',
                $response->getStatusCode(),
            );

            return $response;
        } catch (Throwable $exception) {
            RequestTelemetry::recordSource(
                'public-read.page-resolve',
                (hrtime(true) - $startedAt) / 1_000_000,
                'unavailable',
                500,
            );
            return $this->failure($locale, $exception);
        }
    }

    /** @param array<string, mixed> $query */
    private function verifiedPreview(string $locale, string $route, array $query): bool
    {
        $requested = in_array(strtolower(trim((string) ($query['preview'] ?? ''))), ['1', 'true', 'yes'], true);
        if (! $requested) {
            return false;
        }

        $expires = isset($query['preview_expires']) && is_scalar($query['preview_expires'])
            ? (string) $query['preview_expires']
            : null;
        $signature = isset($query['preview_sig']) && is_scalar($query['preview_sig'])
            ? (string) $query['preview_sig']
            : null;

        return PreviewToken::verify(
            'page',
            strtolower(trim($locale)) . ':' . trim($route, '/'),
            $expires,
            $signature,
        );
    }
}
