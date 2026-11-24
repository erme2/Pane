<?php

namespace App\Providers;

use App\Models\AuditEvent;
use App\Policies\AuditEventPolicy;
use App\Services\DeviceTokenService;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        AuditEvent::class => AuditEventPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Native clients sign in with a per-device bearer token (Pane#130).
        Auth::viaRequest('pane-device', fn (Request $request) => $this->app->make(DeviceTokenService::class)->userForRequest($request));
    }
}
