<?php

declare(strict_types=1);

namespace App\Services\LegacyImport;

interface ImportStep
{
    /** Short key used by `--only` and in the report. */
    public function name(): string;

    /** @return list<string> names of steps that must have run first */
    public function dependsOn(): array;

    public function run(ImportContext $ctx): void;
}
