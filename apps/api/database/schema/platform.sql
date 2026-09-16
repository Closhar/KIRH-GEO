-- KIRH GEO, PostgreSQL 16+ / PostGIS 3.x. Run once in an empty database.
-- Application connections use UTC; all instants retain timezone information.
BEGIN;
CREATE EXTENSION IF NOT EXISTS postgis;
SET TIME ZONE 'UTC';

CREATE TABLE users (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name varchar(120) NOT NULL,
 email varchar(254), email_verified_at timestamptz, password varchar(255),
 phone varchar(32), status text NOT NULL DEFAULT 'active' CHECK(status IN ('active','blocked','deletion_pending')),
 locale varchar(12) NOT NULL DEFAULT 'ru', timezone text NOT NULL DEFAULT 'Europe/Moscow',
 remember_token varchar(100), is_platform_admin boolean NOT NULL DEFAULT false,
 app_authentication_secret text, app_authentication_recovery_codes text,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX users_email_unique ON users(lower(email)) WHERE email IS NOT NULL;
CREATE UNIQUE INDEX users_phone_unique ON users(phone) WHERE phone IS NOT NULL;
CREATE TABLE devices (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users,
 installation_id uuid NOT NULL, platform text NOT NULL CHECK(platform IN ('android','ios','web')),
 app_version text, revoked_at timestamptz, last_seen_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(user_id,installation_id), UNIQUE(user_id,id)
);
CREATE TABLE auth_sessions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users, device_id uuid NOT NULL,
 access_token_hash char(64) NOT NULL UNIQUE, access_expires_at timestamptz NOT NULL,
 refresh_token_hash char(64) NOT NULL UNIQUE, family_id uuid NOT NULL, rotated_to_id uuid,
 expires_at timestamptz NOT NULL, revoked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(user_id,device_id) REFERENCES devices(user_id,id)
);
CREATE TABLE identity_action_tokens (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users,
 purpose text NOT NULL CHECK(purpose IN ('verify_email','reset_password')),
 token_hash char(64) NOT NULL UNIQUE, email_hash char(64) NOT NULL,
 expires_at timestamptz NOT NULL, used_at timestamptz, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX identity_action_tokens_user_purpose ON identity_action_tokens(user_id,purpose);
CREATE TABLE workspaces (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name varchar(120) NOT NULL,
 type text NOT NULL DEFAULT 'family' CHECK(type IN ('family','team','business')),
 owner_user_id uuid NOT NULL REFERENCES users, billing_owner_user_id uuid NOT NULL REFERENCES users,
 status text NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended','closed')),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workspace_memberships (
 workspace_id uuid NOT NULL REFERENCES workspaces, user_id uuid NOT NULL REFERENCES users,
 status text NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended','left')),
 joined_at timestamptz NOT NULL DEFAULT now(), left_at timestamptz,
 PRIMARY KEY(workspace_id,user_id), CHECK(left_at IS NULL OR left_at >= joined_at)
);
CREATE TABLE groups (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces,
 name varchar(120) NOT NULL, type text NOT NULL DEFAULT 'family', mutual_tracking_enabled boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(workspace_id,id)
);
CREATE TABLE group_memberships (
 workspace_id uuid NOT NULL, group_id uuid NOT NULL, user_id uuid NOT NULL,
 joined_at timestamptz NOT NULL DEFAULT now(), left_at timestamptz, PRIMARY KEY(group_id,user_id),
 FOREIGN KEY(workspace_id,group_id) REFERENCES groups(workspace_id,id),
 FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships
);
CREATE TABLE invitations (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces, group_id uuid,
 token_hash char(64) NOT NULL UNIQUE, invited_by uuid NOT NULL, accepted_by uuid REFERENCES users,
 expires_at timestamptz NOT NULL, accepted_at timestamptz, revoked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(workspace_id,group_id) REFERENCES groups(workspace_id,id),
 FOREIGN KEY(workspace_id,invited_by) REFERENCES workspace_memberships,
 CHECK((accepted_by IS NULL) = (accepted_at IS NULL))
);
CREATE TABLE group_visibility_permissions (
 workspace_id uuid NOT NULL, group_id uuid NOT NULL, subject_user_id uuid NOT NULL, viewer_user_id uuid NOT NULL,
 allowed boolean NOT NULL DEFAULT false, updated_by uuid NOT NULL, updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(group_id,subject_user_id,viewer_user_id),
 FOREIGN KEY(workspace_id,group_id) REFERENCES groups(workspace_id,id),
 FOREIGN KEY(group_id,subject_user_id) REFERENCES group_memberships(group_id,user_id),
 FOREIGN KEY(group_id,viewer_user_id) REFERENCES group_memberships(group_id,user_id),
 FOREIGN KEY(workspace_id,updated_by) REFERENCES workspace_memberships,
 CHECK(subject_user_id <> viewer_user_id)
);
CREATE TABLE permissions (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), key text NOT NULL UNIQUE, description text NOT NULL DEFAULT '');
CREATE TABLE roles (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), key text NOT NULL UNIQUE, scope text NOT NULL CHECK(scope IN ('workspace','group','admin')));
CREATE TABLE role_permissions (role_id uuid REFERENCES roles, permission_id uuid REFERENCES permissions, PRIMARY KEY(role_id,permission_id));
CREATE TABLE workspace_role_assignments (
 workspace_id uuid NOT NULL, user_id uuid NOT NULL, role_id uuid NOT NULL REFERENCES roles,
 PRIMARY KEY(workspace_id,user_id,role_id), FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships
);
CREATE TABLE group_role_assignments (
 workspace_id uuid NOT NULL, group_id uuid NOT NULL, user_id uuid NOT NULL, role_id uuid NOT NULL REFERENCES roles,
 PRIMARY KEY(group_id,user_id,role_id), FOREIGN KEY(workspace_id,group_id) REFERENCES groups(workspace_id,id),
 FOREIGN KEY(group_id,user_id) REFERENCES group_memberships(group_id,user_id)
);
CREATE TABLE admin_role_assignments (user_id uuid REFERENCES users, role_id uuid REFERENCES roles, PRIMARY KEY(user_id,role_id));
CREATE TABLE consent_logs (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, user_id uuid NOT NULL,
 actor_id uuid NOT NULL REFERENCES users, device_id uuid, purpose text NOT NULL,
 action text NOT NULL CHECK(action IN ('grant','revoke','pause','resume')), audience jsonb NOT NULL,
 policy_version text NOT NULL, consent_version bigint NOT NULL CHECK(consent_version > 0), recorded_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships, FOREIGN KEY(user_id,device_id) REFERENCES devices(user_id,id),
 CHECK(actor_id = user_id), UNIQUE(workspace_id,user_id,id)
);
CREATE TABLE sharing_grants (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, user_id uuid NOT NULL,
 device_id uuid, group_id uuid, scope text NOT NULL CHECK(scope IN ('current','history','both')),
 starts_at timestamptz NOT NULL DEFAULT now(), ends_at timestamptz, revoked_at timestamptz,
 consent_version bigint NOT NULL CHECK(consent_version > 0), consent_log_id uuid NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(workspace_id,id), UNIQUE(workspace_id,user_id,id),
 FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships,
 FOREIGN KEY(workspace_id,user_id,consent_log_id) REFERENCES consent_logs(workspace_id,user_id,id),
 FOREIGN KEY(user_id,device_id) REFERENCES devices(user_id,id), FOREIGN KEY(workspace_id,group_id) REFERENCES groups(workspace_id,id),
 CHECK(ends_at IS NULL OR ends_at > starts_at)
);
CREATE TABLE grant_recipients (
 grant_id uuid NOT NULL, workspace_id uuid NOT NULL, viewer_user_id uuid NOT NULL,
 PRIMARY KEY(grant_id,viewer_user_id), FOREIGN KEY(workspace_id,grant_id) REFERENCES sharing_grants(workspace_id,id),
 FOREIGN KEY(workspace_id,viewer_user_id) REFERENCES workspace_memberships
);
CREATE TABLE location_preferences (
 user_id uuid PRIMARY KEY REFERENCES users, primary_device_id uuid, sharing_paused boolean NOT NULL DEFAULT true,
 revision bigint NOT NULL DEFAULT 1 CHECK(revision > 0), history_retention_days integer NOT NULL DEFAULT 7 CHECK(history_retention_days BETWEEN 0 AND 3650),
 updated_at timestamptz NOT NULL DEFAULT now(), FOREIGN KEY(user_id,primary_device_id) REFERENCES devices(user_id,id)
);

