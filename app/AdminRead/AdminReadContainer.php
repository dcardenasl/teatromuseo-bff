<?php

declare(strict_types=1);

namespace App\AdminRead;

use App\AdminRead\Catalog\CatalogDashboardSource;
use App\AdminRead\Cms\CmsAnalyticsDashboardSource;
use App\AdminRead\Cms\CmsAnalyticsSource;
use App\AdminRead\Cms\CmsDashboardSource;
use App\AdminRead\Cms\CmsTranslationsDashboardSource;
use App\AdminRead\Event\EventDashboardSource;
use App\AdminRead\Files\AdminFileUsageSource;
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
        return new CmsTranslationsDashboardSource(\Config\Services::domainClient('cms'));
    }

    public static function catalogDashboard(): CatalogDashboardSource
    {
        return new CatalogDashboardSource(self::database('catalog_readonly'));
    }

    public static function eventDashboard(): EventDashboardSource
    {
        return new EventDashboardSource(self::database('event_readonly'));
    }

    public static function fileUsages(): AdminFileUsageSource
    {
        return new AdminFileUsageSource(
            \Config\Services::hubDashboardClient(),
            self::database('cms_readonly'),
        );
    }
}
