<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Exceptions;

use App\Libraries\Exceptions\AppExceptionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

/**
 * Regression for the exception-message leak: the shared package's
 * `BaseExceptionHandler::handle()` returns `$exception->getMessage()`
 * verbatim with no `ENVIRONMENT` gate, unlike `ExceptionFormatter` (used by
 * `BaseProxyController`). Any `Throwable` that escapes a controller's own
 * try/catch reaches this handler, so it must sanitize the message itself
 * outside `development` — this repo's tests always run with
 * `ENVIRONMENT === 'testing'` (see `tests/bootstrap.php`), so every
 * assertion here exercises the non-development branch.
 */
final class AppExceptionHandlerTest extends CIUnitTestCase
{
    public function testMessageIsSanitizedOutsideDevelopment(): void
    {
        $exception = new RuntimeException('SQLSTATE[42S02]: table `cms_languages` not found at /var/www/app/Foo.php:123');
        $response  = Services::response(null, false);

        (new AppExceptionHandler())->handle($exception, Services::request(false), $response, 500, 1);

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertStringNotContainsString('cms_languages', $body['message']);
        $this->assertStringNotContainsString('/var/www/app/Foo.php', $body['message']);
        $this->assertSame(lang('Api.serverError'), $body['message']);
    }

    public function testResponseIsAlwaysValidJson(): void
    {
        $exception = new RuntimeException('boom');
        $response  = Services::response(null, false);

        (new AppExceptionHandler())->handle($exception, Services::request(false), $response, 503, 1);

        $this->assertJson((string) $response->getBody());
    }
}
