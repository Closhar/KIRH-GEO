<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\IdentityRecovery;
use App\Modules\Identity\Application\SendIdentityAction;
use App\Modules\Identity\Application\SessionService;
use App\Modules\Identity\Mail\IdentityActionMail;
use App\Support\ApiException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GeoFixture;
use Tests\TestCase;

final class IdentityRecoveryTest extends TestCase
{
    use DatabaseTransactions, GeoFixture;

    private function configured(): array
    {
        Bus::fake([SendIdentityAction::class]);
        Mail::fake();
        config(['identity.mail_actions_enabled' => true, 'identity.action_url' => 'https://geo.example.test/account-action', 'mail.default' => 'smtp', 'queue.default' => 'database']);
        $f = $this->geoFixture();
        $f['email'] = $f['subject'].'@example.test';
        DB::table('users')->where('id', $f['subject'])->update(['email' => $f['email']]);

        return $f;
    }

    private function requestedJob(string $email, string $purpose): SendIdentityAction
    {
        app(IdentityRecovery::class)->request($email, $purpose);
        $job = null;
        Bus::assertDispatched(SendIdentityAction::class, function ($item) use (&$job) {
            $job = $item;

            return true;
        });

        return $job;
    }

    public function test_verification_uses_encrypted_job_hashed_token_and_single_use_link(): void
    {
        $f = $this->configured();
        $job = $this->requestedJob($f['email'], 'verify_email');
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertNotSame($job->token, DB::table('identity_action_tokens')->where('id', $job->actionId)->value('token_hash'));
        $job->handle(app(IdentityRecovery::class));
        Mail::assertSent(IdentityActionMail::class, fn ($mail) => str_contains($mail->actionUrl, '#action=verify_email&token=') && ! str_contains($mail->actionUrl, '?token='));
        app(IdentityRecovery::class)->consume($job->token, 'verify_email');
        $this->assertNotNull(DB::table('users')->where('id', $f['subject'])->value('email_verified_at'));
        $this->expectException(ApiException::class);
        app(IdentityRecovery::class)->consume($job->token, 'verify_email');
    }

    public function test_password_reset_revokes_sessions_push_and_grants(): void
    {
        $f = $this->configured();
        app(SessionService::class)->issue($f['subject'], $f['device']);
        DB::table('device_tokens')->insert(['device_id' => $f['device'], 'provider' => 'fcm', 'token_encrypted' => 'encrypted-placeholder', 'token_fingerprint' => str_repeat('a', 64)]);
        $job = $this->requestedJob($f['email'], 'reset_password');
        app(IdentityRecovery::class)->consume($job->token, 'reset_password', 'new-strong-password-1234');
        $this->assertTrue(Hash::check('new-strong-password-1234', DB::table('users')->where('id', $f['subject'])->value('password')));
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', $f['subject'])->whereNull('revoked_at')->count());
        $this->assertNotNull(DB::table('device_tokens')->where('device_id', $f['device'])->value('invalidated_at'));
        $this->assertNotNull(DB::table('sharing_grants')->where('id', $f['grant'])->value('revoked_at'));
        $this->assertTrue((bool) DB::table('location_preferences')->where('user_id', $f['subject'])->value('sharing_paused'));
    }

    public function test_expired_token_is_rejected(): void
    {
        $f = $this->configured();
        $job = $this->requestedJob($f['email'], 'reset_password');
        DB::table('identity_action_tokens')->where('id', $job->actionId)->update(['expires_at' => now()->subSecond()]);
        $this->expectException(ApiException::class);
        app(IdentityRecovery::class)->consume($job->token, 'reset_password', 'new-strong-password-1234');
    }

    public function test_forgot_password_response_does_not_reveal_account_existence(): void
    {
        $f = $this->configured();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $f['email']])->assertStatus(202)->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])->assertStatus(202)->json();
        $this->assertSame($known, $unknown);
        Bus::assertDispatchedTimes(SendIdentityAction::class, 1);
    }

    public function test_log_mailer_is_refused_even_when_feature_enabled(): void
    {
        $f = $this->configured();
        config(['mail.default' => 'log']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $f['email']])->assertStatus(503)->assertJsonPath('error.code', 'MAIL_ACTIONS_UNAVAILABLE');
        Bus::assertNotDispatched(SendIdentityAction::class);
        Mail::assertNothingSent();
    }
}
