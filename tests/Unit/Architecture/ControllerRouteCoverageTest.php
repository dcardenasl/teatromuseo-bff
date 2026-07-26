<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guardrail to prevent orphan API controllers that are not reachable from routes.
 */
class ControllerRouteCoverageTest extends CIUnitTestCase
{
    public function testApiV1ControllersAreReferencedInRoutes(): void
    {
        $root = rtrim((string) ROOTPATH, DIRECTORY_SEPARATOR);
        $controllersDir = $root . DIRECTORY_SEPARATOR . 'app/Controllers/Api/V1';
        $routesPath = $root . DIRECTORY_SEPARATOR . 'app/Config/Routes.php';
        $modularRoutesDir = $root . DIRECTORY_SEPARATOR . 'app/Config/Routes/v1';

        // Load all route sources to check references
        $routesSource = (string) file_get_contents($routesPath);

        if (is_dir($modularRoutesDir)) {
            $files = glob($modularRoutesDir . '/*.php') ?: [];
            foreach ($files as $file) {
                $routesSource .= (string) file_get_contents($file);
            }
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($controllersDir));
        $violations = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();
            if (!str_ends_with($filename, 'Controller.php')) {
                continue;
            }

            $className = substr($filename, 0, -4); // trim ".php"
            if (!str_contains($routesSource, $className . '::')) {
                $relative = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $violations[] = "{$relative}: controller is not referenced in app/Config/Routes.php";
            }
        }

        $this->assertSame([], $violations, "Controller route coverage violations:\n- " . implode("\n- ", $violations));
    }
}
