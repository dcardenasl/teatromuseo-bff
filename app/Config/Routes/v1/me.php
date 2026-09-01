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
    'me/admin-metrics/workspace',
    '\App\Controllers\Api\V1\Me\AdminMetricsWorkspaceController::index',
    ['filter' => 'effectivepermissionsauth'],
);

$routes->get(
    'me/admin-iam/roles/(:num)/workspace',
    '\App\Controllers\Api\V1\Me\AdminIamWorkspaceController::role/$1',
    ['filter' => 'effectivepermissionsauth'],
);

$routes->get(
    'me/admin-analytics',
    '\App\Controllers\Api\V1\Me\AdminAnalyticsController::index',
    // effectivepermissionsauth, not introspectauth: `cms.analytics.read` is
    // registered under the CMS Domain's application in the Hub, not the
    // BFF's own. `/auth/introspect` only returns the caller's permission
    // scope for the BFF's own X-App-Key application, so this permission
    // would never be present there even for a user who genuinely holds it —
    // the same real-world 403 that BFF-ADMINREAD-13 found and fixed for
    // `me/admin-event-lookups`. Every other `admin-*` route already uses
    // effectivepermissionsauth for this exact reason.
    ['filter' => 'effectivepermissionsauth'],
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
$routes->get(
    'me/admin-catalog/collection-items/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCatalogWorkspaceController::index',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-catalog/collection-items/(:num)/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCatalogWorkspaceController::item/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-event/events/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminEventWorkspaceController::index',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-event/events/(:num)/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminEventWorkspaceController::event/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-event/events/list-bootstrap',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminEventListController::index',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-catalog/collection-items/list-bootstrap',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCatalogListController::index',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-catalog/techniques/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCatalogTechniqueController::index',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-catalog/techniques/(:num)/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCatalogTechniqueController::technique/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/entry-form-options',
    '\App\Controllers\Api\V1\Me\AdminCmsBootstrapController::entryFormOptions',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/entry-form-options/(:num)',
    '\App\Controllers\Api\V1\Me\AdminCmsBootstrapController::entryFormOptions/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/page-form-options',
    '\App\Controllers\Api\V1\Me\AdminCmsBootstrapController::pageFormOptions',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/page-form-options/(:num)',
    '\App\Controllers\Api\V1\Me\AdminCmsBootstrapController::pageFormOptions/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/menus/(:num)/editor-bootstrap',
    '\App\Controllers\Api\V1\Me\AdminCmsBootstrapController::menuEditorBootstrap/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/menus/(:num)/editor-bootstrap/(:num)',
    '\App\Controllers\Api\V1\Me\AdminCmsBootstrapController::menuEditorBootstrap/$1/$2',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/site-identity-bootstrap',
    '\App\Controllers\Api\V1\Me\AdminCmsBootstrapController::siteIdentityBootstrap',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/pages/(:num)/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCmsWorkspaceController::page/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/entries/(:num)/workspace',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCmsWorkspaceController::entry/$1',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/wizard-bootstrap',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCmsWizardController::bootstrap',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/categories/bootstrap',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCmsCategoryController::index',
    ['filter' => 'effectivepermissionsauth'],
);
$routes->get(
    'me/admin-cms/categories/(:num)/bootstrap',
    '\\App\\Controllers\\Api\\V1\\Me\\AdminCmsCategoryController::category/$1',
    ['filter' => 'effectivepermissionsauth'],
);
