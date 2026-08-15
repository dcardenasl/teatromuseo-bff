<?php

declare(strict_types=1);

/** @var \CodeIgniter\Router\RouteCollection $routes */

$publicReadFilters = ['webappkey', 'throttle', 'correlationid', 'publicTelemetry'];

$routes->group('public-read', ['namespace' => '\\App\\Controllers\\Api\\V1\\PublicRead', 'filter' => $publicReadFilters], static function ($routes): void {
    $routes->get('(:segment)/navigation', 'CmsPublicReadController::navigation/$1');
    $routes->get('(:segment)/settings', 'CmsPublicReadController::settings/$1');
    $routes->get('(:segment)/pages', 'CmsPublicReadController::pages/$1');
    $routes->get('(:segment)/pages/(.+)', 'CmsPublicReadController::page/$1/$2');
    $routes->get('(:segment)/page-resolve/(:any)', 'PageResolutionController::show/$1/$2');
    $routes->get('(:segment)/entries/(:segment)', 'CmsPublicReadController::entries/$1/$2');
    $routes->get('(:segment)/entries/(:segment)/(:any)', 'CmsPublicReadController::entry/$1/$2/$3');
    $routes->get('(:segment)/collection-items', 'CatalogPublicReadController::index/$1');
    $routes->get('(:segment)/collection-items/(:any)', 'CatalogPublicReadController::item/$1/$2');
    $routes->get('(:segment)/events', 'EventPublicReadController::index/$1');
    $routes->get('(:segment)/events/(:any)', 'EventPublicReadController::item/$1/$2');
});

$routes->group('public', ['namespace' => '\\App\\Controllers\\Api\\V1\\PublicRead', 'filter' => $publicReadFilters], static function ($routes): void {
    $routes->get('catalog/categories', 'CatalogPublicReadController::categories');
    $routes->get('catalog/techniques', 'CatalogPublicReadController::techniques');
    $routes->get('catalog/techniques/(:any)', 'CatalogPublicReadController::technique/$1');
    $routes->get('cms/public/languages', 'CmsPublicReadController::languages');
    $routes->get('(:segment)/collections', 'CmsPublicReadController::collections/$1');
    $routes->get('(:segment)/pages/by-type/(:segment)', 'CmsPublicReadController::pageByType/$1/$2');
    $routes->get('(:segment)/categories/(:segment)', 'CmsPublicReadController::categories/$1/$2');
    $routes->get('(:segment)/tags/(:segment)', 'CmsPublicReadController::tags/$1/$2');
    $routes->get('(:segment)/forms/(:segment)', 'CmsPublicReadController::form/$1/$2');
    $routes->get('redirects/(.*)', 'CmsPublicReadController::redirect/$1');
    $routes->get('events/types', 'EventPublicReadController::types');
});

// Template-generated public passthrough fallback. Explicit public-read routes
// above remain preferred because they avoid an upstream HTTP hop.
$routes->get('public-proxy/(.*)', '\\App\\Controllers\\Api\\V1\\PublicProxyController::forward/$1', [
    'filter' => ['webappkey', 'throttle', 'correlationid'],
]);
