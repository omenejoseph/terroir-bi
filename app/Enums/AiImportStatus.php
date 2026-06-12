<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an AI import batch, from upload through extraction to commit.
 */
enum AiImportStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case PartiallyCommitted = 'partially_committed';
    case Committed = 'committed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Processing => 'Processing',
            self::Ready => 'Ready for review',
            self::PartiallyCommitted => 'Partially committed',
            self::Committed => 'Committed',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Committed || $this === self::Failed;
    }
}
