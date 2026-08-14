<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Keeps the public-read filter contract without counting a second domain hit. */
final class PublicReadTelemetryFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): RequestInterface
    {
        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        return $response;
    }
}
