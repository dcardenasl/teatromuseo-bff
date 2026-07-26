<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guardrail for runtime DI conventions in commands/filters.
 */
class RuntimeInstantiationConventionsTest extends CIUnitTestCase
{
    /**
     * @return array<int, string>
     */
    private function scanTargets(): array
    {
        $root = rtrim((string) ROOTPATH, DIRECTORY_SEPARATOR);
        $targets = [];

        foreach (['app/Commands', 'app/Filters'] as $dir) {
            $absoluteDir = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($absoluteDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absoluteDir));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                if (str_ends_with($file->getFilename(), '.php')) {
                    $targets[] = $file->getPathname();
                }
            }
        }

        sort($targets);

        return $targets;
    }

    public function testCommandsAndFiltersAvoidDirectRuntimeInstantiation(): void
    {
        $root = rtrim((string) ROOTPATH, DIRECTORY_SEPARATOR);
        $violations = [];

        foreach ($this->scanTargets() as $path) {
            $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            if ($relative === 'app/Commands/MakeCrud.php') {
                // Scaffolding template strings intentionally contain "new ...".
                continue;
            }

            $source = file_get_contents($path);
            if (!is_string($source) || $source === '') {
                continue;
            }

            if (preg_match('/new\s+\\\\?[\w\\\\]+Model\s*\(/', $source) === 1) {
                $violations[] = "{$relative}: use model()/Services instead of new *Model()";
            }

            if (preg_match('/new\s+QueueManager\s*\(/', $source) === 1) {
                $violations[] = "{$relative}: use Services::queueManager() instead of new QueueManager()";
            }
        }

        $this->assertSame([], $violations, "Runtime instantiation convention violations:\n- " . implode("\n- ", $violations));
    }
}
