<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Enums\AiImportLineStatus;
use App\Enums\AiImportStatus;
use App\Models\AiImport;
use Throwable;

/**
 * Commits every committable (approved/edited) line of an import, then advances
 * the import's status to committed or partially_committed. A line that fails to
 * commit is left for the user to fix and doesn't abort the rest.
 *
 * @phpstan-type CommitSummary array{committed: int, failed: int, errors: array<string, string>}
 */
class CommitAiImportAction
{
    public function __construct(private readonly CommitAiImportLineAction $commitLine) {}

    /**
     * @return CommitSummary
     */
    public function execute(AiImport $import, string $userId): array
    {
        $committed = 0;
        $failed = 0;
        $errors = [];

        $lines = $import->lines()
            ->whereIn('status', [AiImportLineStatus::Approved->value, AiImportLineStatus::Edited->value])
            ->get();

        foreach ($lines as $line) {
            try {
                $this->commitLine->execute($line, $userId);
                $committed++;
            } catch (Throwable $e) {
                $failed++;
                $errors[$line->getKey()] = $e->getMessage();
            }
        }

        $import->refresh();
        $remaining = $import->lines()
            ->whereIn('status', [
                AiImportLineStatus::Pending->value,
                AiImportLineStatus::Approved->value,
                AiImportLineStatus::Edited->value,
            ])->count();

        $import->update([
            'status' => $remaining === 0 ? AiImportStatus::Committed : AiImportStatus::PartiallyCommitted,
        ]);

        return ['committed' => $committed, 'failed' => $failed, 'errors' => $errors];
    }
}
