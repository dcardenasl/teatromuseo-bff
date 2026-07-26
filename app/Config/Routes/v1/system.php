<?php

declare(strict_types=1);

/** @var \CodeIgniter\Router\RouteCollection $routes */

$routes->get('ping', '\App\Controllers\Api\V1\System\HealthController::ping');
$routes->group('', ['filter' => 'featureToggle:monitoring'], function ($routes): void {
    $routes->get('health', '\App\Controllers\Api\V1\System\HealthController::index');
    $routes->get('ready', '\App\Controllers\Api\V1\System\HealthController::ready');
    $routes->get('live', '\App\Controllers\Api\V1\System\HealthController::live');
});
