<?php

declare(strict_types=1);

namespace App\Libraries\Exceptions;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use dcardenasl\Ci4ApiCore\Exceptions\BaseExceptionHandler;
use Throwable;

/**
 * Overrides the shared package's handler because
 * {@see BaseExceptionHandler::handle()} returns `$exception->getMessage()`
 * unconditionally, with no `ENVIRONMENT` gate. That leaks internal details
 * (SQL fragments, file paths) to the client in production for any
 * `Throwable` that escapes a controller's own try/catch. This mirrors the
 * sanitization rule `dcardenasl\Ci4ApiCore\Support\ExceptionFormatter`
 * already applies to exceptions caught inside `BaseProxyController`.
 */
class AppExceptionHandler extends BaseExceptionHandler
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        $message = ENVIRONMENT === 'development'
            ? $exception::class . ': ' . $exception->getMessage()
            : lang('Api.serverError');

        $response
            ->setStatusCode($statusCode)
            ->setContentType('application/json')
            ->setBody((string) json_encode([
                'success' => false,
                'message' => $message,
            ], JSON_THROW_ON_ERROR))
            ->send();

        if (ENVIRONMENT !== 'testing') {
            // @codeCoverageIgnoreStart
            exit($exitCode);
            // @codeCoverageIgnoreEnd
        }
    }
}
