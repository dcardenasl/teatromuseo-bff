<?php

declare(strict_types=1);

namespace App\Config;

use OpenApi\Attributes as OA;

/**
 * BFF OpenAPI surface.
 *
 * The BFF is forward-only by default. Clients authenticate using a `Bearer`
 * JWT issued by the upstream hub (ci4-api-starter) — the BFF never issues
 * tokens, so the security scheme below documents the wire format only.
 *
 * Tags map to the BFF's own controllers: System (health/ping/live/ready),
 * Users (proxy example, BFF-103), Me (introspect-auth aggregators, BFF-106 and
 * the real Admin dashboard consumer).
 */
#[OA\OpenApi(
    openapi: '3.0.0',
)]
#[OA\Info(
    version: \Config\Project::VERSION,
    title: \Config\Project::NAME,
    description: \Config\Project::DESCRIPTION,
)]
#[OA\Server(
    url: 'http://localhost:8188',
    description: 'Local development server (BFF)'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'JWT issued by the upstream hub. The BFF forwards it verbatim on every call.'
)]
#[OA\SecurityScheme(
    securityScheme: 'appKeyAuth',
    type: 'apiKey',
    in: 'header',
    name: 'X-App-Key',
    description: 'Shared application key for the unauthenticated public-read surface (`BFF_API_KEY`/`WEB_API_KEY`). Distinct from `hub.apiKey`, which identifies the BFF for its own outbound Hub calls.'
)]
#[OA\Tag(
    name: 'System',
    description: 'Health and readiness endpoints'
)]
#[OA\Tag(
    name: 'Users',
    description: 'Proxy endpoints to the hub\'s `/api/v1/users` resource'
)]
#[OA\Tag(
    name: 'Me',
    description: 'Aggregator endpoints scoped to the authenticated user'
)]
#[OA\Tag(
    name: 'PublicRead',
    description: 'Unauthenticated, app-key-gated public content surface consumed by teatromuseo-web'
)]
class OpenApi
{
    // This class only holds OpenAPI annotations
}
