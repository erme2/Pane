<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\AuditEventService;
use App\Services\DeviceTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Native-client (iOS and Android) authentication with per-device bearer tokens (Pane#130). */
class NativeAuthTest extends TestCase
{
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    protected function setUp(): void
    {
        parent::setUp();

        DeviceToken::query()->delete();
        User::query()->delete();

        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);
    }

    /**
     * The test app lives across requests, so a guard would keep the previous request's user. A real
     * request starts with fresh guards.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function member(bool $active = true, string $email = 'guest@example.com'): User
    {
        return User::query()->create([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => 'Guest',
            'email' => $email,
            'password' => 'password',
            'is_active' => $active,
        ]);
    }

    private function fakeWorkOs(string $email = 'guest@example.com'): void
    {
        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => ['id' => 'user_guest', 'email' => $email, 'email_verified' => true, 'first_name' => 'Guest', 'last_name' => 'User'],
                'session_id' => 'session_123',
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function exchange(array $extra = []): array
    {
        $this->fakeWorkOs();

        $response = $this->postJson('/api/v1/auth/native/exchange', $extra + [
            'code' => 'code_123',
            'code_verifier' => self::VERIFIER,
            'state' => 'app_state_1',
            'device_label' => 'Pixel 9',
        ]);

        $response->assertCreated();

        return $response->json('data');
    }

    public function test_exchange_signs_the_member_in_with_tokens_and_no_cookie_or_origin(): void
    {
        $user = $this->member();

        $this->fakeWorkOs();

        $response = $this->postJson('/api/v1/auth/native/exchange', [
            'code' => 'code_123',
            'code_verifier' => self::VERIFIER,
            'state' => 'app_state_1',
            'device_label' => ' Pixel 9 ',
        ]);

        $response
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.state', 'app_state_1')
            ->assertJsonPath('data.expires_in', 3600)
            ->assertJsonPath('data.user.attributes.email', 'guest@example.com')
            ->assertJsonPath('data.user.attributes.role', 'user');

        $data = $response->json('data');

        $this->assertStringStartsWith('pane_at_', $data['access_token']);
        $this->assertStringStartsWith('pane_rt_', $data['refresh_token']);
        $this->assertGuest();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.workos.com/user_management/authenticate'
            && $request['code_verifier'] === self::VERIFIER
            && $request['code'] === 'code_123');

        $device = DeviceToken::query()->findOrFail($data['device_id']);

        $this->assertSame($user->getKey(), $device->user_id);
        $this->assertSame('Pixel 9', $device->device_label);
        $this->assertSame(hash('sha256', $data['access_token']), $device->access_token_hash);
        $this->assertSame(hash('sha256', $data['refresh_token']), $device->refresh_token_hash);
        $this->assertSame('user_guest', $user->fresh()->workos_id);
        // The database never holds a usable token.
        $this->assertStringNotContainsString($data['access_token'], json_encode(DeviceToken::query()->get()->makeVisible(['access_token_hash'])->toArray()));
    }

    public function test_exchange_validates_its_input(): void
    {
        $this->member();

        foreach ([
            ['code' => ''],
            ['code_verifier' => 'short'],
            ['state' => ''],
            ['device_label' => str_repeat('a', 101)],
            ['unknown' => 'x'],
        ] as $bad) {
            $this->postJson('/api/v1/auth/native/exchange', $bad + [
                'code' => 'code_123',
                'code_verifier' => self::VERIFIER,
                'state' => 'app_state_1',
            ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_exchange_refuses_a_rejected_code_an_unknown_member_and_an_inactive_member(): void
    {
        $this->fakeWorkOs('stranger@example.com');

        $this->postJson('/api/v1/auth/native/exchange', ['code' => 'c', 'code_verifier' => self::VERIFIER, 'state' => 's1s1s1s1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'membership_required');

        $this->member(false);
        $this->fakeWorkOs();

        $this->postJson('/api/v1/auth/native/exchange', ['code' => 'c', 'code_verifier' => self::VERIFIER, 'state' => 's1s1s1s1'])
            ->assertStatus(422);

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_exchange_refuses_a_code_workos_rejects(): void
    {
        $this->member();
        Http::fake(['api.workos.com/user_management/authenticate' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->postJson('/api/v1/auth/native/exchange', ['code' => 'bad', 'code_verifier' => self::VERIFIER, 'state' => 's1s1s1s1'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request');

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_the_access_token_works_as_a_session_without_origin_or_csrf(): void
    {
        $this->member();
        $data = $this->exchange();
        $auth = ['Authorization' => 'Bearer '.$data['access_token']];

        $this->withHeaders($auth)->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.attributes.email', 'guest@example.com')
            ->assertJsonPath('data.application.type', 'application');

        // A write needs no Origin and no CSRF token for a bearer-authenticated request.
        $this->withHeaders($auth)->getJson('/api/v1/session/devices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $data['device_id'])
            ->assertJsonPath('data.0.current', true)
            ->assertJsonPath('data.0.device_label', 'Pixel 9');

        $this->withHeaders($auth)->getJson('/api/v1/maps')->assertStatus(200);
    }

    public function test_requests_without_a_valid_token_still_need_origin_and_csrf(): void
    {
        $this->member();
        $data = $this->exchange();

        $this->getJson('/api/v1/session')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer pane_at_nope'])->getJson('/api/v1/session')->assertUnauthorized();
        // The refresh token is not an access token.
        $this->withHeaders(['Authorization' => 'Bearer '.$data['refresh_token']])->getJson('/api/v1/session')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer pane_at_nope'])->deleteJson('/api/v1/session')->assertForbidden();
    }

    public function test_an_expired_access_token_is_refused(): void
    {
        $this->member();
        $data = $this->exchange();

        $this->travel(3601)->seconds();

        $this->withHeaders(['Authorization' => 'Bearer '.$data['access_token']])->getJson('/api/v1/session')->assertUnauthorized();
    }

    public function test_refresh_rotates_both_tokens_and_the_old_refresh_token_stops_working(): void
    {
        $this->member();
        $data = $this->exchange();

        $refreshed = $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $data['refresh_token']]);

        $refreshed->assertOk()->assertJsonPath('data.device_id', $data['device_id']);

        $new = $refreshed->json('data');

        $this->assertNotSame($data['access_token'], $new['access_token']);
        $this->assertNotSame($data['refresh_token'], $new['refresh_token']);

        $this->withHeaders(['Authorization' => 'Bearer '.$data['access_token']])->getJson('/api/v1/session')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer '.$new['access_token']])->getJson('/api/v1/session')->assertOk();
        $this->assertSame(1, DeviceToken::query()->count());
    }

    public function test_reusing_a_replaced_refresh_token_signs_the_device_out(): void
    {
        $this->member();
        $data = $this->exchange();
        $new = $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $data['refresh_token']])->json('data');

        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $data['refresh_token']])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_refresh_token');

        // The thief's replay also ends the legitimate holder's session: sign in again.
        $this->assertSame(0, DeviceToken::query()->count());
        $this->withHeaders(['Authorization' => 'Bearer '.$new['access_token']])->getJson('/api/v1/session')->assertUnauthorized();
        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $new['refresh_token']])->assertUnauthorized();
    }

    public function test_replaying_an_older_ancestor_after_several_rotations_signs_the_device_out_and_is_audited(): void
    {
        $this->member();
        $reuseEvents = fn (): int => AuditEvent::query()->where('action', 'auth.native.refresh_reuse')->count();
        $before = $reuseEvents();
        $first = $this->exchange();
        $second = $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $first['refresh_token']])->json('data');
        $third = $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $second['refresh_token']])->json('data');

        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $first['refresh_token']])->assertUnauthorized();

        $this->assertSame(0, DeviceToken::query()->count());
        $this->withHeaders(['Authorization' => 'Bearer '.$third['access_token']])->getJson('/api/v1/session')->assertUnauthorized();
        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $third['refresh_token']])->assertUnauthorized();
        $this->assertSame($before + 1, $reuseEvents());
    }

    public function test_refresh_refuses_expired_unknown_malformed_and_inactive(): void
    {
        $user = $this->member();
        $data = $this->exchange();

        $this->postJson('/api/v1/auth/native/refresh', [])->assertStatus(422);
        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => 'pane_rt_unknown'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $data['access_token']])->assertUnauthorized();

        $user->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $data['refresh_token']])->assertUnauthorized();
        $this->assertSame(0, DeviceToken::query()->count());

        $user->forceFill(['is_active' => true])->save();
        $data = $this->exchange();
        $this->travel(31)->days();
        $this->postJson('/api/v1/auth/native/refresh', ['refresh_token' => $data['refresh_token']])->assertUnauthorized();
    }

    public function test_an_inactive_member_loses_access_on_the_next_request(): void
    {
        $user = $this->member();
        $data = $this->exchange();

        $user->forceFill(['is_active' => false])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$data['access_token']])->getJson('/api/v1/session')->assertUnauthorized();
    }

    public function test_logout_signs_out_this_device_only_and_returns_the_workos_logout_url(): void
    {
        $this->member();
        $reuseEvents = fn (): int => AuditEvent::query()->where('action', 'auth.native.refresh_reuse')->count();
        $before = $reuseEvents();
        $first = $this->exchange();
        $second = $this->exchange(['device_label' => 'iPad']);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$first['access_token']])->deleteJson('/api/v1/session');

        $response->assertOk();
        $this->assertStringContainsString('session_id=session_123', $response->json('data.logout_url'));
        $this->assertSame([$second['device_id']], DeviceToken::query()->pluck('device_id')->all());
        $this->withHeaders(['Authorization' => 'Bearer '.$first['access_token']])->getJson('/api/v1/session')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer '.$second['access_token']])->getJson('/api/v1/session')->assertOk();
    }

    public function test_a_member_lists_and_revokes_only_their_own_devices(): void
    {
        $this->member();
        $mine = $this->exchange();
        $other = $this->member(true, 'other@example.com');
        $theirs = (new DeviceTokenService(app(AuditEventService::class)))->issue($other, 'Other phone', null);
        $auth = ['Authorization' => 'Bearer '.$mine['access_token']];

        $this->withHeaders($auth)->getJson('/api/v1/session/devices')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($auth)->deleteJson('/api/v1/session/devices/'.$theirs['device']->device_id)->assertNotFound();
        $this->withHeaders($auth)->deleteJson('/api/v1/session/devices/not-a-uuid')->assertNotFound();
        $this->assertSame(2, DeviceToken::query()->count());

        $this->withHeaders($auth)->deleteJson('/api/v1/session/devices/'.$mine['device_id'])->assertNoContent();
        $this->assertSame(1, DeviceToken::query()->count());
    }

    public function test_a_new_device_over_the_limit_signs_out_the_least_recently_used_one(): void
    {
        config()->set('pane.native_auth.max_devices_per_user', 2);
        $this->member();

        $first = $this->exchange(['device_label' => 'one']);
        $this->travel(5)->minutes();
        $second = $this->exchange(['device_label' => 'two']);
        $this->travel(5)->minutes();
        $third = $this->exchange(['device_label' => 'three']);

        $this->assertEqualsCanonicalizing(
            [$second['device_id'], $third['device_id']],
            DeviceToken::query()->pluck('device_id')->all()
        );
        $this->assertNotContains($first['device_id'], DeviceToken::query()->pluck('device_id')->all());
    }

    public function test_the_story_crud_route_accepts_a_device_token(): void
    {
        $this->member();
        $data = $this->exchange();

        // No cookie session and no token: the CRUD route is closed.
        $this->getJson('/stories/users')->assertUnauthorized();
        $this->assertNotSame(401, $this->withHeaders(['Authorization' => 'Bearer '.$data['access_token']])->getJson('/stories/users')->status());
    }
}
