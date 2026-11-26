<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Schedules\Models\Schedule;
use App\Domains\Schedules\Services\ScheduleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AssignScheduleRequest;
use App\Http\Requests\V1\StoreScheduleRequest;
use App\Http\Requests\V1\UpdateScheduleRequest;
use App\Http\Resources\V1\ScheduleResource;
use App\Http\Resources\V1\ShiftAssignmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleService $scheduleService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Schedule::class);

        $query = Schedule::query()->orderBy('name');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        $schedules = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return ScheduleResource::collection($schedules);
    }

    public function store(StoreScheduleRequest $request): JsonResponse
    {
        Gate::authorize('create', Schedule::class);

        $schedule = $this->scheduleService->create($request->validated(), $request->user());

        return (new ScheduleResource($schedule))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Schedule $schedule): ScheduleResource
    {
        Gate::authorize('view', $schedule);

        return new ScheduleResource($schedule);
    }

    public function update(UpdateScheduleRequest $request, Schedule $schedule): ScheduleResource
    {
        Gate::authorize('update', $schedule);

        $updated = $this->scheduleService->update($schedule, $request->validated(), $request->user());

        return new ScheduleResource($updated);
    }

    public function destroy(Request $request, Schedule $schedule): JsonResponse
    {
        Gate::authorize('delete', $schedule);

        $this->scheduleService->delete($schedule, $request->user());

        return response()->json(['message' => 'Schedule deleted.']);
    }

    public function assignToEmployee(AssignScheduleRequest $request, Schedule $schedule): JsonResponse
    {
        Gate::authorize('update', $schedule);

        $validated = $request->validated();
        $assignment = $this->scheduleService->assignToEmployee(
            $schedule,
            (int) $validated['employee_id'],
            $validated['effective_from'],
            $validated['effective_until'] ?? null,
            $request->user(),
        );

        return (new ShiftAssignmentResource($assignment))
            ->response()
            ->setStatusCode(201);
    }
}
