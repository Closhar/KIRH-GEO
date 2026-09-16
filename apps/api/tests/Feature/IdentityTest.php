<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\SessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentityTest extends TestCase
{
    use DatabaseTransactions;

    private function register(): array
    {
        return $this->postJson('/api/v1/auth/register', ['name' => 'Тест', 'email' => Str::uuid().'@example.test',
            'password' => 'strong-password-1234', 'platform' => 'android', 'installation_id' => (string) Str::uuid()])
            ->assertCreated()->json('data');
    }

    public function test_registration_starts_paused_and_tokens_are_hashed(): void
    {
        $session = $this->register();
        $this->assertTrue((bool) DB::table('location_preferences')->where('user_id', $session['user']['id'])->value('sharing_paused'));
        $this->assertDatabaseMissing('auth_sessions', ['access_token_hash' => $session['access_token']]);
        $this->withToken($session['access_token'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_refresh_reuse_revokes_entire_family(): void
    {
        $session = $this->register();
        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertOk()->json('data');
        $this->withToken($session['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($rotated['access_token'])->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
        $this->withToken($rotated['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_revoked_device_cannot_read_or_refresh(): void
    {
        $session = $this->register();
        $this->withToken($session['access_token'])->deleteJson('/api/v1/devices/'.$session['device_id'])->assertOk();
        $this->withToken($session['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
    }

    public function test_logout_revokes_all_tokens_in_refresh_family(): void
    {
        $session = $this->register();
        // Simulate a concurrent rotation that committed after middleware authenticated the old bearer.
        $family = DB::table('auth_sessions')->where('id', $session['session_id'])->value('family_id');
        $other = app(SessionService::class)->issue($session['user']['id'], $session['device_id'], $family);
        $this->withToken($session['access_token'])->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($other['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $other['refresh_token']])->assertUnauthorized();
    }

    public function test_login_rejects_new_device_after_cap_but_existing_device_still_works(): void
    {
        $session = $this->register();
        $body = ['email' => $session['user']['email'], 'password' => 'strong-password-1234', 'platform' => 'android'];
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/login', $body + ['installation_id' => (string) Str::uuid()])->assertOk();
        }
        $this->postJson('/api/v1/auth/login', $body + ['installation_id' => (string) Str::uuid()])->assertConflict()->assertJsonPath('error.code', 'LIMIT_EXCEEDED');
        $existing = DB::table('devices')->where('id', $session['device_id'])->value('installation_id');
        $this->postJson('/api/v1/auth/login', $body + ['installation_id' => $existing])->assertOk();
    }

    public function test_malformed_device_uuid_is_rejected_without_database_exception(): void
    {
        $session = $this->register();
        $this->withToken($session['access_token'])->deleteJson('/api/v1/devices/not-a-uuid')->assertNotFound();
    }

    public function test_me_never_serializes_mfa_secret_or_recovery_codes(): void
    {
        $session = $this->register();
        DB::table('users')->where('id', $session['user']['id'])->update(['app_authentication_secret' => 'sensitive-secret', 'app_authentication_recovery_codes' => 'sensitive-codes']);
        $this->withToken($session['access_token'])->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonMissingPath('data.user.app_authentication_secret')->assertJsonMissingPath('data.user.app_authentication_recovery_codes');
    }
}
