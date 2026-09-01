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
     * Controllers/methods allowed to bypass strict BaseProxyController conventions.
     *
     * HealthController is keeping thin (no BaseProxyController overhead) as an infrastructure probe.
     * PublicRead controllers are local SQL read adapters, not upstream
     * proxies; they must translate database failures into the versioned
     * unavailable envelope instead of relying on BaseProxyController's
     * ApiException path.
     */
    private const ALLOWED_INFRA_CONTROLLERS = [
        'app/Controllers/Api/V1/System/HealthController.php',
        'app/Controllers/Api/V1/PublicRead/CmsPublicReadController.php',
        'app/Controllers/Api/V1/PublicRead/CatalogPublicReadController.php',
        'app/Controllers/Api/V1/PublicRead/EventPublicReadController.php',
        'app/Controllers/Api/V1/PublicRead/PageResolutionController.php',
    ];

    public function testApiV1ControllersExtendBaseProxyControllerAndFollowConventions(): void
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

            $isInfra = in_array($relative, self::ALLOWED_INFRA_CONTROLLERS, true);
            if ($isInfra) {
                continue;
            }

            if (! str_contains($source, 'extends BaseProxyController')) {
                $violations[] = $relative . ': must extend BaseProxyController';
                continue;
            }

            if (preg_match('/\btry\s*\{/', $source) === 1) {
                $violations[] = $relative . ': try/catch not allowed; BaseProxyController handles upstream exceptions';
            }
        }

        $this->assertSame([], $violations, "BFF Controller convention violations:\n- " . implode("\n- ", $violations));
    }
}
