<?php

declare(strict_types=1);

namespace App\Support;

final class CanonicalJson
{
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sort($item);
            }
        }

        return $value;
    }
}
