<?php

namespace App\Http\Controllers\Api\V1\Plan;

use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Plan\PaymentRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\PlanPaymentRequest;
use App\Support\Api\Paging;
use App\Support\Plans\PaymentOptionsService;
use App\Support\Plans\PaymentRequestService;
use App\Support\Plans\PlanPackages;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;

final class PaymentController extends Controller
{
    public function index(
        Request $request,
        CurrentBusiness $current,
    ): JsonResponse {
        $business = $current->get();
        $membership = $current->membership();

        if ($business === null || $membership === null) {
            throw new LogicException(
                'Payment requests require an active business context.'
            );
        }

        $this->assertCanManagePayments($membership->role);

        $query = PlanPaymentRequest::query()
            ->where('business_id', $business->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = Paging::paginate($query, $request);

        return ApiResponse::success(
            Paging::shape(
                $page,
                fn(PlanPaymentRequest $payment) =>
                PaymentRequestResource::make($payment)
                    ->resolve($request),
            ),
        );
    }

    public function options(
        CurrentBusiness $current,
        PaymentOptionsService $options,
    ): JsonResponse {
        $business = $current->get();
        $membership = $current->membership();

        if ($business === null || $membership === null) {
            throw new LogicException(
                'Payment options require an active business context.'
            );
        }

        $this->assertCanManagePayments($membership->role);

        return ApiResponse::success(
            $options->forBusiness($business),
        );

    }

    public function store(
        Request $request,
        CurrentBusiness $current,
        PaymentRequestService $payments,
    ): JsonResponse {
        $business = $current->get();
        $membership = $current->membership();
        $user = $request->user();

        if (
            $business === null
            || $membership === null
            || $user === null
        ) {
            throw new LogicException(
                'Payment submission requires an active business context.'
            );
        }

        $this->assertCanManagePayments($membership->role);

        $data = $request->validate([
            'package_months' => [
                'required',
                'integer',
                Rule::in(PlanPackages::months()),
            ],
            'trx_id'         => [
                'required',
                'string',
                'max:64',
            ],
            'payer_number'   => [
                'required',
                'string',
                'max:32',
            ],
        ]);

        $payment = $payments->createUnlimited(
            $business,
            $user,
            (int) $data['package_months'],
            $data['trx_id'],
            $data['payer_number'],
        );

        return ApiResponse::success(
            PaymentRequestResource::make($payment)
                ->resolve($request),
            'Payment details received for checking.',
            201,
        );
    }

    private function assertCanManagePayments(
        BusinessRole $role,
    ): void {
        if (!in_array(
            $role,
            [
                BusinessRole::Owner,
                BusinessRole::Administrator,
            ],
            true,
        )) {
            throw new ApiException(
                'PLAN_PAYMENT_FORBIDDEN',
                'Only the business owner or administrator can manage plan payments.',
                403,
            );
        }
    }
}
