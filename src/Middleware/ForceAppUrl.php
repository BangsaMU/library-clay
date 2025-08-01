<?php
namespace Bangsamu\LibraryClay\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ForceAppUrl
{
    public function handle(Request $request, Closure $next)
    {
        Log::info('[ForceAppUrl] Middleware aktif.');

        $urlParts = parse_url(config('app.url'));

        $appHost = $urlParts['host'] ?? null;
        $appPort = $urlParts['port'] ?? ($urlParts['scheme'] === 'https' ? 443 : 80);

        $requestHost = $request->getHost();
        $requestPort = $request->getPort();

        if ($requestHost !== $appHost || $requestPort !== $appPort) {
        // if ($appHost && $requestHost !== $appHost && app()->environment('production')) {
            $scheme = $request->getScheme();
            $uri = $request->getRequestUri();
            Log::info('[ForceAppUrl] redirect to ==>'."{$scheme}://{$appHost}:{$appPort}{$uri}");
            return redirect()->to("{$scheme}://{$appHost}:{$appPort}{$uri}", 301);
        }

        return $next($request);
    }
}
