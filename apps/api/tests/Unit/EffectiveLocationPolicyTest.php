<?php

namespace Tests\Unit;

use App\Modules\Location\Domain\EffectiveLocationPolicy;
use PHPUnit\Framework\TestCase;

final class EffectiveLocationPolicyTest extends TestCase
{
    public function test_limits_intersect_without_plan_name_branching(): void
    {
        $settings = require __DIR__.'/../../config/location.php';
        $policy = EffectiveLocationPolicy::resolve($settings, [
            'history.retention_days' => 30,
            'location.normal.min_interval_seconds' => 120,
        ], 14);
        self::assertSame(14, $policy['history_retention_days']);
        self::assertSame(120, $policy['modes']['normal']['capture_seconds']);
        self::assertSame(120, $policy['modes']['normal']['upload_seconds']);
    }

    public function test_missing_history_entitlement_does_not_allow_history(): void
    {
        $settings = require __DIR__.'/../../config/location.php';
        self::assertSame(0, EffectiveLocationPolicy::resolve($settings, [])['history_retention_days']);
    }

    public function test_platform_retention_cap_cannot_be_overridden(): void
    {
        $settings = require __DIR__.'/../../config/location.php';
        $settings['history_max_days'] = 7;
        self::assertSame(7, EffectiveLocationPolicy::resolve($settings, ['history.retention_days' => 365], 30)['history_retention_days']);
    }
}
