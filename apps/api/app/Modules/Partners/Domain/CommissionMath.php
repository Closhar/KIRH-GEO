<?php

declare(strict_types=1);

namespace App\Modules\Partners\Domain;

final class CommissionMath
{
    public static function calculate(int $paidMinor, ?int $rateBps, ?int $fixedMinor): int
    {
        if ($paidMinor < 0 || ($rateBps === null) === ($fixedMinor === null) || ($rateBps !== null && ($rateBps < 0 || $rateBps > 10000)) || ($fixedMinor !== null && $fixedMinor < 0)) {
            throw new \InvalidArgumentException('Invalid commission rules.');
        }

        return min($paidMinor, $rateBps === null ? $fixedMinor : intdiv($paidMinor * $rateBps, 10000));
    }

    /** Cumulative rounding ensures partial refunds sum to the exact full reversal. */
    public static function reversal(int $commission, int $paidMinor, int $refundedMinor, int $alreadyReversed): int
    {
        if ($paidMinor < 1 || $refundedMinor < 0 || $refundedMinor > $paidMinor || $commission < 0 || $alreadyReversed < 0) {
            throw new \InvalidArgumentException('Invalid refund amounts.');
        }

        return max(0, intdiv($commission * $refundedMinor, $paidMinor) - $alreadyReversed);
    }
}
