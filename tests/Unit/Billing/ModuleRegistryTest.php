<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Authorization\ModuleRegistry;
use App\Enums\Module;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    public function test_every_module_is_mapped(): void
    {
        $caps = ModuleRegistry::capabilities();
        $prefixes = ModuleRegistry::pathPrefixes();

        foreach (Module::all() as $module) {
            $this->assertArrayHasKey($module->value, $caps, "missing caps for {$module->value}");
            $this->assertArrayHasKey($module->value, $prefixes, "missing prefixes for {$module->value}");
            $this->assertNotEmpty($prefixes[$module->value], "no path prefix for {$module->value}");
        }
    }

    public function test_finance_capability_is_shared_by_three_modules(): void
    {
        $modules = ModuleRegistry::modulesForCapability('finance.view');
        $values = array_map(fn (Module $m) => $m->value, $modules);

        sort($values);
        $this->assertSame(['cash_flow', 'costs', 'inflows'], $values);
    }

    public function test_path_prefixes_are_unique_to_one_module(): void
    {
        $seen = [];
        foreach (ModuleRegistry::pathPrefixes() as $module => $prefixes) {
            foreach ($prefixes as $prefix) {
                $this->assertArrayNotHasKey($prefix, $seen, "prefix {$prefix} shared by {$module} and ".($seen[$prefix] ?? ''));
                $seen[$prefix] = $module;
            }
        }
    }
}
