<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_methods (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces,
 provider text NOT NULL, external_id text NOT NULL, status text NOT NULL CHECK(status IN ('active','revoked')),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(provider,external_id), UNIQUE(workspace_id,id)
);
ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS payment_method_id uuid;
ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS auto_renew boolean NOT NULL DEFAULT false;
DO $$ BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='subscriptions'::regclass AND confrelid='payment_methods'::regclass) THEN
  ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_payment_method_scope_fk FOREIGN KEY(workspace_id,payment_method_id) REFERENCES payment_methods(workspace_id,id);
 END IF;
END $$;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS billing_period_start timestamptz;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS billing_period_end timestamptz;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS recurring boolean NOT NULL DEFAULT false;
ALTER TABLE users ADD COLUMN IF NOT EXISTS app_authentication_secret text;
ALTER TABLE users ADD COLUMN IF NOT EXISTS app_authentication_recovery_codes text;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Payment method data requires an explicit reviewed rollback migration.');
    }
};
