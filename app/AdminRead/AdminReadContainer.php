<?php

declare(strict_types=1);

namespace App\AdminRead;

use App\AdminRead\Catalog\AdminCatalogCollectionItemSource;
use App\AdminRead\Catalog\CatalogDashboardSource;
use App\AdminRead\Cms\AdminCmsBootstrapSource;
use App\AdminRead\Cms\AdminCmsWizardSource;
use App\AdminRead\Cms\AdminCmsWorkspaceSource;
use App\AdminRead\Cms\CmsAnalyticsDashboardSource;
use App\AdminRead\Cms\CmsAnalyticsSource;
use App\AdminRead\Cms\CmsDashboardSource;
use App\AdminRead\Cms\CmsTranslationsDashboardSource;
use App\AdminRead\Event\AdminEventLookupSource;
use App\AdminRead\Event\AdminEventWorkspaceSource;
use App\AdminRead\Event\EventDashboardSource;
use App\AdminRead\Files\AdminFileUsageSource;
use App\AdminRead\Hub\AdminIamRoleWorkspaceSource;
use App\AdminRead\Hub\AdminMetricsWorkspaceSource;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Construction seam for authenticated, direct dashboard projections. */
final class AdminReadContainer
{
    /** @return BaseConnection<mixed, mixed> */
    public static function database(string $group): BaseConnection
    {
        if ($group === '') {
            throw new \InvalidArgumentException('An admin-read database group is required.');
        }

        /** @var BaseConnection<mixed, mixed> $connection */
        $connection = Database::connect($group);

        return $connection;
    }

    public static function cmsDashboard(): CmsDashboardSource
    {
        return new CmsDashboardSource(self::database('cms_readonly'));
    }

    public static function cmsAnalyticsDashboard(): CmsAnalyticsDashboardSource
    {
        return new CmsAnalyticsDashboardSource(self::database('cms_readonly'));
    }

    public static function cmsAnalytics(): CmsAnalyticsSource
    {
        return new CmsAnalyticsSource(self::database('cms_readonly'));
    }

    public static function cmsTranslationsDashboard(): CmsTranslationsDashboardSource
    {
        return new CmsTranslationsDashboardSource(self::database('cms_readonly'));
    }

    public static function catalogDashboard(): CatalogDashboardSource
    {
        return new CatalogDashboardSource(self::database('catalog_readonly'));
    }

    public static function catalogCollectionItemWorkspace(): AdminCatalogCollectionItemSource
    {
        return new AdminCatalogCollectionItemSource(
            self::database('catalog_readonly'),
            self::database('cms_readonly'),
        );
    }

    public static function eventDashboard(): EventDashboardSource
    {
        return new EventDashboardSource(self::database('event_readonly'));
    }

    public static function eventWorkspace(): AdminEventWorkspaceSource
    {
        return new AdminEventWorkspaceSource(
            self::database('event_readonly'),
            self::database('cms_readonly'),
        );
    }

    public static function fileUsages(): AdminFileUsageSource
    {
        return new AdminFileUsageSource(\Config\Services::hubDashboardClient());
    }

    public static function eventLookups(): AdminEventLookupSource
    {
        return new AdminEventLookupSource(
            self::database('event_readonly'),
            \Config\Services::cache(),
        );
    }

    public static function cmsBootstrap(): AdminCmsBootstrapSource
    {
        return new AdminCmsBootstrapSource(
            \Config\Services::domainClient('cms'),
            \Config\Services::cache(),
            self::database('cms_readonly'),
        );
    }

    public static function cmsWorkspace(): AdminCmsWorkspaceSource
    {
        $bff = config('Bff');
        $hubPublicBaseUrl = (string) ($bff->hubPublicBaseUrl ?? '');

        return new AdminCmsWorkspaceSource(
            self::database('cms_readonly'),
            new \App\PublicRead\Cms\FileUrlResolver(
                new \App\PublicRead\Support\DirectDbFileMetaResolver(
                    self::database('hub_readonly'),
                    $hubPublicBaseUrl,
                ),
                $hubPublicBaseUrl,
            ),
        );
    }

    public static function cmsWizard(): AdminCmsWizardSource
    {
        return new AdminCmsWizardSource(
            \Config\Services::domainClient('cms'),
            \Config\Services::cache(),
        );
    }

    public static function iamRoleWorkspace(): AdminIamRoleWorkspaceSource
    {
        return new AdminIamRoleWorkspaceSource(\Config\Services::hubDashboardClient());
    }

    public static function metricsWorkspace(): AdminMetricsWorkspaceSource
    {
        return new AdminMetricsWorkspaceSource(\Config\Services::hubDashboardClient());
    }
}