CREATE TABLE plans (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code text NOT NULL, version integer NOT NULL CHECK(version > 0), name text NOT NULL,
 status text NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','archived')), published_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(code,version)
);
CREATE TABLE features (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), key text NOT NULL UNIQUE,
 value_type text NOT NULL CHECK(value_type IN ('boolean','integer','duration')), unit text,
 merge_strategy text NOT NULL DEFAULT 'max' CHECK(merge_strategy IN ('any','max','override'))
);
CREATE TABLE plan_features (
 plan_id uuid REFERENCES plans, feature_id uuid REFERENCES features, value jsonb NOT NULL,
 PRIMARY KEY(plan_id,feature_id), CHECK(jsonb_typeof(value) IN ('boolean','number'))
);
CREATE TABLE plan_prices (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), plan_id uuid NOT NULL REFERENCES plans,
 interval text NOT NULL CHECK(interval IN ('month','year')), currency char(3) NOT NULL DEFAULT 'RUB',
 amount_minor bigint NOT NULL CHECK(amount_minor >= 0), provider text NOT NULL, external_product_id text, external_price_id text,
 active boolean NOT NULL DEFAULT true, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(provider,external_price_id)
);
CREATE TABLE payment_methods (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces,
 provider text NOT NULL, external_id text NOT NULL, status text NOT NULL CHECK(status IN ('active','revoked')),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(provider,external_id), UNIQUE(workspace_id,id)
);
CREATE TABLE subscriptions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces, plan_price_id uuid NOT NULL REFERENCES plan_prices,
 provider text NOT NULL, external_id text, status text NOT NULL CHECK(status IN ('pending','trialing','active','past_due','grace','paused','canceled','expired')),
 period_start timestamptz NOT NULL, period_end timestamptz NOT NULL, cancel_at_period_end boolean NOT NULL DEFAULT false,
 revision bigint NOT NULL DEFAULT 1, provider_updated_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 payment_method_id uuid, auto_renew boolean NOT NULL DEFAULT false,
 FOREIGN KEY(workspace_id,payment_method_id) REFERENCES payment_methods(workspace_id,id),
 UNIQUE(provider,external_id), UNIQUE(workspace_id,id), CHECK(period_end > period_start)
);
CREATE UNIQUE INDEX subscriptions_one_current ON subscriptions(workspace_id) WHERE status IN ('pending','trialing','active','past_due','grace','paused');
CREATE TABLE payments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces, subscription_id uuid,
 provider text NOT NULL, external_id text NOT NULL, idempotency_key uuid NOT NULL UNIQUE,
 amount_minor bigint NOT NULL CHECK(amount_minor >= 0), currency char(3) NOT NULL,
 status text NOT NULL CHECK(status IN ('pending','succeeded','failed','canceled','partially_refunded','refunded')),
 paid_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(provider,external_id), UNIQUE(workspace_id,id),
 billing_period_start timestamptz, billing_period_end timestamptz, recurring boolean NOT NULL DEFAULT false,
 FOREIGN KEY(workspace_id,subscription_id) REFERENCES subscriptions(workspace_id,id)
);
CREATE TABLE billing_operations (
 workspace_id uuid NOT NULL REFERENCES workspaces, user_id uuid NOT NULL REFERENCES users,
 idempotency_key uuid NOT NULL, operation text NOT NULL, payload_hash char(64) NOT NULL,
 response jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(workspace_id,idempotency_key)
);
CREATE TABLE invoices (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces, subscription_id uuid,
 number text NOT NULL UNIQUE, provider_reference text, total_minor bigint NOT NULL CHECK(total_minor >= 0),
 tax_minor bigint NOT NULL DEFAULT 0 CHECK(tax_minor >= 0), currency char(3) NOT NULL, billing_snapshot jsonb NOT NULL DEFAULT '{}', issued_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(workspace_id,subscription_id) REFERENCES subscriptions(workspace_id,id)
);
CREATE TABLE invoice_items (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), invoice_id uuid NOT NULL REFERENCES invoices, description text NOT NULL,
 quantity integer NOT NULL CHECK(quantity > 0), unit_amount_minor bigint NOT NULL CHECK(unit_amount_minor >= 0), total_minor bigint NOT NULL CHECK(total_minor >= 0)
);
CREATE TABLE refunds (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), payment_id uuid NOT NULL REFERENCES payments, provider text NOT NULL,
 external_id text NOT NULL, amount_minor bigint NOT NULL CHECK(amount_minor > 0), status text NOT NULL CHECK(status IN ('pending','succeeded','failed')),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(provider,external_id)
);
CREATE TABLE promo_codes (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code_hash char(64) NOT NULL UNIQUE, label text NOT NULL,
 starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL, max_uses integer CHECK(max_uses > 0),
 uses integer NOT NULL DEFAULT 0 CHECK(uses >= 0), per_user_limit integer NOT NULL DEFAULT 1 CHECK(per_user_limit > 0),
 per_workspace_limit integer NOT NULL DEFAULT 1 CHECK(per_workspace_limit > 0), eligibility jsonb NOT NULL DEFAULT '{}',
 budget_minor bigint CHECK(budget_minor >= 0), spent_minor bigint NOT NULL DEFAULT 0 CHECK(spent_minor >= 0),
 active boolean NOT NULL DEFAULT true, created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(ends_at > starts_at), CHECK(max_uses IS NULL OR uses <= max_uses), CHECK(budget_minor IS NULL OR spent_minor <= budget_minor)
);
CREATE TABLE promo_benefits (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), promo_code_id uuid NOT NULL REFERENCES promo_codes,
 type text NOT NULL CHECK(type IN ('free_access','discount','free_months')), plan_id uuid REFERENCES plans,
 duration_days integer CHECK(duration_days > 0), months integer CHECK(months > 0),
 discount_bps integer CHECK(discount_bps BETWEEN 1 AND 10000), amount_minor bigint CHECK(amount_minor > 0), currency char(3),
 cycles integer CHECK(cycles > 0), CHECK(type <> 'free_months' OR months IS NOT NULL),
 CHECK(type <> 'free_access' OR (duration_days IS NOT NULL AND plan_id IS NOT NULL)),
 CHECK(type <> 'discount' OR ((discount_bps IS NOT NULL)::integer + (amount_minor IS NOT NULL)::integer = 1)),
 CHECK(amount_minor IS NULL OR currency IS NOT NULL)
);
CREATE TABLE promo_redemptions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), promo_code_id uuid NOT NULL REFERENCES promo_codes, workspace_id uuid NOT NULL,
 user_id uuid NOT NULL, idempotency_key uuid NOT NULL, redeemed_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(workspace_id,idempotency_key), FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships
);
CREATE TABLE promotion_applications (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), redemption_id uuid NOT NULL REFERENCES promo_redemptions,
 benefit_id uuid NOT NULL REFERENCES promo_benefits, snapshot jsonb NOT NULL, starts_at timestamptz NOT NULL, ends_at timestamptz,
 cycles_remaining integer CHECK(cycles_remaining >= 0), status text NOT NULL CHECK(status IN ('scheduled','active','exhausted','canceled')),
 UNIQUE(redemption_id,benefit_id), CHECK(ends_at IS NULL OR ends_at > starts_at)
);
CREATE TABLE billing_credits (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces,
 application_id uuid REFERENCES promotion_applications, amount_minor bigint NOT NULL CHECK(amount_minor > 0),
 remaining_minor bigint NOT NULL CHECK(remaining_minor >= 0), currency char(3) NOT NULL, expires_at timestamptz,
 idempotency_key text NOT NULL UNIQUE, CHECK(remaining_minor <= amount_minor)
);
CREATE TABLE trials (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces, campaign_id text NOT NULL,
 starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL, status text NOT NULL CHECK(status IN ('active','converted','expired','canceled')),
 UNIQUE(workspace_id,campaign_id), CHECK(ends_at > starts_at)
);
CREATE TABLE entitlements (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces, feature_id uuid NOT NULL REFERENCES features,
 source_type text NOT NULL CHECK(source_type IN ('plan','subscription','trial','promo','support')), source_id uuid NOT NULL,
 value jsonb NOT NULL CHECK(jsonb_typeof(value) IN ('boolean','number')), priority integer NOT NULL DEFAULT 0,
 starts_at timestamptz NOT NULL, ends_at timestamptz, revoked_at timestamptz,
 UNIQUE(workspace_id,feature_id,source_type,source_id), CHECK(ends_at IS NULL OR ends_at > starts_at)
);
CREATE INDEX entitlements_resolve ON entitlements(workspace_id,feature_id,starts_at,ends_at) WHERE revoked_at IS NULL;
CREATE TABLE usage_counters (
 workspace_id uuid NOT NULL REFERENCES workspaces, feature_id uuid NOT NULL REFERENCES features,
 period_start timestamptz NOT NULL, period_end timestamptz NOT NULL,
 consumed bigint NOT NULL DEFAULT 0 CHECK(consumed >= 0), reserved bigint NOT NULL DEFAULT 0 CHECK(reserved >= 0),
 PRIMARY KEY(workspace_id,feature_id,period_start), CHECK(period_end > period_start)
);
CREATE TABLE usage_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, feature_id uuid NOT NULL,
 period_start timestamptz NOT NULL, idempotency_key text NOT NULL, quantity bigint NOT NULL CHECK(quantity > 0), occurred_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(workspace_id,idempotency_key), FOREIGN KEY(workspace_id,feature_id,period_start) REFERENCES usage_counters(workspace_id,feature_id,period_start)
);
CREATE TABLE usage_reservations (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, feature_id uuid NOT NULL, period_start timestamptz NOT NULL,
 quantity bigint NOT NULL CHECK(quantity > 0), expires_at timestamptz NOT NULL,
 status text NOT NULL CHECK(status IN ('reserved','consumed','released')), idempotency_key text NOT NULL,
 UNIQUE(workspace_id,idempotency_key), FOREIGN KEY(workspace_id,feature_id,period_start) REFERENCES usage_counters(workspace_id,feature_id,period_start)
);
CREATE TABLE payment_webhook_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), provider text NOT NULL, external_event_id text NOT NULL, payload_hash char(64) NOT NULL,
 payload_encrypted text NOT NULL, received_at timestamptz NOT NULL DEFAULT now(), processed_at timestamptz,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processed','failed','ignored')), attempts integer NOT NULL DEFAULT 0,
 UNIQUE(provider,external_event_id)
);

