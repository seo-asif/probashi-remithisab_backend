<?php

namespace App\Http\Controllers\Api\V1\Sender;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sender\SavingsGoalResource;
use App\Http\Responses\ApiResponse;
use App\Models\SavingsGoal;
use App\Services\Sender\SenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /savings-goals: name, target_bdt, target_date (optional), with saved_bdt / left_bdt / reached.
 * POST may send include_past_self: true to count money already sent to Myself that is not in a goal.
 * Deleting a goal unlinks its remittances (they stay). PRIVATE. Phase 4: Free plan keeps 1 goal.
 */
class GoalController extends Controller
{
    public function __construct(private SenderService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $goals = SavingsGoal::query()->orderBy('id')->get();

        return ApiResponse::success(['items' => $goals->map(fn ($g) => $this->present($request, $g))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        [$goal, $created] = $this->service->saveGoal(null, $request->only(['id', 'name', 'target_bdt', 'target_date', 'include_past_self']));

        return ApiResponse::success($this->present($request, $goal), $created ? 'Goal saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$goal] = $this->service->saveGoal(SavingsGoal::findByUuidOrFail($id), $request->only(['name', 'target_bdt', 'target_date']));

        return ApiResponse::success($this->present($request, $goal), 'Goal saved.');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->deleteGoal(SavingsGoal::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Goal deleted.');
    }

    private function present(Request $request, SavingsGoal $goal): array
    {
        return (new SavingsGoalResource($goal, $this->service->goalProgress($goal)))->resolve($request);
    }
}
