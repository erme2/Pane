<?php

namespace Tests\Feature\PaneAdmin;

use App\Exceptions\InvitationVersionConflict;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Tests\TestCase;

class UserInvitationRevokeRaceTest extends TestCase
{
    public function test_a_revoke_is_refused_when_the_locked_invitation_is_no_longer_current(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        // The client read the invitation while it was pending...
        $stale = UserInvitation::query()->create([
            'email' => 'raced.user@example.com',
            'token_hash' => hash('sha256', 'raced-token'),
            'status' => UserInvitation::STATUS_PENDING,
            'invited_by_user_id' => $actor->getKey(),
            'expires_at' => now()->addDay(),
        ]);

        // ...then the invitation was accepted before the revoke took its lock.
        UserInvitation::query()->whereKey($stale->getKey())->update(['status' => UserInvitation::STATUS_ACCEPTED]);

        $this->expectException(InvitationVersionConflict::class);

        try {
            app(UserInvitationService::class)->revoke(
                $actor,
                $stale,
                fn (UserInvitation $locked): bool => $locked->status === UserInvitation::STATUS_PENDING,
            );
        } finally {
            // Nothing was revoked: the accepted invitation is left as it was.
            $this->assertSame(UserInvitation::STATUS_ACCEPTED, $stale->fresh()->status);
        }
    }
}
