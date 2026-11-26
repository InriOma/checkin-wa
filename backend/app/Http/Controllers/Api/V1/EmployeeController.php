<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Employees\Models\Employee;
use App\Domains\Employees\Services\EmployeeService;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AssignEmployeeBranchesRequest;
use App\Http\Requests\V1\StoreEmployeeRequest;
use App\Http\Requests\V1\UpdateEmployeeRequest;
use App\Http\Resources\V1\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employeeService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $user = $request->user();
        $tenantId = $this->tenantContext->requireId();
        $query = Employee::query()
            ->with(['defaultBranch', 'branchAssignments.branch'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->integer('branch_id');
            $query->where(function ($q) use ($branchId): void {
                $q->where('default_branch_id', $branchId)
                    ->orWhereHas('branchAssignments', fn ($ba) => $ba->where('branch_id', $branchId));
            });
        }

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->string('search')).'%';
            $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($term, $operator): void {
                $q->where('first_name', $operator, $term)
                    ->orWhere('last_name', $operator, $term)
                    ->orWhere('employee_code', $operator, $term);
            });
        }

        $membership = $user->membershipFor($tenantId);
        if (
            ! $user->is_platform_admin
            && $membership?->role === TenantRole::BranchManager
            && ! empty($membership->branch_scope_ids)
        ) {
            $allowedBranchIds = array_map('intval', $membership->branch_scope_ids);
            $query->where(function ($q) use ($allowedBranchIds): void {
                $q->whereIn('default_branch_id', $allowedBranchIds)
                    ->orWhereHas('branchAssignments', fn ($ba) => $ba->whereIn('branch_id', $allowedBranchIds));
            });
        }

        $employees = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        Gate::authorize('create', Employee::class);

        $employee = $this->employeeService->create($request->validated(), $request->user());

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee->load(['defaultBranch', 'branchAssignments.branch']));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        Gate::authorize('update', $employee);

        $updated = $this->employeeService->update($employee, $request->validated(), $request->user());

        return new EmployeeResource($updated);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('delete', $employee);

        $this->employeeService->delete($employee, $request->user());

        return response()->json(['message' => 'Employee deleted.']);
    }

    public function assignBranches(AssignEmployeeBranchesRequest $request, Employee $employee): EmployeeResource
    {
        Gate::authorize('update', $employee);

        $validated = $request->validated();
        $updated = $this->employeeService->syncBranchAssignments(
            $employee,
            $validated['branch_ids'],
            $validated['effective_from'],
            $validated['effective_until'] ?? null,
            $request->user(),
        );

        return new EmployeeResource($updated->load('defaultBranch'));
    }
}
