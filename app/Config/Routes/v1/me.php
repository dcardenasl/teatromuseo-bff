<?php

declare(strict_types=1);

/** @var \CodeIgniter\Router\RouteCollection $routes */

$routes->get(
    'me/dashboard',
    '\App\Controllers\Api\V1\Me\DashboardController::index',
    ['filter' => 'introspectauth'],
);

$routes->get(
    'me/admin-dashboard',
    '\App\Controllers\Api\V1\Me\AdminDashboardController::index',
    ['filter' => 'effectivepermissionsauth'],
);

$routes->get(
    'me/admin-analytics',
    '\App\Controllers\Api\V1\Me\AdminAnalyticsController::index',
    ['filter' => 'introspectauth'],
);

$routes->get(
    'me/admin-files/(:num)/usages',
    '\App\Controllers\Api\V1\Me\AdminFileUsagesController::index/$1',
    ['filter' => 'effectivepermissionsauth'],
);

$routes->get(
    'me/admin-event-lookups/(:segment)',
    '\App\Controllers\Api\V1\Me\AdminEventLookupsController::index/$1',
    ['filter' => 'effectivepermissionsauth'],
);
