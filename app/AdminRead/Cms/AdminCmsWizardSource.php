<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsWizardSourceInterface;
use App\Libraries\Domain\DomainClient;
use CodeIgniter\Cache\CacheInterface;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use RuntimeException;

/** Composes the CMS reads required to initialize the Admin structure wizard. */
final class AdminCmsWizardSource implements AdminCmsWizardSourceInterface
{
    private const CACHE_TTL = 30;

    public function __construct(
        private readonly DomainClient $client,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @param list<string> $permissions */
    public function bootstrap(array $permissions, string $bearerToken): array
    {
        if (! in_array('cms.entries.read', $permissions, true)) {
            throw new AuthorizationException('The cms.entries.read permission is required.');
        }

        $scope = array_values(array_unique($permissions));
        sort($scope);
        $cacheKey = 'admin_cms_wizard_' . hash('sha256', implode("\0", $scope));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $config = $this->object('/cms/wizard/config', $bearerToken);
        $blockTypes = $this->items('/cms/block-types?limit=200&is_active=1', $bearerToken);
        $result = [
            'config' => $config,
            'blockTypes' => $blockTypes,
        ];
        $this->cache->save($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function items(string $path, string $bearerToken): array
    {
        $payload = $this->payload($this->client->get($path, $bearerToken));
        if (is_array($payload['items'] ?? null)) {
            $payload = $payload['items'];
        } elseif (is_array($payload['data'] ?? null)) {
            $payload = $payload['data'];
        }
        if (! is_array($payload)) {
            throw new RuntimeException('CMS wizard block type payload is invalid.');
        }

        return array_values(array_filter($payload, static fn (mixed $item): bool => is_array($item)));
    }

    /** @return array<string, mixed> */
    private function object(string $path, string $bearerToken): array
    {
        $payload = $this->payload($this->client->get($path, $bearerToken));
        if (is_array($payload['data'] ?? null) && ! isset($payload['id'])) {
            $payload = $payload['data'];
        }
        if (! is_array($payload)) {
            throw new RuntimeException('CMS wizard config payload is invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function payload(array $payload): mixed
    {
        return array_key_exists('data', $payload) ? $payload['data'] : $payload;
    }
}
