<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Consent\ConsentService;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class IdentityRecovery
{
    public function assertMailConfigured(): void
    {
        $mailer = config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        $url = config('identity.action_url');
        $queueDriver = config('queue.connections.'.config('queue.default').'.driver');
        if (! config('identity.mail_actions_enabled') || $transport !== 'smtp' || ! is_string($url)
            || ! in_array($queueDriver, ['database', 'redis', 'sqs', 'beanstalkd'], true)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! parse_url($url, PHP_URL_HOST)
            || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)) {
            throw new ApiException('MAIL_ACTIONS_UNAVAILABLE', 'Подтверждение почты и восстановление пока не настроены.', 503);
        }
    }

    public function request(string $email, string $purpose): void
    {
        $this->assertMailConfigured();
        if (! in_array($purpose, ['reset_password', 'verify_email'], true)) {
            throw new \InvalidArgumentException('Unknown identity action.');
        }
        $email = mb_strtolower(trim($email));
        $user = DB::table('users')->whereRaw('lower(email)=?', [$email])->where('status', 'active')->first();
        if (! $user || ($purpose === 'verify_email' && $user->email_verified_at)) {
            return; // The HTTP response is identical for missing/blocked/already-verified accounts.
        }
        DB::transaction(function () use ($user, $purpose, $email): void {
            $current = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if ($current->status !== 'active' || mb_strtolower($current->email ?? '') !== $email) {
                return;
            }
            if (DB::table('identity_action_tokens')->where('user_id', $user->id)->where('purpose', $purpose)->where('created_at', '>', now()->subMinute())->exists()) {
                return; // Per-identity cooldown also applies behind shared IPs and hides account existence.
            }
            DB::table('identity_action_tokens')->where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')->update(['used_at' => now()]);
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $ttl = config('identity.'.($purpose === 'reset_password' ? 'reset_ttl_minutes' : 'verify_ttl_minutes'));
            DB::table('identity_action_tokens')->insert(['id' => $id, 'user_id' => $user->id, 'purpose' => $purpose,
                'token_hash' => hash('sha256', $token), 'email_hash' => hash('sha256', $email), 'expires_at' => now()->addMinutes($ttl)]);
            SendIdentityAction::dispatch($id, $token)->afterCommit();
        }, 3);
    }

    public function consume(string $token, string $purpose, ?string $password = null): void
    {
        $this->assertMailConfigured();
        $action = DB::table('identity_action_tokens')->where('token_hash', hash('sha256', $token))->where('purpose', $purpose)->first();
        if (! $action) {
            throw new ApiException('ACTION_TOKEN_INVALID', 'Ссылка истекла или уже использована.', 422);
        }
        DB::transaction(function () use ($action, $purpose, $password): void {
            $user = DB::table('users')->where('id', $action->user_id)->lockForUpdate()->first();
            $current = DB::table('identity_action_tokens')->where('id', $action->id)->lockForUpdate()->first();
            if ($current->used_at || now()->gte($current->expires_at) || $user->status !== 'active'
                || ! hash_equals($current->email_hash, hash('sha256', mb_strtolower($user->email ?? '')))) {
                throw new ApiException('ACTION_TOKEN_INVALID', 'Ссылка истекла или уже использована.', 422);
            }
            DB::table('identity_action_tokens')->where('id', $current->id)->update(['used_at' => now()]);
            if ($purpose === 'verify_email') {
                DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
            } else {
                if ($password === null) {
                    throw new \InvalidArgumentException('New password required.');
                }
                DB::table('users')->where('id', $user->id)->update(['password' => Hash::make($password), 'remember_token' => null, 'updated_at' => now()]);
                DB::table('auth_sessions')->where('user_id', $user->id)->update(['revoked_at' => now()]);
                DB::table('device_tokens')->whereIn('device_id', DB::table('devices')->where('user_id', $user->id)->select('id'))->update(['invalidated_at' => now()]);
                app(ConsentService::class)->pause($user->id);
                DB::table('identity_action_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            }
            Log::info('identity.action_completed', ['action_id' => $current->id, 'purpose' => $purpose]);
        }, 3);
    }
}
