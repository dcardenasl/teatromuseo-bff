<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsBootstrapSourceInterface;
use App\Libraries\Domain\DomainClient;
use App\Support\RequestTelemetry;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Composes the bounded CMS reads required by the Admin form screens.
 *
 * The CMS domain remains the source of truth. This reader only coordinates
 * authenticated, read-only domain calls and caches the resulting projection
 * for a short period under the effective permission scope.
 *
 * Each bootstrap has two implementations: a direct-SQL projection (used
 * whenever a `cms_readonly` connection is configured — see
 * {@see CmsBootstrapDirectQueries}) and an HTTP fallback via the CMS
 * Domain's own API (`items()`/`data()` below), kept here since it's the
 * simpler of the two paths and has no SQL to separate out.
 */
final class AdminCmsBootstrapSource implements AdminCmsBootstrapSourceInterface
{
    private const CACHE_TTL = 30;

    /** @param ?BaseConnection<mixed,mixed> $readDb */
    public function __construct(
        private readonly DomainClient $client,
        private readonly CacheInterface $cache,
        private readonly ?BaseConnection $readDb = null,
    ) {
    }

    /** @param list<string> $permissions */
    public function entryFormOptions(?int $entryId, array $permissions, string $bearerToken): array
    {
        $this->requirePermissions($permissions, [
            'cms.entries.read',
            'cms.languages.read',
            'cms.collections.read',
        ]);

        $cacheKey = $this->cacheKey(
            'entry-form-options',
            $permissions,
            ...($entryId === null ? [] : [$entryId]),
        );
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.entry-form-options', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.entry-form-options', 'miss');

        $sections = $this->readDb !== null
            ? $this->directQueries($this->readDb)->entryFormOptions($permissions)
            : [
                'languages'   => $this->items('/api/v1/cms/languages?limit=100&is_active=1', $bearerToken),
                'collections' => $this->items('/api/v1/cms/collections?limit=100&is_active=1', $bearerToken),
            ];

        if ($this->readDb === null && in_array('cms.categories.read', $permissions, true)) {
            $sections['categories'] = $this->items('/api/v1/cms/categories?per_page=1000&projection=list', $bearerToken);
        }
        if ($this->readDb === null && in_array('cms.tags.read', $permissions, true)) {
            $sections['tags'] = $this->items('/api/v1/cms/tags?per_page=1000&projection=list', $bearerToken);
        }

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param list<string> $permissions */
    public function pageFormOptions(?int $pageId, array $permissions, string $bearerToken): array
    {
        $this->requirePermissions($permissions, [
            'cms.pages.read',
            'cms.languages.read',
            'cms.collections.read',
        ]);

        $cacheKey = $this->cacheKey(
            'page-form-options',
            $permissions,
            ...($pageId === null ? [] : [$pageId]),
        );
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.page-form-options', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.page-form-options', 'miss');

        $sections = $this->readDb !== null
            ? $this->directQueries($this->readDb)->pageFormOptions()
            : [
                'languages'   => $this->items('/api/v1/cms/languages?limit=100&is_active=1', $bearerToken),
                'pages'       => $this->items('/api/v1/cms/pages?limit=250', $bearerToken),
                'collections' => $this->items('/api/v1/cms/collections?limit=200&is_active=1&projection=list', $bearerToken),
            ];

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param list<string> $permissions */
    public function menuEditorBootstrap(int $menuId, ?int $itemId, array $permissions, string $bearerToken): array
    {
        if ($menuId < 1 || ($itemId !== null && $itemId < 1)) {
            throw new InvalidArgumentException('CMS menu identifiers must be positive integers.');
        }

        $this->requirePermissions($permissions, [
            'cms.menus.read',
            'cms.languages.read',
            'cms.pages.read',
            'cms.entries.read',
            'cms.collections.read',
        ]);

        $cacheKey = $this->cacheKey(
            'menu-editor',
            $permissions,
            $menuId,
            ...($itemId === null ? [] : [$itemId]),
        );
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.menu-editor', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.menu-editor', 'miss');

