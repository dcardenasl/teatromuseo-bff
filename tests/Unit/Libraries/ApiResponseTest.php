<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;

/**
 * ApiResponse Unit Tests
 *
 * Tests the centralized response builder.
 * These are pure unit tests - no database or external dependencies.
 */
class ApiResponseTest extends CIUnitTestCase
{
    public function testSuccessReturnsCorrectStructure(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $result = ApiResponse::success($data);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals($data, $result['data']);
        $this->assertArrayNotHasKey('message', $result);
    }

    public function testSuccessWithMessageIncludesMessage(): void
    {
        $result = ApiResponse::success(['id' => 1], 'Operation completed');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Operation completed', $result['message']);
    }

    public function testSuccessWithMetaIncludesMeta(): void
    {
        $meta = ['version' => '1.0'];
        $result = ApiResponse::success(['id' => 1], null, $meta);

        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals($meta, $result['meta']);
    }

    public function testErrorReturnsCorrectStructure(): void
    {
        $errors = ['email' => 'Invalid email'];
        $result = ApiResponse::error($errors, 'Validation failed');

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('Validation failed', $result['message']);
        $this->assertEquals($errors, $result['errors']);
    }

    public function testErrorWithStringConvertsToArray(): void
    {
        $result = ApiResponse::error('Something went wrong');

        $this->assertEquals(['general' => 'Something went wrong'], $result['errors']);
    }

    public function testErrorWithCodeIncludesCode(): void
    {
        $result = ApiResponse::error(['field' => 'error'], 'Error', 400);

        $this->assertArrayHasKey('code', $result);
        $this->assertEquals(400, $result['code']);
    }

    public function testPaginatedIncludesMetadata(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $result = ApiResponse::paginated($items, 50, 1, 10);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals($items, $result['data']);
        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals(50, $result['meta']['total']);
        $this->assertEquals(1, $result['meta']['page']);
        $this->assertEquals(10, $result['meta']['per_page']);
        $this->assertEquals(5, $result['meta']['last_page']);
        $this->assertEquals(1, $result['meta']['from']);
        $this->assertEquals(10, $result['meta']['to']);
    }

    public function testPaginatedCalculatesLastPageCorrectly(): void
    {
        // 25 items, 10 per page = 3 pages
        $result = ApiResponse::paginated([], 25, 1, 10);
        $this->assertEquals(3, $result['meta']['last_page']);

        // 100 items, 20 per page = 5 pages
        $result = ApiResponse::paginated([], 100, 1, 20);
        $this->assertEquals(5, $result['meta']['last_page']);
    }

    public function testCreatedIncludesMessage(): void
    {
        $data = ['id' => 1];
        $result = ApiResponse::created($data);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals($data, $result['data']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testDeletedReturnsSuccessWithoutData(): void
    {
        $result = ApiResponse::deleted();

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayNotHasKey('data', $result);
    }

    public function testValidationErrorReturns422Code(): void
    {
        $errors = ['email' => 'Required'];
        $result = ApiResponse::validationError($errors);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(422, $result['code']);
        $this->assertEquals($errors, $result['errors']);
    }

    public function testNotFoundReturns404Code(): void
    {
        $result = ApiResponse::notFound('User not found');

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(404, $result['code']);
        $this->assertEquals('User not found', $result['message']);
    }

    public function testUnauthorizedReturns401Code(): void
    {
        $result = ApiResponse::unauthorized();

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(401, $result['code']);
    }

    public function testForbiddenReturns403Code(): void
    {
        $result = ApiResponse::forbidden();

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(403, $result['code']);
    }

    public function testServerErrorReturns500Code(): void
    {
        $result = ApiResponse::serverError();

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(500, $result['code']);
    }

    // =====================================================================
    // RFC 7807 Problem Details (audit B7.4 / ADR-010)
    // =====================================================================

    public function testProblemDetailsDefaultsToAboutBlankWhenNoTypeProvided(): void
    {
        $result = ApiResponse::problemDetails([], 'Validation failed', 422);

        $this->assertSame('about:blank', $result['type']);
        $this->assertSame('Validation failed', $result['title']);
        $this->assertSame(422, $result['status']);
        $this->assertArrayNotHasKey('errors', $result, 'Empty error map must not be emitted.');
        $this->assertArrayNotHasKey('detail', $result);
    }

    public function testProblemDetailsEmitsFullShapeWhenAllFieldsProvided(): void
    {
        $result = ApiResponse::problemDetails(
            ['email' => 'required'],
            'Validation failed',
            422,
            'https://api.example.com/errors/validation-failed',
            '/api/v1/users',
            "The 'email' field is required."
        );

        $this->assertSame('https://api.example.com/errors/validation-failed', $result['type']);
        $this->assertSame('Validation failed', $result['title']);
        $this->assertSame(422, $result['status']);
        $this->assertSame("The 'email' field is required.", $result['detail']);
        $this->assertSame('/api/v1/users', $result['instance']);
        $this->assertSame(['email' => 'required'], $result['errors']);
    }

    public function testProblemDetailsPromotesStringErrorIntoDetailSlot(): void
    {
        $result = ApiResponse::problemDetails('Database unreachable', 'Service unavailable', 503);

        $this->assertSame('Database unreachable', $result['detail']);
        $this->assertArrayNotHasKey('errors', $result, 'String input has no field map to expose under errors.');
    }

    public function testNegotiateErrorReturnsLegacyShapeForJsonAccept(): void
    {
        $result = ApiResponse::negotiateError(
            'application/json',
            ['email' => 'required'],
            'Validation failed',
            422
        );

        $this->assertSame('application/json', $result['content_type']);
        $this->assertSame('error', $result['body']['status']);
        $this->assertSame('Validation failed', $result['body']['message']);
        $this->assertSame(['email' => 'required'], $result['body']['errors']);
    }

    public function testNegotiateErrorReturnsProblemJsonForProblemAccept(): void
    {
        $result = ApiResponse::negotiateError(
            'application/problem+json, application/json;q=0.9',
            ['email' => 'required'],
            'Validation failed',
            422,
            'https://example.com/errors/validation',
            '/api/v1/users'
        );

        $this->assertSame('application/problem+json', $result['content_type']);
        $this->assertSame('https://example.com/errors/validation', $result['body']['type']);
        $this->assertSame('Validation failed', $result['body']['title']);
        $this->assertSame(422, $result['body']['status']);
        $this->assertSame('/api/v1/users', $result['body']['instance']);
    }

    public function testClientPrefersProblemJsonHandlesMixedAcceptHeaders(): void
    {
        // Explicit mention of application/problem+json — switch.
        $this->assertTrue(ApiResponse::clientPrefersProblemJson('application/problem+json'));
        $this->assertTrue(ApiResponse::clientPrefersProblemJson('application/json, application/problem+json'));
        $this->assertTrue(ApiResponse::clientPrefersProblemJson('Application/Problem+JSON'));
        $this->assertTrue(ApiResponse::clientPrefersProblemJson('text/html;q=0.5, application/problem+json;q=1.0'));

        // No mention — stay on default JSON.
        $this->assertFalse(ApiResponse::clientPrefersProblemJson(''));
        $this->assertFalse(ApiResponse::clientPrefersProblemJson('application/json'));
        $this->assertFalse(ApiResponse::clientPrefersProblemJson('*/*'));
        $this->assertFalse(ApiResponse::clientPrefersProblemJson('application/xml'));
    }
}
