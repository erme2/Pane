<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        return array_values(array_filter(array_merge(
            [$this->allSubdomainsOfApplicationUrl()],
            $this->configuredHosts()
        )));
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, $next)
    {
        return parent::handle($request, function (Request $request) use ($next) {
            $request->getHost();

            return $next($request);
        });
    }

    /**
     * @return array<int, string>
     */
    private function configuredHosts(): array
    {
        return array_map(
            fn (string $host): string => $this->hostPattern($host),
            config('app.trusted_hosts', [])
        );
    }

    private function hostPattern(string $host): string
    {
        $host = parse_url($host, PHP_URL_HOST) ?: $host;

        if (str_starts_with($host, '*.')) {
            return '^(.+\\.)?'.preg_quote(substr($host, 2)).'$';
        }

        return '^'.preg_quote($host).'$';
    }
}
