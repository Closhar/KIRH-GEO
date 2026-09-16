<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Mail\IdentityActionMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class SendIdentityAction implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $actionId, public string $token) {}

    public function handle(IdentityRecovery $recovery): void
    {
        $recovery->assertMailConfigured();
        $action = DB::table('identity_action_tokens as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.id', $this->actionId)->whereNull('a.used_at')->where('a.expires_at', '>', now())
            ->where('u.status', 'active')->select('a.*', 'u.email')->first();
        if (! $action || ! hash_equals($action->email_hash, hash('sha256', mb_strtolower($action->email)))) {
            return;
        }
        $url = rtrim(config('identity.action_url'), '#').'#action='.$action->purpose.'&token='.$this->token;
        Mail::to($action->email)->send(new IdentityActionMail($url, $action->purpose));
    }
}
