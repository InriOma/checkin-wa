<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Attendance\Services\AttendanceCorrectionService;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CorrectAttendanceRequest;
use App\Http\Resources\V1\AttendanceRecordResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AttendanceCorrectionService $correctionService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AttendanceRecord::class);

        $user = $request->user();
        $tenantId = $this->tenantContext->requireId();

        $query = AttendanceRecord::query()
            ->with(['employee', 'branch', 'breaks'])
            ->orderByDesc('work_date')
            ->orderByDesc('clock_in_at');

        if ($request->filled('work_date')) {
            $query->whereDate('work_date', (string) $request->string('work_date'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('work_date', '>=', (string) $request->string('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('work_date', '<=', (string) $request->string('to_date'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->integer('branch_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        $membership = $user->membershipFor($tenantId);
        if (
            ! $user->is_platform_admin
            && $membership?->role === TenantRole::BranchManager
            && ! empty($membership->branch_scope_ids)
        ) {
            $query->whereIn('branch_id', array_map('intval', $membership->branch_scope_ids));
        }

        return AttendanceRecordResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 20), 100))
        );
    }

    public function show(AttendanceRecord $attendance): AttendanceRecordResource
    {
        Gate::authorize('view', $attendance);

        return new AttendanceRecordResource($attendance->load(['employee', 'branch', 'events', 'breaks']));
    }

    public function correct(CorrectAttendanceRequest $request, AttendanceRecord $attendance): AttendanceRecordResource
    {
        Gate::authorize('correct', $attendance);

        $updated = $this->correctionService->correct($attendance, $request->validated(), $request->user());

        return new AttendanceRecordResource($updated);
    }
}
