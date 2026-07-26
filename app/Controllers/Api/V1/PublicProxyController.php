<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Generic transparent proxy for template-driven public endpoints.
 *
 * Kickstart generates route entries that point here for every
 * `template.json.public_endpoints[]` item. The route parameter is appended to
 * `/api/v1/` so the BFF simply forwards the incoming request to the hub.
 */
class PublicProxyController extends BaseProxyController
{
    public function forward(string $path = ''): ResponseInterface
    {
        return $this->proxy(Services::hubClient(), '/api/v1/' . ltrim($path, '/'));
    }
}
