<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function data(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)
            ->header('Cache-Control', 'no-store');
    }
}
