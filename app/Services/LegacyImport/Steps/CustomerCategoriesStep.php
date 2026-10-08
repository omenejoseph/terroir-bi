<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The old app's customer categories ("Restaurant", "Hotel", …) and which customer wore which.
 *
 * In the legacy data a customer's descriptive label is the free text in `customerType`, picked
 * from the CustomerCategory list. The fixed sales channel (CustomersStep) is derived from the
 * same text, so this step keeps the wording the channel mapping would otherwise discard.
 *
 * Deliberately non-destructive so it is safe to run on a tenant whose customers staff have
 * already edited: it only creates categories that don't exist yet and only labels customers
 * that have no category, never touching any other customer field.
 */
class CustomerCategoriesStep extends AbstractStep
{
    public function name(): string
    {
        return 'customer_categories';
    }

    public function dependsOn(): array
    {
        return ['customers'];
    }

    public function run(ImportContext $ctx): void
    {
        $byName = $this->existing($ctx);
        $next = (int) DB::table('customer_categories')->where('tenant_id', $ctx->tenant->getKey())->max('sort_order');

        foreach ($ctx->legacy->table('CustomerCategory')->orderBy('sortOrder')->orderBy('name')->get() as $r) {
            $ctx->report->read('customer_categories');
            $key = $this->key((string) $r->name);
            $existing = $byName[$key] ?? null;

            if ($existing !== null) {
                // Already there (re-run, or created by hand in the new app): adopt it, change nothing.
                $ctx->ids->put('CustomerCategory', (string) $r->id, $existing);
                $ctx->report->written('customer_categories');

                continue;
            }

            $byName[$key] = $this->put($ctx, 'CustomerCategory', (string) $r->id, 'customer_categories', [
                'name' => trim((string) $r->name),
                'sort_order' => (int) $r->sortOrder,
                'is_active' => Normalize::bool($r->isActive),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => now(),
            ]);
            $next = max($next, (int) $r->sortOrder);
            $ctx->report->written('customer_categories');
        }

        $this->assign($ctx, $byName, $next);
    }

    /**
     * @param  array<string, string>  $byName  normalised name => category id
     */
    private function assign(ImportContext $ctx, array &$byName, int $next): void
    {
        $assigned = 0;

        foreach ($this->rows($ctx, 'Customer') as $r) {
            $label = Normalize::str($r->customerType);
            $customer = $ctx->ids->get('Customer', (string) $r->id);
            if ($label === null || $customer === null) {
                continue;
            }

            $ctx->report->read('customer_category_labels');
            $key = $this->key($label);

            if (! isset($byName[$key])) {
                // A label that was never on the category list: keep the wording rather than lose it.
                $byName[$key] = $this->create($ctx, $label, ++$next);
                $ctx->report->warn('customer_categories', "customer label '{$label}' was not on the category list; created it");
            }

            $assigned += DB::table('customers')
                ->where('id', $customer)
                ->whereNull('customer_category_id')
                ->update(['customer_category_id' => $byName[$key]]);
            $ctx->report->written('customer_category_labels');
        }

        if ($assigned === 0) {
            $ctx->report->warn('customer_categories', 'no customer needed a category label (already labelled, or none in legacy)');
        }
    }

    private function create(ImportContext $ctx, string $name, int $order): string
    {
        $id = (string) Str::ulid();
        DB::table('customer_categories')->insert([
            'id' => $id,
            'tenant_id' => (string) $ctx->tenant->getKey(),
            'name' => $name,
            'sort_order' => $order,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, string> normalised name => category id */
    private function existing(ImportContext $ctx): array
    {
        $out = [];
        foreach (DB::table('customer_categories')->where('tenant_id', $ctx->tenant->getKey())->get(['id', 'name']) as $c) {
            $out[$this->key((string) $c->name)] = (string) $c->id;
        }

        return $out;
    }

    private function key(string $name): string
    {
        return Str::lower(trim($name));
    }
}
