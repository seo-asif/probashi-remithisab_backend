<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanPaymentRequest;
use Illuminate\Contracts\View\View;

final class PaymentDetailsController extends Controller
{
    public function __invoke(PlanPaymentRequest $payment): View
    {
        // Do not expose decrypted payer numbers or transaction IDs on this read-only page.
        $payment->load(['business:id,name', 'submittedBy:id,name,email', 'beneficiary:id,name,email']);
        return view('admin.payment-details', ['payment' => $payment]);
    }
}