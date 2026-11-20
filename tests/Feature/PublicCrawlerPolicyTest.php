<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StatusPage;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCrawlerPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_policy_allows_status_pages_and_sitemap_only(): void
    {
        config(['app.url' => 'https://status.example.test']);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSeeText('Disallow: /')
            ->assertSeeText('Allow: /status/')
            ->assertSeeText('Allow: /sitemap.xml')
            ->assertSeeText('Disallow: /status/*/subscribers')
            ->assertSeeText('Sitemap: https://status.example.test/sitemap.xml');
    }

    public function test_sitemap_lists_only_public_status_pages_and_is_crawlable(): void
    {
        config(['app.url' => 'https://status.example.test']);
        Package::factory()->create(['price' => 0]);
        $user = User::factory()->create();
        $publicStatusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Public status',
            'is_public' => true,
        ]);
        $privateStatusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Private status',
            'is_public' => false,
        ]);

        $response = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $this->assertStringContainsString(
            'https://status.example.test/status/' . $publicStatusPage->id,
            (string) $response->getContent(),
        );
        $this->assertStringNotContainsString(
            (string) $privateStatusPage->id,
            (string) $response->getContent(),
        );
    }
}
