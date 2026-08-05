<?php

namespace App\Console\Commands;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\OrganizationInvitationService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class BootstrapLatteOrganizationInvitation extends Command
{
    protected $signature = 'latte:bootstrap-organization
        {email? : Email address for the first Latte organization invitation}
        {--role=organization_administrator : Role for the invited member}
        {--send : Send the invitation email with the configured mailer}';

    protected $description = 'Initialize the configured Latte organization and create its first organization invitation.';

    public function handle(
        ApplicationRegistryService $applications,
        OrganizationInvitationService $invitations
    ): int {
        $emailInput = $this->argument('email');
        $email = $this->normalizeEmail(is_string($emailInput) && filled($emailInput)
            ? $emailInput
            : (string) $this->ask('First Latte organization administrator email'));
        $role = (string) $this->option('role');

        $validator = Validator::make([
            'email' => $email,
            'role' => $role,
        ], [
            'email' => ['required', 'email', 'max:320'],
            'role' => ['required', 'in:'.implode(',', OrganizationMembership::ROLES)],
        ]);

        if ($validator->fails()) {
            $this->error('The email and role options must be valid.');

            return self::FAILURE;
        }

        $application = $applications->configuredLatteApplication()->refresh();
        $organization = $application->organization;

        if (! $organization instanceof Organization) {
            $this->error('The configured Latte application is not bound to an organization.');

            return self::FAILURE;
        }

        $actor = $this->bootstrapActor($email);

        try {
            $result = $invitations->bootstrapOrganizationAdministratorInvitation($actor, $organization, $email, $role);
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->removeBootstrapActor($actor);
        }

        $invitationUrl = $this->invitationUrl($application, (string) $result['token']);
        $sendFailure = null;

        if ((bool) $this->option('send')) {
            try {
                $this->sendInvitation($email, $invitationUrl);
            } catch (Throwable $exception) {
                $sendFailure = $exception->getMessage();
            }
        }

        $this->info('Latte organization ready.');
        $this->line('Application: '.$application->getKey());
        $this->line('Organization: '.$organization->getKey());
        $this->line('Bootstrap actor: '.$actor->email);
        $this->line('Invitation email: '.$email);
        $this->line('Invitation role: '.$role);
        $this->line('Invitation URL: '.$invitationUrl);

        if (is_string($sendFailure)) {
            $this->error('Invitation email could not be sent: '.$sendFailure);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function bootstrapActor(string $email): User
    {
        $actor = User::query()->firstOrNew(['email' => $email]);
        $actor->forceFill([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => $email,
            'password' => Hash::make(Str::random(48)),
            'is_active' => true,
        ])->save();

        return $actor;
    }

    private function removeBootstrapActor(User $actor): void
    {
        if (! $actor->organizationMemberships()->exists()) {
            $actor->delete();
        }
    }

    private function invitationUrl(ApplicationRegistration $application, string $token): string
    {
        return rtrim($application->trusted_origin, '/').'/auth/login?'.http_build_query([
            'invitation_token' => $token,
            'redirect_to' => $this->invitationRedirectUri($application),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function invitationRedirectUri(ApplicationRegistration $application): string
    {
        $redirectUri = ($application->redirect_uris ?? [])[0] ?? null;

        if (is_string($redirectUri) && $redirectUri !== '') {
            return $redirectUri;
        }

        return rtrim($application->trusted_origin, '/');
    }

    private function sendInvitation(string $email, string $invitationUrl): void
    {
        Mail::raw(
            "You have been invited to Latte.\n\nOpen this link to sign in:\n{$invitationUrl}\n",
            function (Message $message) use ($email): void {
                $message
                    ->to($email)
                    ->subject('Your Latte invitation');
            }
        );

        $this->info('Invitation email sent.');
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
