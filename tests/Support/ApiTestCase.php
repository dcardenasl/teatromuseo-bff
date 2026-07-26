<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * ApiTestCase
 *
 * Base class for BFF HTTP tests. The BFF has no database, so this case
 * deliberately omits DatabaseTestTrait — migrations and seeds do not exist.
 */
abstract class ApiTestCase extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        \dcardenasl\Ci4ApiCore\Http\ContextHolder::flush();
        $this->resetCacheState();
        $this->resetState();
    }

    protected function tearDown(): void
    {
        \dcardenasl\Ci4ApiCore\Http\ContextHolder::flush();
        $this->resetCacheState();
        $this->resetState();
        parent::tearDown();
    }

    protected function resetRequest(): void
    {
        $this->resetState();
        $this->reapplyTestRequestHeaders();
    }

    protected function resetState(): void
    {
        $_POST    = [];
        $_GET     = [];
        $_FILES   = [];
        $_REQUEST = [];

        Services::resetSingle('request');

        $this->request = null;

        $this->reapplyTestRequestHeaders();
    }

    protected function resetCacheState(): void
    {
        try {
            Services::cache()->clean();
        } catch (\Throwable $e) {
            // Cache backend may be absent in some test contexts — ignore.
        }
    }

    protected function getResponseJson($result): array
    {
        return json_decode($result->getJSON(), true) ?? [];
    }

    /** @var array<string, string> */
    protected array $testRequestHeaders = [];

    protected function setTestRequestHeaders(array $headers): void
    {
        $this->testRequestHeaders = $headers;
        $this->withHeaders($headers);
    }

    protected function reapplyTestRequestHeaders(): void
    {
        $this->withHeaders($this->testRequestHeaders);
    }

    protected function clearTestRequestHeaders(): void
    {
        $this->testRequestHeaders = [];
        $this->withHeaders([]);
    }
}
