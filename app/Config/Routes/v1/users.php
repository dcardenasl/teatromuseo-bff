<?php

declare(strict_types=1);

/** @var \CodeIgniter\Router\RouteCollection $routes */

$routes->get('users/(:num)', '\App\Controllers\Api\V1\Users\UsersProxyController::show/$1');
