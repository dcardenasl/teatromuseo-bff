<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Architecture guardrail for API controller conventions.
 *
 * Prevents regressions where controllers re-implement ApiController pipeline
 * for JSON endpoints instead of using handleRequest().
 */
class ControllerConventionsTest extends CIUnitTestCase
{
    /**
     * Controllers/methods allowed to bypass strict JSON handleRequest convention.
     *
     * Non-JSON transport endpoints are allowed (download/stream).
     * Infra controllers are not under app/Controllers/Api/V1 nor ApiController descendants.
     */
    private const ALLOWED_TRY_CATCH_CONTROLLERS = [
        'app/Controllers/Api/V1/Files/FileController.php',
    ];

    public function testApiV1ControllersDoNotReimplementHandleRequestPipeline(): void
    {
        $root = rtrim((string) ROOTPATH, DIRECTORY_SEPARATOR);
        $controllerPaths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app/Controllers/Api/V1')
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if (substr($file->getFilename(), -14) === 'Controller.php') {
                $controllerPaths[] = $file->getPathname();
            }
        }

        $violations = [];

        foreach ($controllerPaths as $path) {
            $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            $source = file_get_contents($path);

            if (! is_string($source) || $source === '') {
                continue;
            }

            if (! str_contains($source, 'extends ApiController')) {
                continue;
            }

            $allowsTryCatch = in_array($relative, self::ALLOWED_TRY_CATCH_CONTROLLERS, true);

            if (preg_match('/\bcollectRequestData\s*\(/', $source) === 1) {
                $violations[] = $relative . ': collectRequestData() must not be called directly in concrete controller';
            }

            if (preg_match('/protected\s+string\s+\$serviceName\s*=/', $source) === 1) {
                $violations[] = $relative . ': serviceName locator property is forbidden; resolve service explicitly';
            }

            if (preg_match('/\bgetService\s*\(/', $source) === 1) {
                $violations[] = $relative . ': getService() locator usage is forbidden; use explicit service property';
            }

            if (preg_match('/\bresolveDefaultService\s*\(/', $source) !== 1) {
                $violations[] = $relative . ': missing resolveDefaultService() explicit dependency contract';
            }

            if (! $allowsTryCatch && preg_match('/\btry\s*\{/', $source) === 1) {
                $violations[] = $relative . ': try/catch not allowed; use ApiController::handleRequest()';
            }

            if (! $allowsTryCatch && preg_match('/\bhandleException\s*\(/', $source) === 1) {
                $violations[] = $relative . ': direct handleException() usage not allowed in concrete controller';
            }
        }

        $this->assertSame([], $violations, "Controller convention violations:\n- " . implode("\n- ", $violations));
    }
}