CREATE TABLE partners (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL UNIQUE REFERENCES users,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','suspended')), payout_profile_reference text,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE partner_programs (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text NOT NULL, active boolean NOT NULL DEFAULT false);
CREATE TABLE partner_program_versions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), program_id uuid NOT NULL REFERENCES partner_programs, version integer NOT NULL CHECK(version > 0),
 status text NOT NULL CHECK(status IN ('draft','published','archived')), rules jsonb NOT NULL,
 rate_bps integer CHECK(rate_bps BETWEEN 0 AND 10000), fixed_minor bigint CHECK(fixed_minor >= 0), currency char(3) NOT NULL DEFAULT 'RUB',
 hold_days integer NOT NULL CHECK(hold_days >= 0), minimum_payout_minor bigint NOT NULL CHECK(minimum_payout_minor >= 0),
 published_at timestamptz, UNIQUE(program_id,version), CHECK((rate_bps IS NOT NULL)::integer + (fixed_minor IS NOT NULL)::integer = 1)
);
CREATE TABLE referral_links (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), partner_id uuid NOT NULL REFERENCES partners,
 program_version_id uuid NOT NULL REFERENCES partner_program_versions, token_hash char(64) NOT NULL UNIQUE,
 campaign text, expires_at timestamptz, revoked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE referral_codes (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), partner_id uuid NOT NULL REFERENCES partners,
 program_version_id uuid NOT NULL REFERENCES partner_program_versions, promo_code_id uuid REFERENCES promo_codes,
 code_hash char(64) NOT NULL UNIQUE, expires_at timestamptz, revoked_at timestamptz
);
CREATE TABLE referral_attributions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL UNIQUE REFERENCES workspaces, partner_id uuid NOT NULL REFERENCES partners,
 program_version_id uuid NOT NULL REFERENCES partner_program_versions, referral_link_id uuid REFERENCES referral_links, referral_code_id uuid REFERENCES referral_codes,
 source text NOT NULL CHECK(source IN ('link','code','manual')), attributed_at timestamptz NOT NULL DEFAULT now(), locked_at timestamptz
);
CREATE TABLE partner_commissions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), payment_id uuid NOT NULL REFERENCES payments, attribution_id uuid NOT NULL REFERENCES referral_attributions,
 program_version_id uuid NOT NULL REFERENCES partner_program_versions, commission_kind text NOT NULL DEFAULT 'payment',
 base_minor bigint NOT NULL CHECK(base_minor >= 0), amount_minor bigint NOT NULL CHECK(amount_minor >= 0), currency char(3) NOT NULL,
 calculation_snapshot jsonb NOT NULL, hold_until timestamptz NOT NULL,
 status text NOT NULL CHECK(status IN ('pending','held','payable','reserved','paid','canceled','reversed')),
 UNIQUE(payment_id,attribution_id,commission_kind)
);
CREATE TABLE partner_payouts (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), partner_id uuid NOT NULL REFERENCES partners, amount_minor bigint NOT NULL CHECK(amount_minor > 0),
 currency char(3) NOT NULL, status text NOT NULL CHECK(status IN ('requested','reserved','processing','paid','failed','canceled')),
 idempotency_key text NOT NULL UNIQUE, external_reference text, requested_at timestamptz NOT NULL DEFAULT now(), paid_at timestamptz
);
CREATE TABLE partner_payout_items (
 payout_id uuid NOT NULL REFERENCES partner_payouts, commission_id uuid NOT NULL UNIQUE REFERENCES partner_commissions,
 amount_minor bigint NOT NULL CHECK(amount_minor > 0), PRIMARY KEY(payout_id,commission_id)
);
CREATE TABLE partner_ledger_entries (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), partner_id uuid NOT NULL REFERENCES partners, commission_id uuid REFERENCES partner_commissions,
 payout_id uuid REFERENCES partner_payouts, refund_id uuid REFERENCES refunds, reverses_id uuid REFERENCES partner_ledger_entries,
 type text NOT NULL CHECK(type IN ('accrual','reversal','reserve','release','payout')), amount_minor bigint NOT NULL CHECK(amount_minor <> 0),
 currency char(3) NOT NULL, idempotency_key text NOT NULL UNIQUE, created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE location_batches (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), device_id uuid NOT NULL REFERENCES devices, client_batch_id uuid NOT NULL,
 payload_hash char(64) NOT NULL, response_summary jsonb NOT NULL, accepted_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(device_id,client_batch_id)
);
CREATE TABLE location_point_receipts (
 device_id uuid NOT NULL REFERENCES devices, client_point_id uuid NOT NULL, payload_hash char(64) NOT NULL,
 point_id uuid NOT NULL, point_time timestamptz NOT NULL, received_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz NOT NULL, PRIMARY KEY(device_id,client_point_id)
);
CREATE TABLE location_points (
 id uuid NOT NULL DEFAULT gen_random_uuid(), device_id uuid NOT NULL, user_id uuid NOT NULL REFERENCES users, client_point_id uuid NOT NULL,
 captured_at timestamptz NOT NULL, received_at timestamptz NOT NULL DEFAULT now(), position geography(Point,4326) NOT NULL,
 accuracy_m double precision NOT NULL CHECK(accuracy_m >= 0 AND accuracy_m < 100000), altitude_m double precision,
 speed_mps double precision CHECK(speed_mps BETWEEN 0 AND 1500), heading double precision CHECK(heading >= 0 AND heading < 360),
 battery_pct smallint CHECK(battery_pct BETWEEN 0 AND 100), mode text NOT NULL CHECK(mode IN ('idle','normal','live','sport','sos')),
 consent_version bigint NOT NULL CHECK(consent_version > 0), PRIMARY KEY(captured_at,id),
 FOREIGN KEY(user_id,device_id) REFERENCES devices(user_id,id)
) PARTITION BY RANGE(captured_at);
CREATE INDEX location_points_user_time ON location_points(user_id,captured_at DESC);
CREATE INDEX location_points_device_time ON location_points(device_id,captured_at DESC);
-- Provision previous/current/next UTC months. Scheduler must create future partitions.
DO $$ DECLARE month_start timestamptz; i integer; BEGIN
 FOR i IN -1..2 LOOP
  month_start := date_trunc('month',now()) + make_interval(months => i);
  EXECUTE format('CREATE TABLE %I PARTITION OF location_points FOR VALUES FROM (%L) TO (%L)',
   'location_points_' || to_char(month_start,'YYYY_MM'), month_start, month_start + interval '1 month');
 END LOOP;
