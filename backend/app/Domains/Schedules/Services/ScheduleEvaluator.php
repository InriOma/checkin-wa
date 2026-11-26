<?php

declare(strict_types=1);

namespace App\Domains\Schedules\Services;

use App\Domains\Schedules\Models\Schedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ScheduleEvaluator
{
    /**
     * Builds the UTC start and end instants for a given local work date in the schedule's IANA timezone.
     *
     * @return array{work_date: string, start_utc: CarbonImmutable, end_utc: CarbonImmutable}|null
     */
    public function buildShiftWindow(Schedule $schedule, string $workDate): ?array
    {
        $tz = $schedule->timezone ?: 'UTC';
        $date = CarbonImmutable::createFromFormat('Y-m-d', $workDate, $tz);
        if ($date === false) {
            return null;
        }

        $workDays = array_map('intval', $schedule->work_days ?? []);
        if (! in_array($date->dayOfWeekIso, $workDays, true)) {
            return null;
        }

        $startTime = $this->normalizeTime($schedule->start_time);
        $endTime = $this->normalizeTime($schedule->end_time);

        $startLocal = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$workDate} {$startTime}", $tz);
        $endDate = $schedule->isOvernight() ? $date->addDay()->toDateString() : $workDate;
        $endLocal = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$endDate} {$endTime}", $tz);

        if ($startLocal === false || $endLocal === false) {
            return null;
        }

        return [
            'work_date' => $workDate,
            'start_utc' => $startLocal->utc(),
            'end_utc' => $endLocal->utc(),
        ];
    }

    /**
     * Resolves the applicable shift window for a clock-in instant (supporting overnight shifts).
     *
     * @return array{work_date: string, start_utc: CarbonImmutable, end_utc: CarbonImmutable}|null
     */
    public function resolveShiftWindowForClockIn(Schedule $schedule, CarbonInterface $clockInUtc): ?array
    {
        $tz = $schedule->timezone ?: 'UTC';
        $local = CarbonImmutable::instance($clockInUtc)->setTimezone($tz);

        if ($schedule->isOvernight()) {
            $yesterday = $local->subDay()->toDateString();
            $prevWindow = $this->buildShiftWindow($schedule, $yesterday);
            if ($prevWindow !== null && $clockInUtc <= $prevWindow['end_utc']->addHours(2)) {
                return $prevWindow;
            }
        }

        return $this->buildShiftWindow($schedule, $local->toDateString());
    }

    public function calculateLateMinutes(Schedule $schedule, CarbonInterface $clockInUtc, string $workDate): int
    {
        $window = $this->buildShiftWindow($schedule, $workDate);
        if ($window === null) {
            return 0;
        }

        $clockIn = CarbonImmutable::instance($clockInUtc)->utc();
        if ($clockIn->lessThanOrEqualTo($window['start_utc'])) {
            return 0;
        }

        $minutesLate = (int) $window['start_utc']->diffInMinutes($clockIn);
        if ($minutesLate <= (int) $schedule->late_tolerance_minutes) {
            return 0;
        }

        return $minutesLate;
    }

    public function calculateEarlyDepartureMinutes(Schedule $schedule, CarbonInterface $clockOutUtc, string $workDate): int
    {
        $window = $this->buildShiftWindow($schedule, $workDate);
        if ($window === null) {
            return 0;
        }

        $clockOut = CarbonImmutable::instance($clockOutUtc)->utc();
        if ($clockOut->greaterThanOrEqualTo($window['end_utc'])) {
            return 0;
        }

        return (int) $clockOut->diffInMinutes($window['end_utc']);
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }
}
