<?php

declare(strict_types=1);

use App\Domains\Schedules\Models\Schedule;
use App\Domains\Schedules\Services\ScheduleEvaluator;
use Carbon\CarbonImmutable;

it('evaluates tolerance, overnight shifts, and dst transitions accurately', function (): void {
    $evaluator = new ScheduleEvaluator;

    $overnight = new Schedule([
        'timezone' => 'America/New_York',
        'work_days' => [6, 7], // Saturday & Sunday
        'start_time' => '22:00:00',
        'end_time' => '06:00:00',
        'late_tolerance_minutes' => 10,
    ]);

    // Saturday 2026-03-07 22:00 EST -> Sunday 2026-03-08 06:00 EDT (spring forward at 02:00)
    $window = $evaluator->buildShiftWindow($overnight, '2026-03-07');
    expect($window)->not->toBeNull();
    expect((int) $window['start_utc']->diffInMinutes($window['end_utc']))->toBe(420); // 7 hours due to DST

    // Clocking in Sunday at 00:15 local (after midnight) resolves back to Saturday's overnight shift
    $afterMidnightUtc = CarbonImmutable::create(2026, 3, 8, 0, 15, 0, 'America/New_York')->utc();
    $resolved = $evaluator->resolveShiftWindowForClockIn($overnight, $afterMidnightUtc);
    expect($resolved['work_date'])->toBe('2026-03-07');
    expect($evaluator->calculateLateMinutes($overnight, $afterMidnightUtc, '2026-03-07'))->toBe(135);

    // Within 10-minute tolerance -> 0 late minutes
    $withinTolerance = CarbonImmutable::create(2026, 3, 7, 22, 10, 0, 'America/New_York')->utc();
    expect($evaluator->calculateLateMinutes($overnight, $withinTolerance, '2026-03-07'))->toBe(0);

    // Early departure calculation
    $earlyOut = CarbonImmutable::create(2026, 3, 8, 5, 30, 0, 'America/New_York')->utc();
    expect($evaluator->calculateEarlyDepartureMinutes($overnight, $earlyOut, '2026-03-07'))->toBe(30);
});
