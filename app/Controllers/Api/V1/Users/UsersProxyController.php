<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Users;

use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Canonical proxy example: forwards `GET /api/v1/users/{id}` to the hub.
 *
 * Add similar three-line classes for each upstream resource the BFF needs to
 * expose. Aggregator endpoints live in their own controllers and use
 * {@see BaseProxyController::aggregate()} instead.
 */
class UsersProxyController extends BaseProxyController
{
    public function show(int $id): ResponseInterface
    {
        return $this->proxy(Services::hubClient(), '/api/v1/users/' . $id);
    }
}
