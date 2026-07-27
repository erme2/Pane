<?php

namespace App\Providers;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Policies\AuditEventPolicy;
use App\Policies\OrganizationPolicy;
// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        AuditEvent::class => AuditEventPolicy::class,
        Organization::class => OrganizationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
