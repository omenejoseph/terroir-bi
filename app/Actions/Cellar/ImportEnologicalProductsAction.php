<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\EnologicalStockSource;
use App\Models\EnologicalProduct;
use App\Services\Cellar\EnologicalStockLedger;
use App\Support\Money\UnitPrice;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Paste-in import of enological products (TSV from a spreadsheet, or CSV).
 * The first row is the header; recognised columns (English or Croatian):
 * name, category, unit, stock, min stock, cost per unit (major units),
 * manufacturer, packaging, SO₂ uplift, notes. Rows are matched to existing
 * products by name; a changed stock is booked as an "import" movement.
 * With `dry_run` the parsed rows and errors come back and nothing is saved.
 */
class ImportEnologicalProductsAction
{
    private const HEADERS = [
        'name' => ['name', 'naziv', 'product', 'proizvod'],
        'category' => ['category', 'kategorija'],
        'unit' => ['unit', 'jedinica', 'jm'],
        'current_stock' => ['stock', 'current stock', 'zaliha', 'stanje'],
        'min_stock' => ['min stock', 'minimum', 'min zaliha'],
        'cost_per_unit' => ['cost', 'cost per unit', 'price', 'cijena'],
        'manufacturer' => ['manufacturer', 'proizvođač', 'proizvodac'],
        'packaging_size' => ['packaging', 'pakiranje'],
        'so2_uplift_per_unit' => ['so2 uplift', 'uplift'],
        'notes' => ['notes', 'napomena'],
    ];

    public function __construct(private readonly EnologicalStockLedger $ledger) {}

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, created: int, updated: int}
     */
    public function execute(string $text, bool $dryRun): array
    {
        [$rows, $errors] = $this->parse($text);
        $result = ['rows' => $rows, 'errors' => $errors, 'created' => 0, 'updated' => 0];

        if ($dryRun || $errors !== []) {
            return $result;
        }

        return DB::transaction(function () use ($rows, $result): array {
            foreach ($rows as $row) {
                $existing = EnologicalProduct::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $row['name'])])->first();
                $stock = $row['current_stock'] ?? null;
                unset($row['current_stock']);

                if ($existing === null) {
                    $product = EnologicalProduct::create($row + ['category' => 'OTHER', 'unit' => 'g', 'current_stock' => 0]);
                    $result['created']++;
                } else {
                    $existing->fill($row)->save();
                    $product = $existing;
                    $result['updated']++;
                }

                if ($stock !== null) {
                    $delta = Quantity::sub(Quantity::normalize((string) $stock), (string) $product->current_stock);
                    if (Quantity::compare($delta, '0.000') !== 0) {
                        $this->ledger->move($product, $delta, EnologicalStockSource::Import, null, 'Imported stock count');
                    }
                }
            }

            return $result;
        });
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function parse(string $text): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($text)) ?: [], fn (string $l): bool => trim($l) !== ''));
        if (count($lines) < 2) {
            return [[], ['Paste a header row and at least one product row.']];
        }

        $delimiter = str_contains($lines[0], "\t") ? "\t" : (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
        $header = array_map(fn (?string $h): string => mb_strtolower(trim((string) $h)), str_getcsv($lines[0], $delimiter, '"', '\\'));

        $columns = [];
        foreach ($header as $i => $h) {
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($h, $aliases, true)) {
                    $columns[$i] = $field;
                }
            }
        }
        if (! in_array('name', $columns, true)) {
            return [[], ['The header needs a "name" column.']];
        }

        $rows = [];
        $errors = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $row = [];
            foreach ($columns as $i => $field) {
                $value = trim((string) ($cells[$i] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (in_array($field, ['current_stock', 'min_stock', 'cost_per_unit', 'so2_uplift_per_unit'], true)) {
                    $number = str_replace(',', '.', $value);
                    if (! is_numeric($number)) {
                        $errors[] = 'Row '.($n + 2).": {$field} \"{$value}\" is not a number.";

                        continue;
                    }
                    $value = $field === 'cost_per_unit' ? (float) UnitPrice::normalize((float) $number * 100) : $number;
                }
                $row[$field] = $value;
            }
            if (! isset($row['name'])) {
                $errors[] = 'Row '.($n + 2).': missing name.';

                continue;
            }
            $rows[] = $row;
        }

        return [$rows, $errors];
    }
}
