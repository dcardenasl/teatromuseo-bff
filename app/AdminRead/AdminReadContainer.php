<?php

declare(strict_types=1);

namespace App\AdminRead;

use App\AdminRead\Catalog\CatalogDashboardSource;
use App\AdminRead\Cms\CmsDashboardSource;
use App\AdminRead\Event\EventDashboardSource;
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

    public static function catalogDashboard(): CatalogDashboardSource
    {
        return new CatalogDashboardSource(self::database('catalog_readonly'));
    }

    public static function eventDashboard(): EventDashboardSource
    {
        return new EventDashboardSource(self::database('event_readonly'));
    }
}
