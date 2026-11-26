<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Reports\Services\AttendanceReportService;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AttendanceReportService $reportService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AttendanceRecord::class);

        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 404);

        $tz = $tenant->timezone ?: 'UTC';
        $today = now()->setTimezone($tz)->toDateString();

        $validated = $request->validate([
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date'],
            'branch_id' => ['sometimes', 'integer'],
        ]);

        $fromDate = $validated['from_date'] ?? $today;
        $toDate = $validated['to_date'] ?? $today;
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;

        $allowedBranchIds = $this->resolveBranchScope($request, $tenant->id);
        if ($branchId !== null && $allowedBranchIds !== null && ! in_array($branchId, $allowedBranchIds, true)) {
            abort(403, 'Branch outside manager scope.');
        }

        $summary = $this->reportService->summary(
            $tenant->id,
            $fromDate,
            $toDate,
            $branchId,
            $allowedBranchIds,
        );

        return response()->json(['data' => $summary]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', AttendanceRecord::class);

        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 404);

        $tz = $tenant->timezone ?: 'UTC';
        $today = now()->setTimezone($tz)->toDateString();

        $validated = $request->validate([
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date'],
            'branch_id' => ['sometimes', 'integer'],
        ]);

        $fromDate = $validated['from_date'] ?? $today;
        $toDate = $validated['to_date'] ?? $today;
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;

        $allowedBranchIds = $this->resolveBranchScope($request, $tenant->id);
        if ($branchId !== null && $allowedBranchIds !== null && ! in_array($branchId, $allowedBranchIds, true)) {
            abort(403, 'Branch outside manager scope.');
        }

        $filename = "attendance-{$tenant->code}-{$fromDate}-{$toDate}.csv";

        return response()->streamDownload(function () use ($tenant, $fromDate, $toDate, $branchId, $allowedBranchIds): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($this->reportService->exportRows($tenant->id, $fromDate, $toDate, $branchId, $allowedBranchIds) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return list<int>|null */
    private function resolveBranchScope(Request $request, int $tenantId): ?array
    {
        $user = $request->user();
        $membership = $user->membershipFor($tenantId);

        if (
            ! $user->is_platform_admin
            && $membership?->role === TenantRole::BranchManager
            && ! empty($membership->branch_scope_ids)
        ) {
            return array_map('intval', $membership->branch_scope_ids);
        }

        return null;
    }
}
