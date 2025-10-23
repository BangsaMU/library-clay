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

        // Ambil daftar domain yang diizinkan dari ENV (dipisah koma)
        $allowedDomains = collect(explode(',', env('FORCE_APP_URL_DOMAIN', '')))
            ->map(fn($d) => trim($d))
            ->filter()
            ->toArray();

        // Tambahkan domain yang otomatis dikecualikan (safe access)
        $allowedDomains = array_merge($allowedDomains, [
            'trycloudflare.com',
            'localhost',
        ]);

        // Jika domain request cocok salah satu domain yang diizinkan
        foreach ($allowedDomains as $allowed) {
            if (
                str_ends_with($requestHost, $allowed) ||
                str_contains($requestHost, $allowed) ||
                preg_match('/^192\.168\./', $requestHost)
            ) {
                Log::info("[ForceAppUrl] Access allowed for host: {$requestHost}");
                return $next($request);
            }
        }

        // Redirect jika host/port berbeda
        if ($requestHost !== $appHost || $requestPort !== $appPort) {
            $scheme = $request->getScheme();
            $uri = $request->getRequestUri();
            $target = "{$scheme}://{$appHost}:{$appPort}{$uri}";

            Log::info("[ForceAppUrl] Redirect to => {$target}");
            return redirect()->to($target, 301);
        }

        return $next($request);
    }
}

