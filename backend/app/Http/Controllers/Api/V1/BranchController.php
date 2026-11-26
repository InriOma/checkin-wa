<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Branches\Models\Branch;
use App\Domains\Branches\Services\BranchService;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreBranchRequest;
use App\Http\Requests\V1\UpdateBranchRequest;
use App\Http\Resources\V1\BranchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $branchService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Branch::class);

        $user = $request->user();
        $tenantId = $this->tenantContext->requireId();
        $query = Branch::query()->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->string('search')).'%';
            $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($term, $operator): void {
                $q->where('name', $operator, $term)
                    ->orWhere('code', $operator, $term);
            });
        }

        $membership = $user->membershipFor($tenantId);
        if (
            ! $user->is_platform_admin
            && $membership?->role === TenantRole::BranchManager
            && ! empty($membership->branch_scope_ids)
        ) {
            $query->whereIn('id', array_map('intval', $membership->branch_scope_ids));
        }

        $branches = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        Gate::authorize('create', Branch::class);

        $branch = $this->branchService->create($request->validated(), $request->user());

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        Gate::authorize('view', $branch);

        return new BranchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        Gate::authorize('update', $branch);

        $updated = $this->branchService->update($branch, $request->validated(), $request->user());

        return new BranchResource($updated);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('delete', $branch);

        $this->branchService->delete($branch, $request->user());

        return response()->json(['message' => 'Branch deleted.']);
    }
}
