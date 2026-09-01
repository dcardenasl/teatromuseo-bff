<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsWizardSourceInterface;
use App\Libraries\Domain\DomainClient;
use App\Support\RequestTelemetry;
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
            RequestTelemetry::recordCache('admin.cms.wizard', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.wizard', 'miss');

        // The CMS wizard endpoint already builds the complete dynamic config,
        // including the active block type map. Reuse that read-model section
        // instead of making a second HTTP request to /api/v1/cms/block-types.
        $config = $this->object('/api/v1/cms/wizard/config', $bearerToken);
        $blockTypes = [];
        $blockTypeMap = is_array($config['block_types'] ?? null) ? $config['block_types'] : [];
        foreach ($blockTypeMap as $blockKey => $blockType) {
            if (! is_array($blockType)) {
                continue;
            }

            $blockType['block_key'] = (string) ($blockType['block_key'] ?? $blockKey);
            $blockTypes[] = $blockType;
        }
        $result = [
            'config' => $config,
            'blockTypes' => $blockTypes,
        ];
        $this->cache->save($cacheKey, $result, self::CACHE_TTL);

        return $result;
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
