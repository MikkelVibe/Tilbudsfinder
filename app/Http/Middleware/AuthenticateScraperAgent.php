<?php

namespace App\Http\Middleware;

use App\Models\ScraperAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateScraperAgent
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            abort(401, 'Missing scraper agent token.');
        }

        $tokenHash = hash('sha256', $token);
        $agent = ScraperAgent::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $agent) {
            abort(401, 'Invalid scraper agent token.');
        }

        $request->attributes->set('scraper_agent', $agent);

        return $next($request);
    }
}
