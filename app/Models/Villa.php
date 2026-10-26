<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A rentable villa, with the content its guest portal shows. Retired with `is_active = false`,
 * never deleted, so its bookings keep their villa.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $portal_token
 * @property string|null $address
 * @property string|null $coordinates
 * @property string|null $wifi_name
 * @property string|null $wifi_password
 * @property string|null $check_in_time
 * @property string|null $check_out_time
 * @property string|null $instructions
 * @property string|null $rules
 * @property string|null $welcome
 * @property string|null $transport
 * @property string|null $amenities
 * @property string|null $emergency_phone
 * @property string|null $contact_phone
 * @property string|null $contact_email
 * @property string|null $logo
 * @property list<string>|null $portal_tab_order
 * @property bool $is_active
 */
class Villa extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name', 'slug', 'portal_token', 'address', 'coordinates', 'wifi_name', 'wifi_password', 'check_in_time',
        'check_out_time', 'instructions', 'rules', 'welcome', 'transport', 'amenities', 'emergency_phone',
        'contact_phone', 'contact_email', 'logo', 'portal_tab_order', 'is_active',
    ];

    protected $attributes = ['is_active' => true];

    protected $hidden = ['wifi_password'];

    protected function casts(): array
    {
        return [
            'portal_tab_order' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function newPortalToken(): string
    {
        return Str::random(40);
    }

    /**
     * @return HasMany<VillaRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(VillaRate::class)->orderBy('start_date');
    }

    /**
     * @return HasMany<VillaSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(VillaSection::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<VillaBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(VillaBooking::class);
    }

    /**
     * @return HasMany<VillaMenuItem, $this>
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(VillaMenuItem::class)->orderBy('category')->orderBy('sort_order');
    }
}
