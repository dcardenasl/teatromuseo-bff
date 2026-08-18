<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseService;

require_once __DIR__ . '/ApiCoreServices.php';

/**
 * Services Configuration file.
 *
 * BFF starter: stateless gateway. No DB, no audit chain, no domain factories.
 * Only HubClient and the ci4-api-core HTTP/DTO helpers via ApiCoreServices.
 */
class Services extends BaseService
{
    use ApiCoreServices;

    public static function hubClient(bool $getShared = true): \dcardenasl\Ci4ApiCore\Http\Client\HubClient
    {
        if ($getShared) {
            return static::getSharedInstance('hubClient');
        }

        return static::createHubClient();
    }

    public static function hubDashboardClient(bool $getShared = true): \App\Libraries\Hub\HubClient
    {
        if ($getShared) {
            return static::getSharedInstance('hubDashboardClient');
        }

        return static::createHubClient();
    }

    private static function createHubClient(): \App\Libraries\Hub\HubClient
    {

        /** @var \Config\Hub $hubConfig */
        $hubConfig = config('Hub');

        $coreHubConfig = new \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig(
            url: $hubConfig->url,
            apiKey: $hubConfig->apiKey,
            introspectPath: $hubConfig->introspectPath ?? '/api/v1/auth/introspect',
            serviceTokenPath: $hubConfig->serviceTokenPath ?? '/api/v1/auth/service-token',
            permissionsPath: $hubConfig->permissionsPath ?? '/api/v1/iam/permissions',
            introspectCacheTtl: $hubConfig->introspectCacheTtl ?? 60,
            serviceTokenSafetyMargin: $hubConfig->serviceTokenSafetyMargin ?? 30,
            httpTimeout: $hubConfig->httpTimeout ?? 5,
        );

        return new \App\Libraries\Hub\HubClient(
            $coreHubConfig,
            \Config\Services::curlrequest(),
            \Config\Services::cache()
        );
    }

    public static function domainClient(string $domainCode, bool $getShared = true): \App\Libraries\Domain\DomainClient
    {
        if ($getShared) {
            return static::getSharedInstance('domainClient', $domainCode);
        }

        /** @var \Config\Bff $bffConfig */
        $bffConfig = config('Bff');
        $baseUrl   = $bffConfig->domains[$domainCode] ?? null;

        if ($baseUrl === null) {
            throw new \InvalidArgumentException("Domain client misconfigured: Upstream domain URL for '{$domainCode}' is not defined in Config\\Bff::\$domains.");
        }

        return new \App\Libraries\Domain\DomainClient(
            \Config\Services::curlrequest(),
            $baseUrl
        );
    }

    public static function healthChecker(bool $getShared = true): \dcardenasl\Ci4ApiCore\Monitoring\HealthChecker
    {
        if ($getShared) {
            return static::getSharedInstance('healthChecker');
        }

        return new \dcardenasl\Ci4ApiCore\Monitoring\HealthChecker();
    }

    /**
     * The Request Service
     *
     * @param \Config\App|bool $getShared
     */
    public static function request($getShared = true): \dcardenasl\Ci4ApiCore\Http\ApiRequest
    {
        if (is_bool($getShared) && $getShared) {
            return static::getSharedInstance('request');
        }

        $config = $getShared instanceof \Config\App ? $getShared : config('App');

        return new \dcardenasl\Ci4ApiCore\Http\ApiRequest(
            $config,
            static::uri(),
            'php://input',
            new \CodeIgniter\HTTP\UserAgent()
        );
    }

