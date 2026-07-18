<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Community instance is a private mail sandbox, never a public website —
 * every response carries a noindex header so search engines drop anything
 * they reach (public share links included), regardless of how the instance
 * is exposed. public/robots.txt disallows crawling outright; this header
 * is the belt for URLs that get fetched anyway.
 */
class DenySearchIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
