<?php

declare(strict_types=1);

namespace App\Filament\Support;

final class FeatureLabels
{
    private const LABELS = [
        'members.max' => 'Максимум участников',
        'groups.max' => 'Максимум групп',
        'devices.max' => 'Максимум устройств',
        'history.retention_days' => 'Глубина истории, дней',
        'geofences.max' => 'Максимум геозон',
        'live.enabled' => 'Live-сессии',
        'live.minutes_per_period' => 'Минут live в период',
        'temporary_shares.max_active' => 'Максимум временных ссылок',
        'location.enabled' => 'Передача геопозиции',
        'sos.enabled' => 'SOS',
        'location.idle.min_interval_seconds' => 'Интервал idle, сек',
        'location.normal.min_interval_seconds' => 'Интервал normal, сек',
        'location.live.min_interval_seconds' => 'Интервал live, сек',
        'location.sport.min_interval_seconds' => 'Интервал sport, сек',
        'location.sos.min_interval_seconds' => 'Интервал sos, сек',
    ];

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? preg_replace('/[._]+/', ' ', $key);
    }
}
