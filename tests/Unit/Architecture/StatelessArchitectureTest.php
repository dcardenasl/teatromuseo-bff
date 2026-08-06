<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guardrail to enforce that the Backend-for-Frontend (BFF) remains completely stateless.
 *
 * It scans the app/ folder to ensure:
 * - No Model imports (App\Models\...) or inheritance from CodeIgniter\Model.
 * - No usage of the model() helper.
 * - No direct Database connection usage (Database::connect, Config\Database, BaseConnection).
 */
final class StatelessArchitectureTest extends CIUnitTestCase
{
    /** @var array<string, string> */
    private const FORBIDDEN_PATTERNS = [
        'use_model' => '/^use\s+App\\\\Models\\\\/m',
        'extends_model' => '/extends\s+(?:\\\\CodeIgniter\\\\)?Model\b/',
        'model_helper' => '/\bmodel\s*\(/',
        'db_connect' => '/\\\\?Database\s*::\s*connect\s*\(/',
        'db_config' => '/^use\s+Config\\\\Database\b/m',
        'db_connection' => '/^use\s+CodeIgniter\\\\Database\\\\/m',
    ];

    public function testCodebaseIsCompletelyStateless(): void
    {
        $root = rtrim((string) ROOTPATH, DIRECTORY_SEPARATOR);
        $appDir = $root . DIRECTORY_SEPARATOR . 'app';

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = $file->getPathname();

            // Skip database migration configuration if it exists
            $relative = str_replace('\\', '/', ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR));
            if (str_starts_with($relative, 'app/Database/') || $relative === 'app/Config/Database.php') {
                continue;
            }

            $source = file_get_contents($path);
            if (!is_string($source) || $source === '') {
                continue;
            }

            // Strip comments and string literals to prevent false positives
            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            foreach (self::FORBIDDEN_PATTERNS as $ruleName => $pattern) {
                $count = preg_match_all($pattern, $code);
                if ($count > 0) {
                    $violations[] = "{$relative}: violating '{$ruleName}' (matched {$count} times)";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Stateless architecture violations found in teatromuseo-bff:\n- " . implode("\n- ", $violations) . "\n\n" .
            "The BFF must remain completely database-free and model-free. All proxy/aggregation flows go directly to the upstream hub or domain APIs."
        );
    }
}