        if ($this->readDb !== null) {
            $sections = $this->directQueries($this->readDb)->menuEditorBootstrap($menuId, $itemId);
        } else {
            $sections = [
                'menu'        => $this->data('/api/v1/cms/menus/' . $menuId, $bearerToken),
                'items'       => $this->items('/api/v1/cms/menu-items?menu_id=' . $menuId . '&limit=1000', $bearerToken),
                'languages'   => $this->items('/api/v1/cms/languages?limit=100&is_active=1', $bearerToken),
                'pages'       => $this->items('/api/v1/cms/pages?limit=250', $bearerToken),
                'entries'     => $this->items('/api/v1/cms/entries?limit=250', $bearerToken),
                'collections' => $this->items('/api/v1/cms/collections?limit=100&is_active=1', $bearerToken),
            ];

            if ($itemId !== null) {
                $sections['item'] = $this->data('/api/v1/cms/menu-items/' . $itemId, $bearerToken);
            }
        }

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param list<string> $permissions */
    public function siteIdentityBootstrap(array $permissions, string $bearerToken): array
    {
        $this->requirePermissions($permissions, [
            'cms.settings.read',
            'cms.languages.read',
        ]);

        $cacheKey = $this->cacheKey('site-identity', $permissions);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.site-identity', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.site-identity', 'miss');

        $sections = $this->readDb !== null
            ? $this->directQueries($this->readDb)->siteIdentityBootstrap()
            : [
                'settings'  => array_merge(
                    $this->items('/api/v1/cms/settings?' . http_build_query([
                        'filter' => ['setting_group' => 'identity'],
                        'per_page' => 100,
                    ]), $bearerToken),
                    $this->items('/api/v1/cms/settings?' . http_build_query([
                        'filter' => ['setting_group' => 'social'],
                        'per_page' => 100,
                    ]), $bearerToken),
                ),
                'languages' => $this->items('/api/v1/cms/languages?limit=100&is_active=1', $bearerToken),
            ];

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param BaseConnection<mixed,mixed> $readDb */
    private function directQueries(BaseConnection $readDb): CmsBootstrapDirectQueries
    {
        return new CmsBootstrapDirectQueries($readDb);
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $required
     */
    private function requirePermissions(array $permissions, array $required): void
    {
        foreach ($required as $permission) {
            if (! in_array($permission, $permissions, true)) {
                throw new AuthorizationException('The ' . $permission . ' permission is required.');
            }
        }
    }

    /** @param list<string> $permissions */
    private function cacheKey(string $context, array $permissions, int ...$ids): string
    {
        $scope = array_values(array_unique($permissions));
        sort($scope);

        return 'admin_cms_bootstrap_' . $context . '_' . implode('_', $ids ?: ['base'])
            . '_' . hash('sha256', implode("\0", $scope));
    }

    /** @return list<array<string, mixed>> */
    private function items(string $path, string $bearerToken): array
    {
        $data = $this->payloadData($this->client->get($path, $bearerToken));
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (! is_array($data)) {
            throw new RuntimeException('CMS bootstrap list payload is invalid.');
        }

        return array_values(array_filter(
            $data,
            static fn (mixed $item): bool => is_array($item),
        ));
    }

    /** @return array<string, mixed> */
    private function data(string $path, string $bearerToken): array
    {
        $data = $this->payloadData($this->client->get($path, $bearerToken));
        if (isset($data['data']) && is_array($data['data']) && ! isset($data['id'])) {
            $data = $data['data'];
        }
        if (! is_array($data)) {
            throw new RuntimeException('CMS bootstrap object payload is invalid.');
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function payloadData(array $payload): mixed
    {
        if (array_key_exists('data', $payload)) {
            return $payload['data'];
        }

        return $payload;
    }
}
