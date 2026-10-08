<?php

declare(strict_types=1);

namespace App\Services\LegacyImport;

/** Collects per-step counts and warnings; rendered by the command and written as JSON. */
class Report
{
    /** @var array<string, array{read: int, written: int, skipped: int}> */
    private array $counts = [];

    /** @var list<string> */
    private array $warnings = [];

    public function read(string $step, int $n = 1): void
    {
        $c = $this->entry($step);
        $c['read'] += $n;
        $this->counts[$step] = $c;
    }

    public function written(string $step, int $n = 1): void
    {
        $c = $this->entry($step);
        $c['written'] += $n;
        $this->counts[$step] = $c;
    }

    public function skipped(string $step, string $reason, int $n = 1): void
    {
        $c = $this->entry($step);
        $c['skipped'] += $n;
        $this->counts[$step] = $c;
        $this->warn($step, $reason);
    }

    public function warn(string $step, string $message): void
    {
        $this->warnings[] = "[$step] $message";
    }

    /** @var array<string, array{rows: int, reason: string}> */
    private array $notMigrated = [];

    /** @param array<string, array{rows: int, reason: string}> $tables */
    public function setNotMigrated(array $tables): void
    {
        $this->notMigrated = $tables;
    }

    /** @return array<string, array{rows: int, reason: string}> */
    public function notMigrated(): array
    {
        return $this->notMigrated;
    }

    /** @return array<string, array{read: int, written: int, skipped: int}> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array{counts: array<string, array{read: int, written: int, skipped: int}>, warnings: list<string>, not_migrated: array<string, array{rows: int, reason: string}>} */
    public function toArray(): array
    {
        return ['counts' => $this->counts, 'warnings' => $this->warnings, 'not_migrated' => $this->notMigrated];
    }

    /** @return array{read: int, written: int, skipped: int} */
    private function entry(string $step): array
    {
        return $this->counts[$step] ?? ['read' => 0, 'written' => 0, 'skipped' => 0];
    }
}
