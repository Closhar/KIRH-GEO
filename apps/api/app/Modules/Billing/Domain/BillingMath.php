<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use Carbon\CarbonImmutable;

final class BillingMath
{
    public static function periodEnd(CarbonImmutable $start, string $interval): CarbonImmutable
    {
        return match ($interval) {
            'month' => $start->addMonthNoOverflow(),
            'year' => $start->addYearNoOverflow(),
            default => throw new \InvalidArgumentException('Unsupported billing interval.'),
        };
    }

    public static function discountedAmount(int $amount, ?int $basisPoints, ?int $fixedMinor): int
    {
        if ($amount < 0 || ($basisPoints === null) === ($fixedMinor === null)
            || ($basisPoints !== null && ($basisPoints < 1 || $basisPoints > 10000))
            || ($fixedMinor !== null && $fixedMinor < 1)) {
            throw new \InvalidArgumentException('Invalid discount.');
        }
        $discount = $basisPoints === null ? $fixedMinor : intdiv($amount * $basisPoints, 10000);

        return max(0, $amount - $discount);
    }
}
