<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\Module;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Help\HelpLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The help center: the article files themselves (both languages, valid
 * front matter, links that resolve), what a member is allowed to see, and
 * search — including from the header's ⌘K box.
 */
class HelpCenterTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function asMember(User $user, Tenant $tenant, string $locale = 'en'): static
    {
        return $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->withHeader('X-Locale', $locale);
    }

    public function test_every_article_exists_in_every_language_with_valid_front_matter(): void
    {
        $library = app(HelpLibrary::class);
        $locales = HelpLibrary::locales();
        $this->assertContains('en', $locales);
        $this->assertContains('hr', $locales);

        $ids = null;
        foreach ($locales as $locale) {
            $articles = $library->all($locale);
            $this->assertNotEmpty($articles, "no articles for {$locale}");

            $mine = array_keys($articles);
            sort($mine);
            $ids ??= $mine;
            $this->assertSame($ids, $mine, "{$locale} must have exactly the same article ids as the others");

            foreach ($articles as $article) {
                $this->assertNotSame('', $article->title, "{$locale}/{$article->id}: title");
                $this->assertNotSame('', $article->summary, "{$locale}/{$article->id}: summary");
                $this->assertArrayHasKey($article->topic, HelpLibrary::TOPICS, "{$locale}/{$article->id}: topic");
                $this->assertNotSame('', $article->text, "{$locale}/{$article->id}: body");

                if ($article->module !== null) {
                    $this->assertNotNull(Module::tryFrom($article->module), "{$locale}/{$article->id}: module");
                }

                foreach ($article->related as $related) {
                    $this->assertArrayHasKey($related, $articles, "{$locale}/{$article->id}: related '{$related}'");
                }
            }
        }
    }

    public function test_help_home_groups_articles_by_topic(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->asMember($admin, $tenant)
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Help/Index')
                ->where('topics.0.key', 'getting-started')
                ->has('topics.0.articles'));
    }

    public function test_articles_follow_the_members_capabilities_and_the_plan(): void
    {
        $tenant = $this->createTenant();
        // Team can take orders but can't manage pricing tiers or settings.
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->asMember($member, $tenant)->get('/help/take-an-order')->assertOk();
        $this->asMember($member, $tenant)->get('/help/pricing-tiers')->assertNotFound();
        $this->asMember($member, $tenant)->get('/help/company-profile')->assertNotFound();

        $plan = Plan::create(['name' => 'No orders', 'slug' => 'no-orders', 'modules' => [Module::Dashboard->value, Module::Settings->value]]);
        $limited = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($limited, [TenantRole::Admin]);

        $this->asMember($admin, $limited)->get('/help/take-an-order')->assertNotFound();
        $this->asMember($admin, $limited)->get('/help/company-profile')->assertOk();
    }

    public function test_article_page_renders_html_and_related_in_the_members_language(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->asMember($admin, $tenant, 'hr')
            ->get('/help/pricing-tiers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Help/Show')
                ->where('article.title', 'Cjenovne razine i popusti')
                ->where('article.html', fn (string $html): bool => str_contains($html, '<h2>'))
                ->has('related', 2));
    }

    public function test_search_folds_accents_matches_prefixes_and_synonyms(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $ids = fn (string $q, string $locale = 'en'): array => array_column(
            (array) $this->asMember($admin, $tenant, $locale)->getJson('/help/search?q='.urlencode($q))->assertOk()->json('results'),
            'id',
        );

        // A synonym in the other language.
        $this->assertContains('pricing-tiers', $ids('rabat'));
        // A prefix, and the question words ignored.
        $this->assertSame('add-customers', $ids('how do I add a custom')[0] ?? null);
        // Croatian without diacritics finds the Croatian article.
        $this->assertContains('order-statuses', $ids('isporuceno', 'hr'));
        $this->assertSame([], $ids('zzzz-nothing'));
    }

    public function test_search_only_returns_articles_the_member_may_see(): void
    {
        $tenant = $this->createTenant();
        // Team can't manage pricing tiers, so that article never comes back for them.
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $ids = array_column(
            (array) $this->asMember($member, $tenant)->getJson('/help/search?q=pricing+tiers')->assertOk()->json('results'),
            'id',
        );

        $this->assertNotContains('pricing-tiers', $ids);
    }

    public function test_global_search_includes_help_articles(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->asMember($admin, $tenant)
            ->getJson('/search?q=pricing+tiers')
            ->assertOk()
            ->assertJsonPath('help.0.url', '/help/pricing-tiers');
    }
}
