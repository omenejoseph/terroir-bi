<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class BackgroundRemovalTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_it_returns_processed_png_when_configured(): void
    {
        config(['uploads.background_removal.key' => 'test-key']);
        Http::fake([
            'api.remove.bg/*' => Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($member);

        $response = $this->post(
            '/api/v1/uploads/remove-background',
            ['image' => UploadedFile::fake()->image('bottle.png', 20, 20)],
            $this->tenantHeader($tenant),
        );

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('PNG-BYTES', $response->getContent());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'remove.bg')
            && $request->hasHeader('X-Api-Key', 'test-key'));
    }

    public function test_it_422s_when_not_configured(): void
    {
        config(['uploads.background_removal.key' => null]);

        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($member);

        $this->post(
            '/api/v1/uploads/remove-background',
            ['image' => UploadedFile::fake()->image('bottle.png', 20, 20)],
            $this->tenantHeader($tenant),
        )->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_it_422s_when_provider_fails(): void
    {
        config(['uploads.background_removal.key' => 'test-key']);
        Http::fake(['api.remove.bg/*' => Http::response('nope', 402)]);

        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($member);

        $this->post(
            '/api/v1/uploads/remove-background',
            ['image' => UploadedFile::fake()->image('bottle.png', 20, 20)],
            $this->tenantHeader($tenant),
        )->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_it_rejects_non_images(): void
    {
        config(['uploads.background_removal.key' => 'test-key']);

        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($member);

        $this->post(
            '/api/v1/uploads/remove-background',
            ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            $this->tenantHeader($tenant),
        )->assertStatus(422)->assertJsonValidationErrors(['image']);
    }
}
