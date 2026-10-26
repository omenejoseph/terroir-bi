<?php

declare(strict_types=1);

namespace App\Actions\Accommodation;

use App\Models\Villa;
use App\Models\VillaMenuItem;
use Illuminate\Support\Facades\DB;

class SaveVillaMenuItemAction
{
    /**
     * Add or edit a menu item. A new one goes to the end of its category. With `$everyVilla` a new
     * item is added to every active villa at once (the old app's "create for all villas").
     *
     * @param  array<string, mixed>  $data
     * @return list<VillaMenuItem>
     */
    public function execute(array $data, ?VillaMenuItem $item = null, bool $everyVilla = false): array
    {
        if ($item !== null) {
            $item->update($data);

            return [$item];
        }

        return DB::transaction(function () use ($data, $everyVilla): array {
            $villaIds = $everyVilla
                ? array_values(array_map('strval', Villa::query()->where('is_active', true)->pluck('id')->all()))
                : [(string) $data['villa_id']];

            $created = [];
            foreach ($villaIds as $villaId) {
                $next = (int) VillaMenuItem::query()->where('villa_id', $villaId)->where('category', $data['category'])->max('sort_order') + 1;
                $created[] = VillaMenuItem::create(['villa_id' => $villaId, 'sort_order' => $next] + $data);
            }

            return $created;
        });
    }
}
