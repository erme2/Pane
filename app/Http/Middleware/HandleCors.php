<?php

namespace App\Http\Middleware;

use App\Services\ApplicationRegistryService;
use Closure;
use Fruitcake\Cors\CorsService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Middleware\HandleCors as BaseHandleCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the origins of every active registered application, not only the static
 * `cors.allowed_origins` list, so one installation can serve several frontends.
 */
class HandleCors extends BaseHandleCors
{
    public function __construct(
        Container $container,
        CorsService $cors,
        private readonly ApplicationRegistryService $applications,
    ) {
        parent::__construct($container, $cors);
    }

    /**
     * @param  Request  $request
     */
    public function handle($request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $application = is_string($origin) && $this->hasMatchingPath($request)
            ? $this->applications->activeApplicationForOrigin($origin)
            : null;

        if ($application === null) {
            return parent::handle($request, $next);
        }

        // The base middleware reads its options from config, so widen the list for this request only.
        $config = $this->container['config'];
        $configuredOrigins = $config->get('cors.allowed_origins', []);
        $config->set('cors.allowed_origins', [...(array) $configuredOrigins, $application->trusted_origin]);

        try {
            return parent::handle($request, $next);
        } finally {
            $config->set('cors.allowed_origins', $configuredOrigins);
        }
    }
}