    public static function publicReadCms(bool $getShared = true): \App\PublicRead\CmsPublicReadBundle
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadCms');
        }

        return \App\PublicRead\PublicReadContainer::cms();
    }

    public static function publicReadCmsLanguages(bool $getShared = true): \App\PublicRead\CmsLanguageReader
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadCmsLanguages');
        }

        return new \App\PublicRead\CmsLanguageReader(
            \App\PublicRead\PublicReadContainer::database('cms_readonly'),
        );
    }

    public static function publicReadDatabaseHealth(bool $getShared = true): \App\PublicRead\ReadDatabaseHealth
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadDatabaseHealth');
        }

        return new \App\PublicRead\ReadDatabaseHealth();
    }

    public static function adminReadCmsDashboard(bool $getShared = true): \App\AdminRead\Contracts\AdminDashboardSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCmsDashboard');
        }

        return \App\AdminRead\AdminReadContainer::cmsDashboard();
    }

    public static function adminReadCmsAnalyticsDashboard(bool $getShared = true): \App\AdminRead\Contracts\AdminDashboardSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCmsAnalyticsDashboard');
        }

        return \App\AdminRead\AdminReadContainer::cmsAnalyticsDashboard();
    }

    public static function adminReadCmsAnalytics(bool $getShared = true): \App\AdminRead\Contracts\AdminAnalyticsSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCmsAnalytics');
        }

        return \App\AdminRead\AdminReadContainer::cmsAnalytics();
    }

    public static function adminReadCmsTranslationsDashboard(bool $getShared = true): \App\AdminRead\Contracts\AdminDashboardTranslationsSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCmsTranslationsDashboard');
        }

        return \App\AdminRead\AdminReadContainer::cmsTranslationsDashboard();
    }

    public static function adminReadCatalogDashboard(bool $getShared = true): \App\AdminRead\Contracts\AdminDashboardSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCatalogDashboard');
        }

        return \App\AdminRead\AdminReadContainer::catalogDashboard();
    }

    public static function adminReadCatalogCollectionItemWorkspace(bool $getShared = true): \App\AdminRead\Contracts\AdminCatalogCollectionItemSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCatalogCollectionItemWorkspace');
        }

        return \App\AdminRead\AdminReadContainer::catalogCollectionItemWorkspace();
    }

    public static function adminReadEventDashboard(bool $getShared = true): \App\AdminRead\Contracts\AdminDashboardSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadEventDashboard');
        }

        return \App\AdminRead\AdminReadContainer::eventDashboard();
    }

    public static function adminReadEventWorkspace(bool $getShared = true): \App\AdminRead\Contracts\AdminEventWorkspaceSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadEventWorkspace');
        }

        return \App\AdminRead\AdminReadContainer::eventWorkspace();
    }

    public static function adminReadFileUsages(bool $getShared = true): \App\AdminRead\Contracts\AdminFileUsageSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadFileUsages');
        }

        return \App\AdminRead\AdminReadContainer::fileUsages();
    }

    public static function adminReadEventLookups(bool $getShared = true): \App\AdminRead\Contracts\AdminEventLookupSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadEventLookups');
        }

        return \App\AdminRead\AdminReadContainer::eventLookups();
    }

    public static function adminReadCmsBootstrap(bool $getShared = true): \App\AdminRead\Contracts\AdminCmsBootstrapSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCmsBootstrap');
        }

        return \App\AdminRead\AdminReadContainer::cmsBootstrap();
    }

    public static function adminReadCmsWorkspace(bool $getShared = true): \App\AdminRead\Contracts\AdminCmsWorkspaceSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCmsWorkspace');
        }

        return \App\AdminRead\AdminReadContainer::cmsWorkspace();
    }

    public static function adminReadCmsWizard(bool $getShared = true): \App\AdminRead\Contracts\AdminCmsWizardSourceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('adminReadCmsWizard');
        }

        return \App\AdminRead\AdminReadContainer::cmsWizard();
    }

    public static function publicReadCatalog(bool $getShared = true): \App\PublicRead\Catalog\PublicReadCollectionItemReader
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadCatalog');
        }

        return \App\PublicRead\PublicReadContainer::catalog();
    }

    public static function publicReadCatalogFacets(bool $getShared = true): \App\PublicRead\CatalogFacetReader
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadCatalogFacets');
        }

        return \App\PublicRead\PublicReadContainer::catalogFacets();
    }

    public static function publicReadEvents(bool $getShared = true): \App\PublicRead\Event\PublicReadEventReader
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadEvents');
        }

        return \App\PublicRead\PublicReadContainer::events();
    }

    public static function publicReadEventTypes(bool $getShared = true): \App\PublicRead\EventTypeReader
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadEventTypes');
        }

        return \App\PublicRead\PublicReadContainer::eventTypes();
    }

    public static function publicReadPageEnvelope(bool $getShared = true): \App\PublicRead\Page\PageEnvelope
    {
        if ($getShared) {
            return static::getSharedInstance('publicReadPageEnvelope');
        }

        return \App\PublicRead\PublicReadContainer::pageEnvelope();
    }
}
