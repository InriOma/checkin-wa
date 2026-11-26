<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Models\Incident;
use Generator;

final class AttendanceReportService
{
    /**
     * @param  list<int>|null  $allowedBranchIds
     * @return array<string, mixed>
     */
    public function summary(
        int $tenantId,
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?array $allowedBranchIds = null,
    ): array {
        $recordsQuery = AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('work_date', '>=', $fromDate)
            ->whereDate('work_date', '<=', $toDate);

        $incidentsQuery = Incident::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('incident_date', '>=', $fromDate)
            ->whereDate('incident_date', '<=', $toDate);

        if ($branchId !== null) {
            $recordsQuery->where('branch_id', $branchId);
            $incidentsQuery->where('branch_id', $branchId);
        } elseif ($allowedBranchIds !== null) {
            $recordsQuery->whereIn('branch_id', $allowedBranchIds);
            $incidentsQuery->whereIn('branch_id', $allowedBranchIds);
        }

        $totalRecords = (clone $recordsQuery)->count();
        $openShifts = (clone $recordsQuery)->whereIn('status', [AttendanceStatus::Working->value, AttendanceStatus::OnBreak->value])->count();
        $closedShifts = (clone $recordsQuery)->where('status', AttendanceStatus::Closed->value)->count();
        $lateCount = (clone $recordsQuery)->where('late_minutes', '>', 0)->count();
        $totalLateMinutes = (int) (clone $recordsQuery)->sum('late_minutes');
        $totalWorkedMinutes = (int) (clone $recordsQuery)->sum('total_worked_minutes');

        $incidentsByType = (clone $incidentsQuery)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $pendingIncidents = (clone $incidentsQuery)
            ->where('status', IncidentStatus::Pending->value)
            ->count();

        return [
            'period' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ],
            'attendance' => [
                'total_records' => $totalRecords,
                'open_shifts' => $openShifts,
                'closed_shifts' => $closedShifts,
                'on_time_count' => max(0, $totalRecords - $lateCount),
                'late_count' => $lateCount,
                'total_late_minutes' => $totalLateMinutes,
                'total_worked_minutes' => $totalWorkedMinutes,
            ],
            'incidents' => [
                'pending_count' => $pendingIncidents,
                'by_type' => $incidentsByType,
            ],
        ];
    }

    /**
     * @param  list<int>|null  $allowedBranchIds
     * @return Generator<int, list<string|int|null>>
     */
    public function exportRows(
        int $tenantId,
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?array $allowedBranchIds = null,
    ): Generator {
        yield [
            'work_date',
            'employee_code',
            'employee_name',
            'branch_code',
            'branch_name',
            'status',
            'clock_in_utc',
            'clock_out_utc',
            'worked_minutes',
            'break_minutes',
            'late_minutes',
            'early_departure_minutes',
        ];

        $query = AttendanceRecord::query()
            ->with(['employee', 'branch'])
            ->where('tenant_id', $tenantId)
            ->whereDate('work_date', '>=', $fromDate)
            ->whereDate('work_date', '<=', $toDate)
            ->orderBy('work_date')
            ->orderBy('clock_in_at');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } elseif ($allowedBranchIds !== null) {
            $query->whereIn('branch_id', $allowedBranchIds);
        }

        foreach ($query->cursor() as $record) {
            yield [
                $record->work_date?->toDateString(),
                $this->sanitizeCsvCell($record->employee?->employee_code ?? ''),
                $this->sanitizeCsvCell($record->employee?->full_name ?? ''),
                $this->sanitizeCsvCell($record->branch?->code ?? ''),
                $this->sanitizeCsvCell($record->branch?->name ?? ''),
                $record->status->value,
                $record->clock_in_at?->toIso8601String(),
                $record->clock_out_at?->toIso8601String(),
                (int) ($record->total_worked_minutes ?? 0),
                (int) $record->total_break_minutes,
                (int) $record->late_minutes,
                (int) $record->early_departure_minutes,
            ];
        }
    }

    private function sanitizeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
