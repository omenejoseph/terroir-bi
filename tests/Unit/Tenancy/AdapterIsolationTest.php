<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Guards the tenancy abstraction: the stancl/tenancy driver must only be
 * referenced from the adapter directory (and the Tenant model, which must
 * declare the driver contract). A swap to another tenancy library should touch
 * only those allowed paths.
 */
class AdapterIsolationTest extends TestCase
{
    /**
     * Paths (relative to app/) allowed to reference the stancl driver.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'Tenancy/Adapters/Stancl/',
        'Models/Tenant.php', // must declare `implements Stancl\...\Tenant`
    ];

    #[DataProvider('phpFilesProvider')]
    public function test_stancl_is_only_referenced_from_allowed_paths(string $relativePath, string $contents): void
    {
        if ($this->isAllowed($relativePath)) {
            $this->assertTrue(true);

            return;
        }

        // Match the vendor package namespace (Stancl\Tenancy), not our own
        // adapter namespace (App\Tenancy\Adapters\Stancl).
        $this->assertStringNotContainsString(
            'Stancl\\Tenancy',
            $contents,
            "Application file [app/{$relativePath}] must not reference the stancl/tenancy driver directly. ".
            'Route it through app/Tenancy/Adapters/Stancl instead.',
        );
    }

    public static function phpFilesProvider(): iterable
    {
        $appPath = dirname(__DIR__, 3).'/app';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($appPath.'/', '', $file->getPathname());

            yield $relative => [$relative, (string) file_get_contents($file->getPathname())];
        }
    }

    private function isAllowed(string $relativePath): bool
    {
        foreach (self::ALLOWED as $allowed) {
            if (str_starts_with($relativePath, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
