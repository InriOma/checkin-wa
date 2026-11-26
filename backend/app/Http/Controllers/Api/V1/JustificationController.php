<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Models\Justification;
use App\Domains\Incidents\Services\IncidentReviewService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ReviewIncidentRequest;
use App\Http\Requests\V1\StoreJustificationRequest;
use App\Http\Resources\V1\JustificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class JustificationController extends Controller
{
    public function __construct(
        private readonly IncidentReviewService $reviewService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Justification::class);

        $query = Justification::query()
            ->with('employee')
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        return JustificationResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 20), 100))
        );
    }

    public function store(StoreJustificationRequest $request): JsonResponse
    {
        Gate::authorize('create', Justification::class);

        $justification = $this->reviewService->createJustification($request->validated(), $request->user());

        return (new JustificationResource($justification))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(ReviewIncidentRequest $request, Justification $justification): JustificationResource
    {
        Gate::authorize('review', $justification);

        $updated = $this->reviewService->reviewJustification(
            $justification,
            IncidentStatus::Approved,
            $request->user(),
            $request->validated('justification_note'),
        );

        return new JustificationResource($updated);
    }

    public function reject(ReviewIncidentRequest $request, Justification $justification): JustificationResource
    {
        Gate::authorize('review', $justification);

        $updated = $this->reviewService->reviewJustification(
            $justification,
            IncidentStatus::Rejected,
            $request->user(),
            $request->validated('justification_note'),
        );

        return new JustificationResource($updated);
    }
}
