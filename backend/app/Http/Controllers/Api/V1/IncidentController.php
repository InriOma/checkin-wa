<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Services\IncidentReviewService;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ReviewIncidentRequest;
use App\Http\Resources\V1\IncidentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class IncidentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly IncidentReviewService $reviewService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Incident::class);

        $user = $request->user();
        $tenantId = $this->tenantContext->requireId();

        $query = Incident::query()
            ->with(['employee', 'branch'])
            ->orderByDesc('incident_date')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->string('type'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->integer('branch_id'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('incident_date', '>=', (string) $request->string('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('incident_date', '<=', (string) $request->string('to_date'));
        }

        $membership = $user->membershipFor($tenantId);
        if (
            ! $user->is_platform_admin
            && $membership?->role === TenantRole::BranchManager
            && ! empty($membership->branch_scope_ids)
        ) {
            $query->whereIn('branch_id', array_map('intval', $membership->branch_scope_ids));
        }

        return IncidentResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 20), 100))
        );
    }

    public function show(Incident $incident): IncidentResource
    {
        Gate::authorize('view', $incident);

        return new IncidentResource($incident->load(['employee', 'branch']));
    }

    public function approve(ReviewIncidentRequest $request, Incident $incident): IncidentResource
    {
        Gate::authorize('review', $incident);

        $updated = $this->reviewService->reviewIncident(
            $incident,
            IncidentStatus::Approved,
            $request->user(),
            $request->validated('justification_note'),
        );

        return new IncidentResource($updated);
    }

    public function reject(ReviewIncidentRequest $request, Incident $incident): IncidentResource
    {
        Gate::authorize('review', $incident);

        $request->validate([
            'justification_note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $updated = $this->reviewService->reviewIncident(
            $incident,
            IncidentStatus::Rejected,
            $request->user(),
            $request->validated('justification_note'),
        );

        return new IncidentResource($updated);
    }
}
