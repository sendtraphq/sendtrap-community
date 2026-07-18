<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A Community instance must never be indexable: robots.txt denies all
 * crawling and every response carries a noindex header — including the
 * public share surface, whose URLs can leak into indexes when people post
 * them somewhere crawlable.
 */
class SearchIndexingTest extends TestCase
{
    public function test_robots_txt_disallows_everything(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /', $robots);
        $this->assertStringNotContainsString('Sitemap', $robots);
    }

    public function test_every_response_carries_a_noindex_header(): void
    {
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
