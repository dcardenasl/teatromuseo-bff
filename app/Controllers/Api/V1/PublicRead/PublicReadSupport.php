<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\PublicRead;

use App\Controllers\BaseProxyController;
use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\HTTP\ResponseInterface;
use dcardenasl\Ci4ApiCore\Exceptions\ValidationException;
use dcardenasl\Ci4ApiCore\Support\ApiResult;
use Throwable;

/** Shared response/error adapter for the three direct public-read controllers. */
abstract class PublicReadSupport extends BaseProxyController
{
    /**
     * @param list<string> $allowed
     * @param list<string> $default
     * @return list<string>
     */
    protected function fields(array $allowed, array $default): array
    {
        if ($allowed === []) {
            return [];
        }

        $rawValue = $this->request->getGet('fields');
        $raw = is_string($rawValue) ? trim($rawValue) : '';
        if ($raw === '') {
            return $default;
        }

        $requested = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $field): bool => $field !== '',
        ));

        $invalid = array_values(array_diff($requested, $allowed));
        if ($invalid !== []) {
            $field = $invalid[0];
            throw new ValidationException(
                "Field '{$field}' is not allowed. Allowed fields: " . implode(', ', $allowed),
                ['fields' => [$field], 'allowed' => $allowed],
            );
        }

        return array_values(array_unique($requested));
    }

    protected function result(ApiResult $result): ResponseInterface
    {
        return $this->response->setJSON($result->body)->setStatusCode($result->status);
    }

    /** @param array<int|string, mixed> $data */
    protected function data(array $data): ResponseInterface
    {
        return $this->response
            ->setJSON(['data' => $data, 'meta' => ['generated_at' => gmdate(DATE_ATOM)]])
            ->setStatusCode(200);
    }

    protected function failure(string $locale, Throwable $exception, int $status = 503): ResponseInterface
    {
        if ($exception instanceof ValidationException) {
            return $this->response
                ->setJSON($exception->toArray())
                ->setStatusCode($exception->getStatusCode());
        }

        log_message('error', 'BFF public-read failure: {message}', ['message' => $exception->getMessage()]);
        $result = PublicReadEnvelope::unavailable(
            $locale,
            'Public read temporarily unavailable.',
            'bff:unavailable',
            'bff',
        );
        if ($status !== 503) {
            $result = new ApiResult($result->body, $status);
        }

        return $this->result($result);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function query(array $extra): array
    {
        $query = $this->request->getGet();

        return is_array($query) ? array_merge($query, $extra) : $extra;
    }
}
