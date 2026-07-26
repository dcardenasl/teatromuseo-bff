<?php

declare(strict_types=1);

namespace Tests\Support\Traits;

/**
 * Custom Assertions for API Testing
 *
 * Provides reusable assertions for testing ApiResponse arrays.
 */
trait CustomAssertionsTrait
{
    /**
     * Assert that the response is a successful API response
     */
    protected function assertSuccessResponse(array $result, ?string $dataKey = null): void
    {
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('success', $result['status']);

        if ($dataKey !== null) {
            $this->assertArrayHasKey('data', $result);
            $this->assertArrayHasKey($dataKey, $result['data']);
        }
    }

    /**
     * Assert that the response is an error response
     */
    protected function assertErrorResponse(array $result, ?string $errorField = null): void
    {
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('error', $result['status']);
        $this->assertArrayHasKey('errors', $result);

        if ($errorField !== null) {
            $this->assertArrayHasKey($errorField, $result['errors']);
        }
    }

    /**
     * Assert that the response is a validation error (422)
     */
    protected function assertValidationErrorResponse(array $result, array $expectedFields = []): void
    {
        $this->assertErrorResponse($result);
        $this->assertArrayHasKey('code', $result);
        $this->assertEquals(422, $result['code']);

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result['errors'], "Expected error field '$field' not found");
        }
    }

    /**
     * Assert that the response is a paginated response
     */
    protected function assertPaginatedResponse(array $result): void
    {
        $this->assertSuccessResponse($result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('total', $result['meta']);
        $this->assertArrayHasKey('page', $result['meta']);
        $this->assertArrayHasKey('per_page', $result['meta']);
        $this->assertArrayHasKey('last_page', $result['meta']);
    }

    /**
     * Assert that the response has a specific error code
     */
    protected function assertErrorResponseWithCode(array $result, int $expectedCode): void
    {
        $this->assertErrorResponse($result);
        $this->assertArrayHasKey('code', $result);
        $this->assertEquals($expectedCode, $result['code']);
    }

    /**
     * Assert that a created response is returned
     */
    protected function assertCreatedResponse(array $result, ?string $dataKey = null): void
    {
        $this->assertSuccessResponse($result, $dataKey);
        $this->assertArrayHasKey('message', $result);
    }
}
