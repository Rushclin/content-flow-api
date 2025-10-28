<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RateLimitByIp
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 5): Response
    {
        $ip = $request->ip();
        $key = 'rate_limit:' . $ip;

        $attempts = Cache::get($key, 0);

        if ($attempts >= $maxAttempts) {
            return response()->json([
                'message' => 'Too many requests. Maximum ' . $maxAttempts . ' attempts allowed per IP address.',
                'remaining' => 0
            ], 429);
        }

        // Increment attempts and set expiration to 24 hours if not already set
        Cache::put($key, $attempts + 1, now()->addDay());

        $response = $next($request);

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - ($attempts + 1)));

        return $response;
    }
}
