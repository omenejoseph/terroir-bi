<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Customers\CustomerCategoryOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CustomerCategoryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    private function as(User $user, ?Tenant $tenant = null): static
    {
        return $this->actingAs($user)->withSession([ActiveTenantSession::KEY => ($tenant ?? $this->tenant)->getKey()]);
    }

    private function category(string $name, int $order = 1, bool $active = true, ?Tenant $tenant = null): CustomerCategory
    {
        $this->actingAsTenant($tenant ?? $this->tenant);
        $category = CustomerCategory::create(['name' => $name, 'sort_order' => $order, 'is_active' => $active]);
        $this->forgetTenant();

        return $category;
    }

    private function customer(string $name, ?CustomerCategory $category = null, ?Tenant $tenant = null): Customer
    {
        $this->actingAsTenant($tenant ?? $this->tenant);
        $customer = Customer::create(['company_name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@x.hr', 'customer_category_id' => $category?->getKey()]);
        $this->forgetTenant();

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function post_(string $url, array $data = []): TestResponse
    {
        return $this->as($this->admin)->post($url, $data);
    }

    public function test_the_page_lists_categories_in_order_with_customer_counts(): void
    {
        $restaurant = $this->category('Restaurant', 1);
        $this->category('Hotel', 2, false);
        $this->customer('Konoba', $restaurant);
        $this->customer('Taverna', $restaurant);

        $this->as($this->admin)->get('/customers/categories')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Customers/Categories')
            ->where('categories.0.name', 'Restaurant')
            ->where('categories.0.customers_count', 2)
            ->where('categories.1.name', 'Hotel')
            ->where('categories.1.is_active', false));
    }

    public function test_a_category_can_be_added_renamed_retired_and_deleted(): void
    {
        $this->post_('/customers/categories', ['name' => ' Wine bar '])->assertRedirect();
        $category = CustomerCategory::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Wine bar', $category->name);                 // trimmed
        $this->assertSame(1, $category->sort_order);

        $this->as($this->admin)->patch("/customers/categories/{$category->getKey()}", ['name' => 'Enoteca'])->assertRedirect();
        $this->as($this->admin)->patch("/customers/categories/{$category->getKey()}", ['is_active' => false])->assertRedirect();
        $category->refresh();
        $this->assertSame(['Enoteca', false], [$category->name, $category->is_active]);

        $this->as($this->admin)->delete("/customers/categories/{$category->getKey()}")->assertRedirect();
        $this->assertSame(0, CustomerCategory::withoutGlobalScopes()->count());
    }

    public function test_names_must_be_unique_within_the_organisation_but_not_across_organisations(): void
    {
        $this->category('Hotel');

        $this->post_('/customers/categories', ['name' => 'Hotel'])->assertSessionHasErrors('name');
        $this->post_('/customers/categories', ['name' => ''])->assertSessionHasErrors('name');

        $other = $this->createTenant();
        $otherAdmin = $this->createMember($other, [TenantRole::Admin]);
        $this->as($otherAdmin, $other)->post('/customers/categories', ['name' => 'Hotel'])->assertSessionDoesntHaveErrors();
        $this->assertSame(2, CustomerCategory::withoutGlobalScopes()->where('name', 'Hotel')->count());
    }

    public function test_renaming_to_your_own_name_is_allowed(): void
    {
        $category = $this->category('Hotel');

        $this->as($this->admin)->patch("/customers/categories/{$category->getKey()}", ['name' => 'Hotel', 'is_active' => true])->assertSessionDoesntHaveErrors();
    }

    public function test_deleting_a_category_keeps_its_customers_without_a_label(): void
    {
        $category = $this->category('Hotel');
        $customer = $this->customer('Hotel Marina', $category);

        $this->as($this->admin)->delete("/customers/categories/{$category->getKey()}")->assertRedirect();

        $this->assertNull(DB::table('customers')->where('id', $customer->getKey())->value('customer_category_id'));
        $this->assertSame(1, DB::table('customers')->count());
    }

    public function test_categories_can_be_reordered(): void
    {
        $a = $this->category('A', 1);
        $b = $this->category('B', 2);
        $c = $this->category('C', 3);

        $this->post_('/customers/categories/reorder', ['ids' => [$c->getKey(), $a->getKey(), $b->getKey()]])->assertRedirect();

        $this->assertSame(['C', 'A', 'B'], CustomerCategory::withoutGlobalScopes()->orderBy('sort_order')->pluck('name')->all());
    }

    public function test_another_organisations_categories_cannot_be_touched(): void
    {
        $other = $this->createTenant();
        $theirs = $this->category('Secret', 1, true, $other);

        $this->as($this->admin)->patch("/customers/categories/{$theirs->getKey()}", ['name' => 'Hijacked'])->assertNotFound();
        $this->as($this->admin)->delete("/customers/categories/{$theirs->getKey()}")->assertNotFound();
        $this->post_('/customers/categories/reorder', ['ids' => [$theirs->getKey()]])->assertSessionHasErrors('ids.0');

        $this->assertSame('Secret', CustomerCategory::withoutGlobalScopes()->whereKey($theirs->getKey())->value('name'));
    }

    public function test_only_people_who_manage_customers_can_change_categories(): void
    {
        $cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $category = $this->category('Hotel');

        $this->as($cellar)->get('/customers/categories')->assertForbidden();
        $this->as($cellar)->post('/customers/categories', ['name' => 'X'])->assertForbidden();
        $this->as($cellar)->patch("/customers/categories/{$category->getKey()}", ['name' => 'X'])->assertForbidden();
        $this->as($cellar)->delete("/customers/categories/{$category->getKey()}")->assertForbidden();
        $this->as($cellar)->post('/customers/categories/reorder', ['ids' => [$category->getKey()]])->assertForbidden();
    }

    public function test_a_customer_can_be_given_changed_and_cleared_of_a_category(): void
    {
        $hotel = $this->category('Hotel', 1);
        $bar = $this->category('Wine bar', 2);

        $this->post_('/customers', ['company_name' => 'Marina', 'email' => 'm@x.hr', 'customer_category_id' => $hotel->getKey()])->assertRedirect();
        $customer = Customer::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($hotel->getKey(), $customer->customer_category_id);

        $this->as($this->admin)->patch("/customers/{$customer->getKey()}", ['customer_category_id' => $bar->getKey()])->assertRedirect();
        $this->assertSame($bar->getKey(), $customer->refresh()->customer_category_id);

        $this->as($this->admin)->patch("/customers/{$customer->getKey()}", ['customer_category_id' => null])->assertRedirect();
        $this->assertNull($customer->refresh()->customer_category_id);
    }

    public function test_a_customer_cannot_be_given_another_organisations_category(): void
    {
        $other = $this->createTenant();
        $theirs = $this->category('Secret', 1, true, $other);

        $this->post_('/customers', ['company_name' => 'Marina', 'email' => 'm@x.hr', 'customer_category_id' => $theirs->getKey()])
            ->assertSessionHasErrors('customer_category_id');
        $this->assertSame(0, Customer::withoutGlobalScopes()->count());
    }

    public function test_the_customers_list_shows_and_filters_by_category(): void
    {
        $hotel = $this->category('Hotel', 1);
        $bar = $this->category('Wine bar', 2);
        $this->customer('Hotel Marina', $hotel);
        $this->customer('Enoteca', $bar);
        $this->customer('Nobody');

        $this->as($this->admin)->get('/customers')->assertInertia(function (AssertableInertia $page): void {
            $names = [];
            foreach ($page->toArray()['props']['customers']['data'] as $c) {
                $names[$c['company_name']] = $c['category']['name'] ?? null;
            }
            $this->assertEqualsCanonicalizing(['Hotel Marina' => 'Hotel', 'Enoteca' => 'Wine bar', 'Nobody' => null], $names);
        });

        $this->as($this->admin)->get("/customers?customer_category_id={$hotel->getKey()}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.company_name', 'Hotel Marina')
            ->where('filters.customer_category_id', $hotel->getKey()));
    }

    public function test_options_offer_active_categories_only_in_the_organisations_order(): void
    {
        $this->category('Hotel', 2);
        $this->category('Restaurant', 1);
        $this->category('Retired', 3, false);
        $this->actingAsTenant($this->tenant);

        $this->assertSame(['Restaurant', 'Hotel'], array_column(app(CustomerCategoryOptions::class)->list(), 'name'));
    }
}
