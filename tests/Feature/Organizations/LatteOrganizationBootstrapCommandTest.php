<?php

namespace Tests\Feature\Organizations;

use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\PaneTable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class LatteOrganizationBootstrapCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        OrganizationInvitation::query()->delete();
        OrganizationMembership::query()->delete();
        ApplicationRegistration::query()->delete();
        Organization::query()->delete();
        User::query()->delete();
    }

    public function test_bootstrap_command_initializes_latte_organization_and_prints_first_invite(): void
    {
        config()->set('services.latte.application_id', '00000000-0000-4000-8000-000000000501');
        config()->set('services.latte.organization_id', '00000000-0000-4000-8000-000000000502');
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/auth/callback']);

        $this->artisan('latte:bootstrap-organization', [
            'email' => 'First.Admin@Example.COM',
        ])
            ->expectsOutput('Latte organization ready.')
            ->expectsOutput('Application: 00000000-0000-4000-8000-000000000501')
            ->expectsOutput('Organization: 00000000-0000-4000-8000-000000000502')
            ->expectsOutput('Bootstrap actor: first.admin@example.com')
            ->expectsOutput('Invitation email: first.admin@example.com')
            ->expectsOutput('Invitation role: organization_administrator')
            ->assertExitCode(0);

        $invitation = OrganizationInvitation::query()->where('email', 'first.admin@example.com')->firstOrFail();

        $this->assertDatabaseMissing(PaneTable::name(PaneTable::ORGANIZATION_MEMBERSHIPS), [
            'organization_id' => '00000000-0000-4000-8000-000000000502',
        ]);
        $this->assertFalse(User::query()->where('email', 'first.admin@example.com')->exists());
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->status);
        $this->assertSame(OrganizationMembership::ROLE_ADMINISTRATOR, $invitation->role);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'organization.membership.invite')
                ->where('outcome', AuditEvent::OUTCOME_SUCCESS)
                ->where('organization_id', '00000000-0000-4000-8000-000000000502')
                ->get()
                ->contains(fn (AuditEvent $event): bool => ($event->resource_ids['organization_invitation_id'] ?? null) === $invitation->getKey())
        );
    }

    public function test_bootstrap_command_replaces_pending_invite_for_same_email(): void
    {
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/auth/callback']);

        $this->artisan('latte:bootstrap-organization', [
            'email' => 'first.admin@example.com',
        ])->assertExitCode(0);

        $this->artisan('latte:bootstrap-organization', [
            'email' => 'first.admin@example.com',
        ])->assertExitCode(0);

        $this->assertSame(
            1,
            OrganizationInvitation::query()
                ->where('email', 'first.admin@example.com')
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->count()
        );
        $this->assertSame(
            1,
            OrganizationInvitation::query()
                ->where('email', 'first.admin@example.com')
                ->where('status', OrganizationInvitation::STATUS_REVOKED)
                ->count()
        );
    }

    public function test_bootstrap_command_prompts_for_first_admin_email_when_argument_is_missing(): void
    {
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/auth/callback']);

        $this->artisan('latte:bootstrap-organization')
            ->expectsQuestion('First Latte organization administrator email', 'Prompted.Admin@Example.COM')
            ->expectsOutput('Invitation email: prompted.admin@example.com')
            ->assertExitCode(0);

        $this->assertDatabaseHas(PaneTable::name(PaneTable::ORGANIZATION_INVITATIONS), [
            'email' => 'prompted.admin@example.com',
            'role' => OrganizationMembership::ROLE_ADMINISTRATOR,
            'status' => OrganizationInvitation::STATUS_PENDING,
        ]);
    }

    public function test_bootstrap_command_can_send_invitation_email(): void
    {
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/auth/callback']);

        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(fn (string $text, mixed $callback): bool => str_contains($text, 'https://latte.test/auth/login?')
                && is_callable($callback));

        $this->artisan('latte:bootstrap-organization', [
            'email' => 'first.admin@example.com',
            '--send' => true,
        ])
            ->expectsOutput('Invitation email sent.')
            ->assertExitCode(0);
    }

    public function test_bootstrap_command_prints_invite_url_when_email_delivery_fails(): void
    {
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/auth/callback']);

        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new \RuntimeException('SMTP authentication required.'));

        $this->artisan('latte:bootstrap-organization', [
            'email' => 'first.admin@example.com',
            '--send' => true,
        ])
            ->expectsOutput('Latte organization ready.')
            ->expectsOutputToContain('Invitation URL: https://latte.test/auth/login?')
            ->expectsOutput('Invitation email could not be sent: SMTP authentication required.')
            ->assertExitCode(1);

        $this->assertDatabaseHas(PaneTable::name(PaneTable::ORGANIZATION_INVITATIONS), [
            'email' => 'first.admin@example.com',
            'status' => OrganizationInvitation::STATUS_PENDING,
        ]);
    }

    public function test_bootstrap_command_rejects_invalid_role(): void
    {
        $this->artisan('latte:bootstrap-organization', [
            'email' => 'first.admin@example.com',
            '--role' => 'owner',
        ])
            ->expectsOutput('The email and role options must be valid.')
            ->assertExitCode(1);
    }
}