END $$;
CREATE TABLE location_point_audiences (
 captured_at timestamptz NOT NULL, point_id uuid NOT NULL, workspace_id uuid NOT NULL, grant_id uuid NOT NULL,
 expires_at timestamptz NOT NULL, PRIMARY KEY(captured_at,point_id,grant_id),
 FOREIGN KEY(captured_at,point_id) REFERENCES location_points(captured_at,id) ON DELETE CASCADE,
 FOREIGN KEY(workspace_id,grant_id) REFERENCES sharing_grants(workspace_id,id), CHECK(expires_at >= captured_at)
);
CREATE INDEX location_audiences_history ON location_point_audiences(workspace_id,captured_at,expires_at);
CREATE TABLE geofences (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, group_id uuid, owner_id uuid NOT NULL, name varchar(120) NOT NULL,
 center geography(Point,4326) NOT NULL, radius_m integer NOT NULL CHECK(radius_m BETWEEN 50 AND 100000),
 hysteresis_m integer NOT NULL DEFAULT 25 CHECK(hysteresis_m >= 0), dwell_seconds integer NOT NULL DEFAULT 30 CHECK(dwell_seconds >= 0),
 active boolean NOT NULL DEFAULT true, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(workspace_id,id),
 FOREIGN KEY(workspace_id,owner_id) REFERENCES workspace_memberships, FOREIGN KEY(workspace_id,group_id) REFERENCES groups(workspace_id,id)
);
CREATE INDEX geofences_center_gist ON geofences USING gist(center);
CREATE TABLE geofence_targets (
 workspace_id uuid NOT NULL, geofence_id uuid NOT NULL, user_id uuid NOT NULL, PRIMARY KEY(geofence_id,user_id),
 FOREIGN KEY(workspace_id,geofence_id) REFERENCES geofences(workspace_id,id), FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships
);
CREATE TABLE geofence_states (
 geofence_id uuid NOT NULL, user_id uuid NOT NULL, state text NOT NULL CHECK(state IN ('unknown','inside','outside')),
 last_processed_at timestamptz, candidate_since timestamptz, candidate_state text CHECK(candidate_state IN ('inside','outside')),
 transition_seq bigint NOT NULL DEFAULT 0, PRIMARY KEY(geofence_id,user_id), FOREIGN KEY(geofence_id,user_id) REFERENCES geofence_targets
);
CREATE TABLE geofence_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, geofence_id uuid NOT NULL, user_id uuid NOT NULL,
 transition_seq bigint NOT NULL, type text NOT NULL CHECK(type IN ('enter','exit')), occurred_at timestamptz NOT NULL,
 detected_at timestamptz NOT NULL DEFAULT now(), point_id uuid NOT NULL,
 UNIQUE(geofence_id,user_id,transition_seq), FOREIGN KEY(workspace_id,geofence_id) REFERENCES geofences(workspace_id,id),
 FOREIGN KEY(geofence_id,user_id) REFERENCES geofence_targets
);
CREATE TABLE sos_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, user_id uuid NOT NULL, device_id uuid NOT NULL,
 idempotency_key uuid NOT NULL, started_at timestamptz NOT NULL DEFAULT now(), ended_at timestamptz,
 status text NOT NULL DEFAULT 'active' CHECK(status IN ('active','ended')), UNIQUE(workspace_id,user_id,idempotency_key), UNIQUE(workspace_id,id),
 FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships, FOREIGN KEY(user_id,device_id) REFERENCES devices(user_id,id)
);
CREATE TABLE sos_acknowledgements (
 workspace_id uuid NOT NULL, sos_event_id uuid NOT NULL, user_id uuid NOT NULL, acknowledged_at timestamptz,
 PRIMARY KEY(sos_event_id,user_id), FOREIGN KEY(workspace_id,sos_event_id) REFERENCES sos_events(workspace_id,id),
 FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships
);
CREATE TABLE live_sessions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, initiator_id uuid NOT NULL,
 starts_at timestamptz NOT NULL DEFAULT now(), expires_at timestamptz NOT NULL,
 status text NOT NULL CHECK(status IN ('requested','active','ended','expired')), UNIQUE(workspace_id,id),
 FOREIGN KEY(workspace_id,initiator_id) REFERENCES workspace_memberships, CHECK(expires_at > starts_at)
);
CREATE TABLE live_session_participants (
 workspace_id uuid NOT NULL, session_id uuid NOT NULL, user_id uuid NOT NULL, consent_grant_id uuid,
 accepted_at timestamptz, ended_at timestamptz, PRIMARY KEY(session_id,user_id),
 FOREIGN KEY(workspace_id,session_id) REFERENCES live_sessions(workspace_id,id), FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships,
 FOREIGN KEY(workspace_id,user_id,consent_grant_id) REFERENCES sharing_grants(workspace_id,user_id,id)
);
CREATE TABLE temporary_shares (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL, issuer_id uuid NOT NULL, subject_id uuid NOT NULL,
 token_hash char(64) NOT NULL UNIQUE, grant_id uuid NOT NULL, expires_at timestamptz NOT NULL, revoked_at timestamptz,
 scope text NOT NULL DEFAULT 'current' CHECK(scope IN ('current','history','both')), passcode_hash varchar(255),
 created_at timestamptz NOT NULL DEFAULT now(), FOREIGN KEY(workspace_id,issuer_id) REFERENCES workspace_memberships,
 FOREIGN KEY(workspace_id,subject_id,grant_id) REFERENCES sharing_grants(workspace_id,user_id,id),
 CHECK(issuer_id = subject_id), CHECK(expires_at > created_at)
);
CREATE TABLE activity_sessions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users, workspace_id uuid, device_id uuid NOT NULL,
 type text NOT NULL DEFAULT 'sport', started_at timestamptz NOT NULL, ended_at timestamptz,
 status text NOT NULL CHECK(status IN ('active','paused','finished','discarded')),
 FOREIGN KEY(workspace_id,user_id) REFERENCES workspace_memberships, FOREIGN KEY(user_id,device_id) REFERENCES devices(user_id,id)
);
CREATE TABLE activity_laps (
 session_id uuid NOT NULL REFERENCES activity_sessions, sequence integer NOT NULL CHECK(sequence > 0),
 started_at timestamptz NOT NULL, ended_at timestamptz NOT NULL, PRIMARY KEY(session_id,sequence), CHECK(ended_at >= started_at)
);
CREATE TABLE activity_metric_samples (
 session_id uuid NOT NULL REFERENCES activity_sessions, recorded_at timestamptz NOT NULL, metric text NOT NULL,
 value double precision NOT NULL, unit text NOT NULL, PRIMARY KEY(session_id,recorded_at,metric)
);

