<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbOk = false;
        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }

        $redisOk = false;
        try {
            Redis::connection()->ping();
            $redisOk = true;
        } catch (\Throwable) {
            $redisOk = false;
        }

        $healthy = $dbOk;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'checkin-wa-api',
            'checks' => [
                'database' => $dbOk ? 'up' : 'down',
                'redis' => $redisOk ? 'up' : 'down',
            ],
            'timestamp_utc' => now()->utc()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
