<?php

declare(strict_types=1);

namespace App\Actions\Accommodation;

use App\Models\Villa;
use Illuminate\Support\Str;

class SaveVillaAction
{
    /**
     * Add or edit a villa. The slug follows the name and is made unique within the tenant (the old
     * app crashed on a duplicate); the portal token is minted once and never changes, so printed QR
     * codes keep working.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, ?Villa $villa = null): Villa
    {
        unset($data['slug'], $data['portal_token']);
        $name = (string) ($data['name'] ?? $villa?->name ?? '');

        if ($villa === null) {
            return Villa::create($data + [
                'slug' => $this->uniqueSlug($name, null),
                'portal_token' => Villa::newPortalToken(),
            ]);
        }

        $villa->fill($data);
        if ($villa->isDirty('name')) {
            $villa->slug = $this->uniqueSlug($name, $villa->getKey());
        }
        $villa->save();

        return $villa;
    }

    private function uniqueSlug(string $name, ?string $ignoreId): string
    {
        $base = Str::slug($name) ?: 'villa';
        $slug = $base;

        for ($n = 2; Villa::query()->where('slug', $slug)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
