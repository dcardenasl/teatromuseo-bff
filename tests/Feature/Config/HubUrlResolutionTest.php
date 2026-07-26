<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use Config\Bff;
use Config\Hub;
use Config\Services;
use Tests\Support\ApiTestCase;

/**
 * BFF-108: `Bff` and `Hub` configs share a single resolver for the hub URL so
 * an operator can set either `bff.hubUrl` (canonical) or `hub.url` (legacy
 * fallback) and both classes land on the same value. Pin this contract — the
 * health probe (`Bff::$hubUrl`) and the HubClient (`Hub::$url`) must never
 * disagree.
 */
class HubUrlResolutionTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnvKey('bff.hubUrl');
        $this->clearEnvKey('hub.url');
        Services::resetSingle('bff');
        Services::resetSingle('hub');
    }

    protected function tearDown(): void
    {
        $this->clearEnvKey('bff.hubUrl');
        $this->clearEnvKey('hub.url');
        Services::resetSingle('bff');
        Services::resetSingle('hub');
        parent::tearDown();
    }

    private function setEnvKey(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }

    private function clearEnvKey(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    public function testCanonicalEnvVarWins(): void
    {
        $this->setEnvKey('bff.hubUrl', 'http://primary.test');
        $this->setEnvKey('hub.url', 'http://legacy.test');

        $this->assertSame('http://primary.test', (new Bff())->hubUrl);
        $this->assertSame('http://primary.test', (new Hub())->url);
    }

    public function testFallsBackToLegacyEnvVar(): void
    {
        $this->setEnvKey('hub.url', 'http://legacy.test');

        $this->assertSame('http://legacy.test', (new Bff())->hubUrl);
        $this->assertSame('http://legacy.test', (new Hub())->url);
    }

    public function testEmptyWhenNeitherIsSet(): void
    {
        $this->assertSame('', (new Bff())->hubUrl);
        $this->assertSame('', (new Hub())->url);
    }
}
