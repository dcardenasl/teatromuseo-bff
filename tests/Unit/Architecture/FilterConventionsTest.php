<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use CodeIgniter\Test\CIUnitTestCase;

class FilterConventionsTest extends CIUnitTestCase
{
    public function testFiltersDeclareStrictTypesAndUseServicesFacade(): void
    {
        $root = rtrim((string) ROOTPATH, DIRECTORY_SEPARATOR);
        $dir = $root . DIRECTORY_SEPARATOR . 'app/Filters';

        $this->assertDirectoryExists($dir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = $file->getPathname();
            $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            $source = file_get_contents($path);

            if (! is_string($source) || $source === '') {
                continue;
            }

            if (! str_contains($source, 'declare(strict_types=1);')) {
                $violations[] = "{$relative}: missing declare(strict_types=1);";
            }

            if (preg_match('/\bservice\s*\(\s*(?:\'[^\']+\'|"[^"]+")\s*\)/', $source) === 1) {
                $violations[] = "{$relative}: use Config\\Services facade instead of service('...')";
            }
        }

        $this->assertSame([], $violations, "Filter conventions violations:\n- " . implode("\n- ", $violations));
    }
}
