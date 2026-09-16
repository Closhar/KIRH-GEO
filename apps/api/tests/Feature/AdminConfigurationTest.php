<?php

namespace Tests\Feature;

use App\Filament\Pages\LocationPolicy;
use App\Filament\Pages\PartnerProgram;
use App\Filament\Pages\PromoCodes;
use App\Filament\Pages\SupportOverview;
use App\Filament\Pages\Tariffs;
use App\Filament\Partner\Pages\PartnerDashboard;
use App\Filament\Support\Administration;
use App\Models\User;
use App\Modules\Access\AccessSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        DB::table('admin_role_assignments')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'platform_admin')->value('id')]);
        $this->actingAs($user);

        return $user;
    }

    public function test_cli_admin_assignment_is_explicit_audited_and_revocable(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->assertSame(0, Artisan::call('geo:admin', ['email' => $user->email, '--reason' => 'Initial platform operator', '--yes' => true]));
        $this->assertDatabaseHas('admin_role_assignments', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $user->id, 'action' => 'admin.role.granted.cli']);
        $this->assertSame(0, Artisan::call('geo:admin', ['email' => $user->email, '--reason' => 'Operator access removed', '--revoke' => true, '--yes' => true]));
        $this->assertDatabaseMissing('admin_role_assignments', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $user->id, 'action' => 'admin.role.revoked.cli']);
    }

    public function test_members_and_platform_flag_alone_cannot_enter_or_mutate_admin(): void
    {
        $user = User::factory()->create(['status' => 'active', 'is_platform_admin' => true]);
        $this->actingAs($user)->get('/admin/location-policy')->assertForbidden();
        $this->expectException(HttpException::class);
        app(Administration::class)->saveLocation(config('location'), 1, 'Should be denied');
    }

    public function test_admin_requires_totp_setup_and_secret_is_encrypted_and_hidden(): void
    {
        $admin = $this->admin();
        $this->assertNotSame('JBSWY3DPEHPK3PXP', DB::table('users')->where('id', $admin->id)->value('app_authentication_secret'));
        $this->assertArrayNotHasKey('app_authentication_secret', $admin->toArray());
        $admin->saveAppAuthenticationSecret(null);
        $this->get('/admin/location-policy')->assertRedirect();
        $this->assertTrue(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired());
    }

    public function test_admin_pages_render_and_location_policy_saves_with_audit(): void
    {
        $this->admin();
        foreach ([LocationPolicy::class, Tariffs::class, PromoCodes::class, PartnerProgram::class] as $page) {
            Livewire::test($page)->assertSuccessful();
        }
        Livewire::test(LocationPolicy::class)
            ->set('data.history_max_days', 45)->set('data.modes.normal.capture_seconds', 30)
            ->set('reason', 'Настройка частоты для пилота')->call('save')->assertHasNoErrors();
        $value = json_decode(DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->value('value'), true);
        $this->assertSame(45, $value['history_max_days']);
        $this->assertSame(30, $value['modes']['normal']['capture_seconds']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'location.settings.updated']);
    }

    public function test_policy_rejects_invalid_interval_and_stale_write(): void
    {
        $this->admin();
        Livewire::test(LocationPolicy::class)->set('data.modes.live.capture_seconds', 0)
            ->set('reason', 'Invalid setting test')->call('save')->assertHasErrors();
        $policy = config('location');
        $version = (int) DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->value('version');
        app(Administration::class)->saveLocation($policy, $version, 'First admin saves');
        $this->expectException(ValidationException::class);
        app(Administration::class)->saveLocation($policy, $version, 'Second admin stale save');
    }

    public function test_plan_versions_keep_published_prices_and_limits_immutable(): void
    {
        $this->admin();
        $features = [];
        foreach (DB::table('features')->get() as $feature) {
            $features[$feature->id] = $feature->value_type === 'boolean' ? true : 10;
        }
        $data = ['code' => 'family_test', 'name' => 'Семья', 'month_minor' => 9900, 'year_minor' => 99000, 'provider' => 'sandbox', 'features' => $features];
        $service = app(Administration::class);
        $first = $service->createPlanDraft($data, 'Initial test draft');
        $service->publishPlan($first, 'Publish tested tariff');
        $data['month_minor'] = 19900;
        $second = $service->createPlanDraft($data, 'New revision price');
        $this->assertDatabaseHas('plan_prices', ['plan_id' => $first, 'interval' => 'month', 'amount_minor' => 9900]);
        $this->assertDatabaseHas('plans', ['id' => $second, 'version' => 2, 'status' => 'draft']);
        $this->expectException(HttpException::class);
        $service->publishPlan($first, 'Cannot publish twice');
    }

    public function test_all_promo_types_create_valid_benefits_without_plaintext_code(): void
    {
        $this->admin();
        $base = ['label' => 'Test campaign', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addMonth()->toIso8601String(),
            'max_uses' => 20, 'per_user_limit' => 1, 'per_workspace_limit' => 1, 'plan_id' => DB::table('plans')->where('status', 'published')->value('id'),
            'duration_days' => 30, 'months' => 2, 'discount_kind' => 'percent', 'discount_value' => 25, 'cycles' => 1];
        foreach (['free_access', 'discount', 'free_months'] as $type) {
            $code = 'TEST_'.$type;
            $id = app(Administration::class)->createPromo($base + ['type' => $type, 'code' => $code], 'Create campaign benefit');
            $this->assertDatabaseHas('promo_benefits', ['promo_code_id' => $id, 'type' => $type]);
            $this->assertDatabaseHas('promo_codes', ['id' => $id, 'code_hash' => hash('sha256', strtoupper($code))]);
        }
    }

    public function test_partner_program_publishes_version_and_requires_audit_reason(): void
    {
        $this->admin();
        Livewire::test(PartnerProgram::class)->set('reason', 'Partner launch terms')->call('saveDraft')->assertHasNoErrors();
        $id = DB::table('partner_program_versions')->where('status', 'draft')->value('id');
        app(Administration::class)->publishPartnerVersion($id, 'Approved launch terms');
        $this->assertDatabaseHas('partner_program_versions', ['id' => $id, 'status' => 'published', 'rate_bps' => 1000]);
        $this->expectException(ValidationException::class);
        app(Administration::class)->saveTrial(10, 0, '');
    }

    public function test_permission_revocation_blocks_already_open_livewire_page(): void
    {
        $user = $this->admin();
        $component = Livewire::test(LocationPolicy::class)->set('reason', 'Attempt after revoke');
        DB::table('admin_role_assignments')->where('user_id', $user->id)->delete();
        $component->call('save')->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'location.settings.updated']);
    }

    public function test_partner_dashboard_is_scoped_and_does_not_grant_admin_access(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        DB::table('partners')->insert(['user_id' => $user->id, 'status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $otherPartner = (string) Str::uuid();
        DB::table('partners')->insert(['id' => $otherPartner, 'user_id' => $other->id, 'status' => 'active']);
        DB::table('partner_payouts')->insert(['partner_id' => $otherPartner, 'amount_minor' => 987654321,
            'currency' => 'RUB', 'status' => 'paid', 'idempotency_key' => 'other-partner-test']);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Livewire::test(PartnerDashboard::class)->assertSuccessful()->assertDontSee('9 876 543');
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('partner')));
    }

    public function test_support_metadata_has_explicit_columns_and_requires_own_permission(): void
    {
        $admin = $this->admin();
        $component = Livewire::test(SupportOverview::class);
        $component->assertSuccessful()->assertDontSee('app_authentication_secret')->assertDontSee('JBSWY3DPEHPK3PXP');
        foreach (['workspaces', 'subscriptions', 'usage', 'audit'] as $section) {
            $component->call('selectSection', $section)->assertSuccessful();
        }
        $permissionId = DB::table('permissions')->where('key', 'admin.support.read')->value('id');
        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        $component->call('selectSection', 'users')->assertForbidden();
    }
}
