<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\CellarTastingNote;
use App\Models\TastingReport;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A tasting report: the list shows title, date, author, wine count, average
 * score and the first wines; the detail adds every wine with its scores and
 * notes. Expects `notes.wineLot`, `notes.vessel` and `createdBy` loaded.
 *
 * @implements Arrayable<string, mixed>
 */
final class TastingReportData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly TastingReport $report,
        public readonly bool $withDetail = false,
    ) {}

    public static function fromModel(TastingReport $report, bool $withDetail = false): self
    {
        return new self($report, $withDetail);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $r = $this->report;
        $notes = $r->notes;
        $scores = $notes->pluck('score')->filter(fn ($s): bool => $s !== null);

        $payload = [
            'id' => $r->getKey(),
            'title' => $r->title,
            'date' => $r->date->toIso8601String(),
            'note' => $r->note,
            'author' => $r->createdBy !== null ? ['id' => $r->createdBy->getKey(), 'name' => $r->createdBy->fullName()] : null,
            'wine_count' => $notes->count(),
            'average_score' => $scores->isNotEmpty() ? round((float) $scores->avg(), 1) : null,
            'wines_preview' => array_values($notes->take(4)->map(fn (CellarTastingNote $n): ?string => $n->wineLot?->name)->filter()->all()),
        ];

        if ($this->withDetail) {
            $payload['wines'] = array_values($notes->map(fn (CellarTastingNote $n): array => [
                'note_id' => $n->getKey(),
                'wine_lot' => $n->wineLot !== null ? [
                    'id' => $n->wineLot->getKey(),
                    'lot_number' => $n->wineLot->lot_number,
                    'name' => $n->wineLot->name,
                    'vintage' => $n->wineLot->vintage,
                ] : null,
                'vessel' => $n->vessel !== null ? ['id' => $n->vessel->getKey(), 'name' => $n->vessel->name] : null,
                'score' => $n->score,
                'appearance' => $n->appearance,
                'nose' => $n->nose,
                'palate' => $n->palate,
                'overall' => $n->overall,
                'note' => $n->note,
            ])->all());
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
