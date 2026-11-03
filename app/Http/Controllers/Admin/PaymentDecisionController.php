<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlanPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use App\Support\Plans\PaymentApprovalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;

final class PaymentDecisionController extends Controller
{
    public function show(Request $request, PlanPaymentRequest $payment): View
    {
        $payment->loadMissing(['business:id,name', 'submittedBy:id,name,email']);

        return view('admin.payment-decision', ['payment' => $payment]);
    }

    public function submit(
        Request $request,
        PlanPaymentRequest $payment,
        PaymentApprovalService $approvals,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:255'],
            'confirm_payment_id' => ['required', 'integer', Rule::in([(int) $payment->id])],
        ]);

        $admin = $request->attributes->get('platform_admin');
        if (!$admin instanceof PlatformAdmin) {
            throw new LogicException('Platform administrator context is missing.');
        }

        // Refuse all already-decided payments in the browser. The authoritative
        // service checks state again under database locks before mutation.
        $payment->refresh();
        if ($payment->status !== PlanPaymentStatus::Pending) {
            return redirect()->route('admin.payments.decision', ['payment' => $payment->id])
                ->with('payment_notice', 'This request has already been decided. No changes were made.');
        }

        if ($data['decision'] === 'approve') {
            $approvals->approve($payment, $admin);
        } else {
            $approvals->reject($payment, $admin, $data['reason']);
        }

        return redirect()->route('admin.payments.decision', ['payment' => $payment->id])
            ->with('payment_notice', $data['decision'] === 'approve'
                ? 'Payment approved using the existing entitlement service.'
                : 'Payment request rejected.');
    }
}