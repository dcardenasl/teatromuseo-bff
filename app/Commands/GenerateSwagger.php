<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class GenerateSwagger extends BaseCommand
{
    protected $group       = 'API';
    protected $name        = 'swagger:generate';
    protected $description = 'Generate OpenAPI/Swagger documentation';
    protected $usage       = 'swagger:generate';

    public function run(array $params)
    {
        CLI::write('Generating OpenAPI documentation...', 'yellow');

        try {
            $appPath = APPPATH;
            $outputPath = FCPATH . 'swagger.json';

            // Scan directories for OpenAPI annotations
            $openapi = (new \OpenApi\Generator())
                ->generate([
                    $appPath . 'Config/OpenApi.php',
                    $appPath . 'Controllers/',
                    $appPath . 'Documentation/',
                    $appPath . 'DTO/',
                ]);

            // Write to file
            file_put_contents($outputPath, $openapi->toJson());

            // Calculate statistics (components properties may be UNDEFINED sentinel when empty)
            $paths = $openapi->paths ?? [];
            $schemas = is_array($openapi->components->schemas ?? null) ? $openapi->components->schemas : [];
            $responses = is_array($openapi->components->responses ?? null) ? $openapi->components->responses : [];
            $requestBodies = is_array($openapi->components->requestBodies ?? null) ? $openapi->components->requestBodies : [];
            $endpointCount = count($paths);
            $schemaCount = count($schemas);
            $responseCount = count($responses);
            $requestBodyCount = count($requestBodies);

            CLI::write('OpenAPI documentation generated successfully!', 'green');
            CLI::write('Location: ' . $outputPath, 'green');
            CLI::write('', '');
            CLI::write('Statistics:', 'cyan');
            CLI::write('  Endpoints: ' . $endpointCount, 'white');
            CLI::write('  Schemas: ' . $schemaCount, 'white');
            CLI::write('  Reusable Responses: ' . $responseCount, 'white');
            CLI::write('  Request Bodies: ' . $requestBodyCount, 'white');
            CLI::write('', '');
            CLI::write('You can view it at: http://localhost:8180/swagger.json', 'cyan');
        } catch (\Exception $e) {
            CLI::error('Failed to generate OpenAPI documentation');
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