CREATE TABLE notifications (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users, workspace_id uuid REFERENCES workspaces,
 type text NOT NULL, event_id uuid NOT NULL, payload jsonb NOT NULL DEFAULT '{}', read_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(user_id,type,event_id)
);
CREATE TABLE notification_deliveries (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), notification_id uuid NOT NULL REFERENCES notifications,
 channel text NOT NULL CHECK(channel IN ('push','websocket','email')), dedup_key text NOT NULL UNIQUE,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','delivered','failed','canceled')), attempts integer NOT NULL DEFAULT 0,
 delivered_at timestamptz, next_attempt_at timestamptz, UNIQUE(notification_id,channel)
);
CREATE TABLE notification_preferences (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users, workspace_id uuid REFERENCES workspaces,
 type text NOT NULL, channel text NOT NULL CHECK(channel IN ('push','websocket','email')), enabled boolean NOT NULL DEFAULT true,
 UNIQUE NULLS NOT DISTINCT(user_id,workspace_id,type,channel)
);
CREATE TABLE device_tokens (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), device_id uuid NOT NULL REFERENCES devices, provider text NOT NULL CHECK(provider IN ('apns','fcm')),
 token_encrypted text NOT NULL, token_fingerprint char(64) NOT NULL, invalidated_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(provider,token_fingerprint)
);
CREATE TABLE audit_logs (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), actor_id uuid REFERENCES users, workspace_id uuid REFERENCES workspaces,
 action text NOT NULL, target_type text NOT NULL, target_id uuid, correlation_id uuid,
 reason text, redacted_changes jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE system_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), component text NOT NULL, severity text NOT NULL CHECK(severity IN ('info','warning','error','critical')),
 event_code text NOT NULL, correlation_id uuid, redacted_context jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE application_settings (
 namespace text NOT NULL, key text NOT NULL, value jsonb NOT NULL, version bigint NOT NULL DEFAULT 1 CHECK(version > 0),
 updated_by uuid REFERENCES users, updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(namespace,key)
);
CREATE TABLE outbox_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid REFERENCES workspaces, type text NOT NULL, schema_version integer NOT NULL DEFAULT 1,
 aggregate_id uuid NOT NULL, payload jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), available_at timestamptz NOT NULL DEFAULT now(),
 processed_at timestamptz, attempts integer NOT NULL DEFAULT 0, locked_until timestamptz, last_error_code text
);
CREATE INDEX outbox_pending ON outbox_events(available_at) WHERE processed_at IS NULL;
CREATE TABLE consumer_receipts (consumer text NOT NULL, event_id uuid NOT NULL REFERENCES outbox_events, consumed_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(consumer,event_id));
CREATE TABLE export_requests (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','completed','failed','expired')),
 requested_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz, expires_at timestamptz, storage_reference text
);
CREATE TABLE deletion_requests (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES users,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','completed','failed','canceled')),
 requested_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz, retention_exceptions jsonb NOT NULL DEFAULT '[]'
);
-- A pseudonymous tombstone survives identity deletion and is replayed after restore.
CREATE TABLE deletion_tombstones (subject_hash char(64) PRIMARY KEY, deleted_at timestamptz NOT NULL, scope jsonb NOT NULL);

-- DB-level append-only guard. Migration/maintenance role may explicitly disable it
-- for an approved retention operation; runtime role must not own these tables.
CREATE FUNCTION reject_immutable_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'append-only table: %', TG_TABLE_NAME; END $$;
CREATE TRIGGER audit_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_immutable_change();
CREATE TRIGGER consent_immutable BEFORE UPDATE OR DELETE ON consent_logs FOR EACH ROW EXECUTE FUNCTION reject_immutable_change();
CREATE TRIGGER ledger_immutable BEFORE UPDATE OR DELETE ON partner_ledger_entries FOR EACH ROW EXECUTE FUNCTION reject_immutable_change();
COMMIT;
