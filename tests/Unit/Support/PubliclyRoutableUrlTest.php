<?php

declare(strict_types=1);

namespace Tests\Unit\Support {
    use App\Support\PubliclyRoutableUrl;
    use Illuminate\Support\Facades\Http;
    use RuntimeException;
    use Tests\TestCase;

    class PubliclyRoutableUrlTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['publicly_routable_url_dns_records']);

            parent::tearDown();
        }

        public function test_it_allows_public_http_urls_without_dns_resolution(): void
        {
            $this->assertTrue(PubliclyRoutableUrl::allows('https://example.com/webhooks/monitoring'));
            $this->assertTrue(PubliclyRoutableUrl::allows('http://example.org/notify'));
        }

        public function test_it_rejects_non_public_url_shapes(): void
        {
            foreach ([
                'not-a-url',
                'ftp://example.com/webhook',
                'https://localhost/webhook',
                'https://service.local/webhook',
                'https://internal/webhook',
                'http://127.0.0.1/webhook',
                'http://[::1]/webhook',
            ] as $url) {
                $this->assertFalse(PubliclyRoutableUrl::allows($url), $url);
            }
        }

        public function test_it_rejects_hosts_that_resolve_to_private_addresses(): void
        {
            $GLOBALS['publicly_routable_url_dns_records'] = [
                'private.example.com' => [
                    ['ip' => '10.10.1.5'],
                ],
            ];

            $this->assertTrue(PubliclyRoutableUrl::allows('https://private.example.com/webhook'));
            $this->assertFalse(PubliclyRoutableUrl::allows('https://private.example.com/webhook', resolveDns: true));
        }

        public function test_it_rejects_dns_failures(): void
        {
            $GLOBALS['publicly_routable_url_dns_records'] = [
                'unresolved.example.com' => false,
                'empty.example.com' => [],
            ];

            $this->assertFalse(PubliclyRoutableUrl::allows('https://unresolved.example.com/webhook', resolveDns: true));
            $this->assertNull(PubliclyRoutableUrl::destination('https://empty.example.com/webhook'));
        }

        public function test_it_returns_a_public_destination_for_pinning(): void
        {
            $GLOBALS['publicly_routable_url_dns_records'] = [
                'public.example.com' => [
                    ['ip' => '93.184.216.34'],
                    ['ipv6' => '2606:4700:4700::1111'],
                ],
            ];

            $this->assertSame([
                'host' => 'public.example.com',
                'ip' => '93.184.216.34',
                'port' => 443,
            ], PubliclyRoutableUrl::destination('https://public.example.com/webhook'));
        }

        public function test_it_does_not_post_to_a_private_dns_destination(): void
        {
            $GLOBALS['publicly_routable_url_dns_records'] = [
                'private.example.com' => [
                    ['ip' => '192.168.1.10'],
                ],
            ];

            Http::fake();

            try {
                PubliclyRoutableUrl::post('https://private.example.com/webhook', ['event' => 'test']);
                $this->fail('Private webhook destinations must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Notification webhook URL is not publicly routable.', $exception->getMessage());
            }

            Http::assertNothingSent();
        }
    }
}
