<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS identity_action_tokens (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users,
 purpose text NOT NULL CHECK(purpose IN ('verify_email','reset_password')),
 token_hash char(64) NOT NULL UNIQUE, email_hash char(64) NOT NULL,
 expires_at timestamptz NOT NULL, used_at timestamptz, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS identity_action_tokens_user_purpose ON identity_action_tokens(user_id,purpose);
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS identity_action_tokens');
    }
};
