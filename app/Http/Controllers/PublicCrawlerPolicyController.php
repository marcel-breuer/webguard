<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StatusPage;
use Illuminate\Http\Response;

class PublicCrawlerPolicyController extends Controller
{
    public function robots(): Response
    {
        $sitemapUrl = rtrim((string) config('app.url'), '/') . route('public.sitemap', absolute: false);
        $content = implode("\n", [
            'User-agent: *',
            'Disallow: /',
            'Allow: /status/',
            'Allow: /sitemap.xml',
            'Disallow: /status/*/subscribers',
            '',
            'Sitemap: ' . $sitemapUrl,
            '',
        ]);

        return response($content, headers: [
            'Cache-Control' => 'public, max-age=3600',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(): Response
    {
        $entries = StatusPage::query()
            ->where('is_public', true)
            ->orderBy('id')
            ->get(['id', 'updated_at'])
            ->map(function (StatusPage $statusPage): string {
                $location = htmlspecialchars(
                    rtrim((string) config('app.url'), '/') . route('public-status-pages.show', $statusPage, absolute: false),
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8',
                );
                $lastModified = $statusPage->updated_at?->toAtomString();

                return '<url><loc>' . $location . '</loc>'
                    . ($lastModified === null ? '' : '<lastmod>' . $lastModified . '</lastmod>')
                    . '</url>';
            })
            ->implode('');

        return response(
            '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . $entries
                . '</urlset>',
            headers: [
                'Cache-Control' => 'public, max-age=300, stale-while-revalidate=60',
                'Content-Type' => 'application/xml; charset=UTF-8',
            ],
        );
    }
}
