<?php

declare(strict_types=1);

namespace App\Modules\Location\Domain;

use InvalidArgumentException;

final class EffectiveLocationPolicy
{
    /** Pure resolver: smaller intervals mean more resource use; smaller retention means less data. */
    public static function resolve(array $settings, array $entitlements, ?int $subjectRetention = null): array
    {
        $modes = [];
        foreach (LocationMode::cases() as $mode) {
            $capture = (int) ($settings['modes'][$mode->value]['capture_seconds'] ?? 0);
            $upload = (int) ($settings['modes'][$mode->value]['upload_seconds'] ?? 0);
            if ($capture < 1 || $upload < 1) {
                throw new InvalidArgumentException('Every location mode must have positive intervals.');
            }
            $minimum = max(1, (int) ($entitlements['location.'.$mode->value.'.min_interval_seconds'] ?? $capture));
            $modes[$mode->value] = [
                'capture_seconds' => max($capture, $minimum),
                'upload_seconds' => max($upload, $minimum),
            ];
        }
        $retention = min(
            max(0, (int) ($settings['history_max_days'] ?? 0)),
            max(0, (int) ($entitlements['history.retention_days'] ?? 0)),
            $subjectRetention === null ? PHP_INT_MAX : max(0, $subjectRetention),
        );

        return ['modes' => $modes, 'history_retention_days' => $retention];
    }
}
