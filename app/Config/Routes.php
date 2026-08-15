<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

if (ENVIRONMENT !== 'production') {
    $routes->get('/api/docs', static function () {
        $swaggerJsonUrl = base_url('swagger.json');
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>API Docs</title>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
            </head>
            <body>
            <div id="swagger-ui"></div>
            <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
            <script>
                SwaggerUIBundle({
                    url: "{$swaggerJsonUrl}",
                    dom_id: '#swagger-ui',
                    presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
                    layout: 'BaseLayout',
                    deepLinking: true,
                });
            </script>
            </body>
            </html>
            HTML;
    });
}

$routes->get('/', static function () {
    return response()->setJSON([
        'name'        => \Config\Project::NAME,
        'version'     => \Config\Project::VERSION,
        'description' => \Config\Project::DESCRIPTION,
        'documentation' => [
            'openapi' => base_url('swagger.json'),
        ],
        'timestamp' => date('Y-m-d H:i:s'),
    ])->setStatusCode(200);
});

$routes->get('/api/versions', static function () {
    /** @var \Config\Api $apiConfig */
    $apiConfig = config('Api');
    $current = null;
    $versions = [];

    foreach ($apiConfig->apiVersions as $key => $entry) {
        $versions[] = array_merge(['version' => $key], $entry);
        if (($entry['status'] ?? null) === 'current') {
            $current = $key;
        }
    }

    return response()->setJSON([
        'current'  => $current,
        'versions' => $versions,
    ])->setStatusCode(200);
});

// System/Health routes at root level (ping, health, ready, live).
// The same route file is also loaded under /api/v1 below so clients using the
// versioned API client can use the health contract without a special transport
// path. Keeping one route definition prevents the two surfaces from drifting.
if (file_exists(APPPATH . 'Config/Routes/v1/system.php')) {
    require APPPATH . 'Config/Routes/v1/system.php';
}

// Module routes (proxy/aggregate endpoints) under /api/v1.
// Add files under app/Config/Routes/v1/*.php to expose new endpoints.
$routes->group('api/v1', function ($routes): void {
    $routesDir = APPPATH . 'Config/Routes/v1';

    if (is_dir($routesDir)) {
        $files = glob($routesDir . '/*.php');
        foreach ($files as $file) {
            require $file;
        }
    }
});
