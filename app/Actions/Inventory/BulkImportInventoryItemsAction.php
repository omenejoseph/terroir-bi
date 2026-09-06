<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Bulk Import (Inventory Analytics/Spend, Figma 382:1592/386:1673) — one CSV,
 * matched against the catalog by SKU: a row whose SKU already exists updates
 * that item (only the columns the row actually fills in); any other row
 * creates one. Writes go through the same CreateInventoryItemAction/
 * UpdateInventoryItemAction every other write path uses.
 *
 * A bad row is skipped, not fatal — one malformed line in a 500-row sheet
 * should not cost the other 499, so this returns a report rather than
 * throwing on the first problem.
 */
class BulkImportInventoryItemsAction
{
    /** A hard ceiling so a mistaken 200MB export doesn't turn into 200,000 writes. */
    private const MAX_ROWS = 5000;

    /** @var list<string> The columns a row may fill in, header-row order. */
    public const COLUMNS = [
        'name', 'sku', 'category', 'group', 'unit',
        'current_stock', 'min_stock', 'bottles_per_case', 'cost_per_unit', 'default_price',
    ];

    public function __construct(
        private readonly CreateInventoryItemAction $create,
        private readonly UpdateInventoryItemAction $update,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: list<array{row: int, reason: string}>}
     */
    public function execute(UploadedFile $file): array
    {
        $created = 0;
        $updated = 0;
        $skipped = [];

        // Materialized only up to the row ceiling — not the whole file — so
        // a mistaken 200MB export still only ever holds MAX_ROWS rows in
        // memory at once, same as the line-by-line generator it replaces
        // for this part.
        /** @var array<int, array<string, string>> $rows */
        $rows = [];
        foreach ($this->rows($file) as $number => $row) {
            if ($number > self::MAX_ROWS) {
                $skipped[] = ['row' => $number, 'reason' => 'File exceeds the '.self::MAX_ROWS.'-row limit — split it into smaller files.'];
                break;
            }
            $rows[$number] = $row;
        }

        // Tracks a SKU already written by an earlier row in this same file, so
        // a repeat further down updates it instead of racing CreateInventory
        // ItemAction's own unique-SKU constraint. Pre-seeded with one query
        // for every SKU the file actually contains, rather than one query
        // per row the first time each SKU is encountered.
        /** @var array<string, InventoryItem> $seen */
        $seen = [];
        $skus = array_values(array_unique(array_filter(
            array_map(fn (array $row): string => trim((string) ($row['sku'] ?? '')), $rows),
        )));
        if ($skus !== []) {
            $seen = InventoryItem::query()->whereIn('sku', $skus)->get()
                ->keyBy(fn (InventoryItem $i): string => (string) $i->sku)
                ->all();
        }

        foreach ($rows as $number => $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                $skipped[] = ['row' => $number, 'reason' => 'Missing SKU.'];

                continue;
            }

            $category = null;
            if (trim((string) ($row['category'] ?? '')) !== '') {
                $category = InventoryCategory::tryFrom(strtoupper(trim($row['category'])));
                if ($category === null) {
                    $skipped[] = ['row' => $number, 'reason' => "Unrecognised category \"{$row['category']}\"."];

                    continue;
                }
            }

            $existing = $seen[$sku] ?? null;

            try {
                $attributes = $this->attributes($row, $category, isCreate: $existing === null);

                if ($existing !== null) {
                    $data = $this->update->execute($existing, $attributes);
                    $updated++;
                } else {
                    if (trim((string) ($row['name'] ?? '')) === '') {
                        $skipped[] = ['row' => $number, 'reason' => 'Missing name for a new item.'];

                        continue;
                    }
                    $data = $this->create->execute(['sku' => $sku, ...$attributes]);
                    $created++;
                }

                // fromModel() (used by both actions) always sets this; the
                // guard is for the type system, not a real runtime case.
                if ($data->model instanceof InventoryItem) {
                    $seen[$sku] = $data->model;
                }
            } catch (Throwable $e) {
                $skipped[] = ['row' => $number, 'reason' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row, ?InventoryCategory $category, bool $isCreate): array
    {
        $attributes = [];

        if (trim((string) ($row['name'] ?? '')) !== '') {
            $attributes['name'] = trim($row['name']);
        }
        if ($category !== null) {
            $attributes['category'] = $category->value;
        } elseif ($isCreate) {
            $attributes['category'] = InventoryCategory::Finished->value;
        }
        if (trim((string) ($row['group'] ?? '')) !== '') {
            $attributes['group'] = trim($row['group']);
        }
        if (trim((string) ($row['unit'] ?? '')) !== '') {
            $attributes['unit'] = trim($row['unit']);
        } elseif ($isCreate) {
            $attributes['unit'] = 'bottles';
        }
        if (($stock = $this->numeric($row['current_stock'] ?? null)) !== null) {
            $attributes['current_stock'] = $stock;
        }
        if (($min = $this->numeric($row['min_stock'] ?? null)) !== null) {
            $attributes['min_stock'] = $min;
        }
        if (($pack = $row['bottles_per_case'] ?? null) !== null && trim((string) $pack) !== '') {
            $attributes['bottles_per_case'] = (int) $pack;
        }
        if (($cost = $this->money($row['cost_per_unit'] ?? null)) !== null) {
            $attributes['cost_per_unit'] = $cost;
        }
        if (($price = $this->money($row['default_price'] ?? null)) !== null) {
            $attributes['default_price'] = $price;
        }

        return $attributes;
    }

    private function numeric(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return is_numeric(trim($value)) ? trim($value) : null;
    }

    /** A major-unit decimal cell ("12.50") to integer minor units (1250). */
    private function money(?string $value): ?int
    {
        if ($value === null || trim($value) === '' || ! is_numeric(trim($value))) {
            return null;
        }

        return (int) round((float) trim($value) * 100);
    }

    /**
     * @return iterable<int, array<string, string>> 1-indexed by data row (the
     *                                              header itself is row 0), so a reported row number matches what a
     *                                              spreadsheet editor shows.
     */
    private function rows(UploadedFile $file): iterable
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return;
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return;
            }
            $header = array_map(fn (?string $h): string => strtolower(trim($h ?? '')), $header);

            $number = 1;
            while (($cells = fgetcsv($handle)) !== false) {
                // A blank line (fgetcsv's own end-of-data quirk for a single
                // empty column) carries nothing to import.
                if ($cells === [null] || $cells === ['']) {
                    $number++;

                    continue;
                }

                // A row with more or fewer cells than the header (a stray
                // comma, a trailing blank column) is padded/truncated to
                // match rather than rejected outright.
                $cells = array_slice(array_pad($cells, count($header), ''), 0, count($header));

                yield $number => array_combine($header, array_map(fn (?string $v): string => $v ?? '', $cells));
                $number++;
            }
        } finally {
            fclose($handle);
        }
    }
}
