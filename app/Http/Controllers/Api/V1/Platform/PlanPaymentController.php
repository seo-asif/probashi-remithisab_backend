<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Plan\PlatformPaymentRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use App\Support\Api\Paging;
use App\Support\Plans\PaymentApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

final class PlanPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->platformAdmin($request);

        $query = PlanPaymentRequest::query()
            ->with([
                'business',
                'submittedBy',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = Paging::paginate(
            $query,
            $request,
        );

        return ApiResponse::success(
            Paging::shape(
                $page,
                fn(PlanPaymentRequest $payment) =>
                PlatformPaymentRequestResource::make($payment)
                    ->resolve($request),
            ),
        );
    }

    public function approve(
        Request $request,
        string $id,
        PaymentApprovalService $approvals,
    ): JsonResponse {
        $admin = $this->platformAdmin($request);
        $payment = $this->findPayment($id);

        $payment = $approvals->approve(
            $payment,
            $admin,
        );

        $payment->loadMissing([
            'business',
            'submittedBy',
        ]);

        return ApiResponse::success(
            PlatformPaymentRequestResource::make($payment)
                ->resolve($request),
            'Payment approved and Unlimited access activated.',
        );
    }

    public function reject(
        Request $request,
        string $id,
        PaymentApprovalService $approvals,
    ): JsonResponse {
        $admin = $this->platformAdmin($request);

        $data = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $payment = $this->findPayment($id);

        $payment = $approvals->reject(
            $payment,
            $admin,
            $data['reason'],
        );

        $payment->loadMissing([
            'business',
            'submittedBy',
        ]);

        return ApiResponse::success(
            PlatformPaymentRequestResource::make($payment)
                ->resolve($request),
            'Payment request rejected.',
        );
    }

    private function findPayment(
        string $id,
    ): PlanPaymentRequest {
        /*
         * The API accepts only the public UUID.
         * Never expose or accept the numeric database ID here.
         */
        return PlanPaymentRequest::query()
            ->where('uuid', $id)
            ->firstOrFail();
    }

    private function platformAdmin(
        Request $request,
    ): PlatformAdmin {
        $admin = $request->attributes->get(
            'platform_admin',
        );

        if (!$admin instanceof PlatformAdmin) {
            /*
             * Reaching this point without the middleware-provided
             * administrator would indicate a route configuration bug.
             */
            throw new LogicException(
                'Platform administrator context is missing.'
            );
        }

        return $admin;
    }
}
