<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TastnowAuth
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-Api-Key') ?? $request->header('x-api-key');
        if (!$key || !hash_equals((string) config('tastnow.shared_secret'), (string) $key)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
