<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class GrantAdministrator extends Command
{
    protected $signature = 'geo:admin {email : Existing account email} {--reason= : Audit reason} {--revoke : Remove platform role} {--yes : Confirm non-interactively}';

    protected $description = 'Explicit audited operator-only platform administrator assignment; never creates a default password';

    public function handle(): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500) {
            $this->error('Provide --reason of 8–500 characters.');

            return self::FAILURE;
        }
        $user = DB::table('users')->whereRaw('lower(email) = ?', [mb_strtolower(trim($this->argument('email')))])->where('status', 'active')->first();
        $role = DB::table('roles')->where('key', 'platform_admin')->where('scope', 'admin')->first();
        if (! $user || ! $role) {
            $this->error('Active account or seeded admin role not found.');

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! $this->confirm('Change platform access for user '.$user->id.'?')) {
            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $role, $reason): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if ($this->option('revoke')) {
                DB::table('admin_role_assignments')->where('user_id', $user->id)->where('role_id', $role->id)->delete();
            } else {
                DB::table('admin_role_assignments')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $role->id]);
            }
            DB::table('audit_logs')->insert(['actor_id' => null, 'action' => $this->option('revoke') ? 'admin.role.revoked.cli' : 'admin.role.granted.cli',
                'target_type' => 'user', 'target_id' => $user->id, 'reason' => $reason, 'redacted_changes' => json_encode(['role_id' => $role->id])]);
        });
        $this->info('Role updated. The admin panel requires TOTP setup at next sign-in.');

        return self::SUCCESS;
    }
}
