<?php

declare(strict_types=1);

namespace Tests\Feature\PublicRead;

use Tests\Support\ApiTestCase;

/** @internal */
final class PublicReadValidationTest extends ApiTestCase
{
    /** @var string|false */
    private string|false $originalBffApiKey = false;

    private bool $hadEnv = false;

    private bool $hadServer = false;

    private string $savedEnv = '';

    private string $savedServer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBffApiKey = getenv('BFF_API_KEY');
        $this->hadEnv = array_key_exists('BFF_API_KEY', $_ENV);
        $this->hadServer = array_key_exists('BFF_API_KEY', $_SERVER);
        $this->savedEnv = $this->hadEnv ? (string) $_ENV['BFF_API_KEY'] : '';
        $this->savedServer = $this->hadServer ? (string) $_SERVER['BFF_API_KEY'] : '';

        putenv('BFF_API_KEY=test-bff-key');
        $_ENV['BFF_API_KEY'] = 'test-bff-key';
        $_SERVER['BFF_API_KEY'] = 'test-bff-key';
        $this->setTestRequestHeaders(['X-App-Key' => 'test-bff-key']);
    }

    protected function tearDown(): void
    {
        if ($this->originalBffApiKey !== false) {
            putenv('BFF_API_KEY=' . $this->originalBffApiKey);
        } else {
            putenv('BFF_API_KEY');
        }

        if ($this->hadEnv) {
            $_ENV['BFF_API_KEY'] = $this->savedEnv;
        } else {
            unset($_ENV['BFF_API_KEY']);
        }

        if ($this->hadServer) {
            $_SERVER['BFF_API_KEY'] = $this->savedServer;
        } else {
            unset($_SERVER['BFF_API_KEY']);
        }

        parent::tearDown();
    }

    public function testMalformedEventDateIsAClientError(): void
    {
        $result = $this->get('/api/v1/public-read/es/events?from=2026-01-01&to=2027-01-01');

        $result->assertStatus(422);
        $body = $this->getResponseJson($result);
        $this->assertSame('error', $body['status']);
        $this->assertSame(422, $body['code']);
        $this->assertArrayHasKey('from', $body['errors']);
    }

    public function testInvertedEventDateRangeIsAClientError(): void
    {
        $result = $this->get(
            '/api/v1/public-read/es/events?from=2027-01-01%2000:00:00&to=2026-01-01%2000:00:00',
        );

        $result->assertStatus(422);
        $body = $this->getResponseJson($result);
        $this->assertSame(422, $body['code']);
        $this->assertArrayHasKey('from', $body['errors']);
    }

    public function testUnknownSparseFieldIsAClientError(): void
    {
        $result = $this->get('/api/v1/public-read/es/collection-items?fields=unknown');

        $result->assertStatus(422);
        $body = $this->getResponseJson($result);
        $this->assertSame(422, $body['code']);
        $this->assertSame(['unknown'], $body['errors']['fields']);
        $this->assertArrayHasKey('allowed', $body['errors']);
    }

    /**
     * TOTEM-BFF-19: the listing endpoint's allowed `fields` set was widened
     * to include detail-only columns (`DETAIL_FIELDS` is a strict superset
     * of `LIST_FIELDS`) so a bounded, single-call bulk consumer can request
     * the richer projection for an entire category at once, instead of one
     * call per item. `gallery_images`/`contenido` are detail-only fields —
     * asking for them here must no longer be a validation error.
     */
    public function testListingAcceptsDetailOnlyFieldsForBulkConsumers(): void
    {
        $result = $this->get('/api/v1/public-read/es/collection-items?fields=id,name,gallery_images,contenido');

        $result->assertStatus(200);
        $body = $this->getResponseJson($result);
        $this->assertTrue($body['ok']);
    }

    /** The default projection (no `fields` param) must stay exactly LIST_FIELDS — existing callers (Web) see no behavior change. */
    public function testListingDefaultFieldsAreUnchangedWhenNoFieldsParamIsGiven(): void
    {
        $result = $this->get('/api/v1/public-read/es/collection-items');

        $result->assertStatus(200);
        $body = $this->getResponseJson($result);
        $this->assertSame(
            ['id', 'name', 'category_id', 'inventory_code', 'status', 'summary', 'cover_file_id', 'cover_image', 'slug', 'localized', 'category', 'created_at', 'updated_at'],
            $body['meta']['fields'],
        );
    }
}
