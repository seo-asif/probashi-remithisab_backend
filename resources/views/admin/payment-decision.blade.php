@extends('admin.layouts.app')

@section('title', 'Payment review &middot; Probashi Admin')

@section('page_styles')
.review-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:24px;flex-wrap:wrap}
.review-head h1{font-size:clamp(25px,3vw,34px);letter-spacing:-1px;margin:7px 0}
.review-sub{font-size:13px;color:#788b84}
.review-back{font-size:13px;color:#206c5e;font-weight:700}
.review-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(280px,.8fr);gap:18px}
.review-card{background:#fff;border:1px solid #dfeae4;border-radius:16px;padding:25px;min-width:0}
.review-card h2{font-size:16px;margin:0 0 19px}
.review-row{display:flex;justify-content:space-between;gap:18px;border-top:1px solid #edf1ef;padding:13px 0;font-size:13px}
.review-row:first-of-type{border-top:0}
.review-row span{color:#708780}
.review-row strong{text-align:right;overflow-wrap:anywhere}
.review-field{margin-bottom:18px}
.review-field label{display:block;font-size:12px;color:#526e65;margin-bottom:8px}
.review-field textarea{width:100%;min-height:92px;resize:vertical;padding:12px;border:1px solid #cfded6;border-radius:9px;font:inherit;font-size:13px}
.review-action{width:100%;border:0;border-radius:9px;padding:13px 16px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.review-approve{background:#14564b;color:#fff}
.review-reject{background:#fff;border:1px solid #d19f9f;color:#8a3e3e}
.review-alert{padding:14px;border-radius:10px;background:#eaf6ef;color:#215f4c;margin-bottom:18px;font-size:13px}
.review-error{background:#fff0ec;color:#9b4038}
.review-note{font-size:12px;color:#73897f;line-height:1.6}
@media(max-width:920px){.review-grid{grid-template-columns:1fr}}@media(max-width:500px){.review-card{padding:19px}.review-row{flex-direction:column;gap:4px}.review-row strong{text-align:left}}
@endsection

@section('content')
<div class="review-head">
    <div><div class="review-sub">Payments / Decision review</div><h1>Payment #{{ $payment->id }}</h1><div class="review-sub">Review the stored request before taking action.</div></div>
    <a class="review-back" href="{{ route('admin.payments') }}">&#8592; All payments</a>
</div>
@if(session('payment_notice'))<div class="review-alert" role="status">{{ session('payment_notice') }}</div>@endif
@if($errors->any())<div class="review-alert review-error" role="alert">{{ $errors->first() }}</div>@endif
<div class="review-grid">
    <section class="review-card" aria-label="Payment information">
        <h2>Payment information</h2>
        <div class="review-row"><span>Status</span><strong>{{ ucfirst($payment->status->value) }}</strong></div>
        <div class="review-row"><span>Product</span><strong>{{ str_replace('_', ' ', ucfirst($payment->product_type->value)) }}</strong></div>
        <div class="review-row"><span>Expected amount</span><strong>BDT {{ number_format($payment->expected_amount_bdt ?? 0) }}</strong></div>
        <div class="review-row"><span>Package</span><strong>{{ $payment->package_months ?? 'N/A' }} month(s)</strong></div>
        <div class="review-row"><span>Submitted by</span><strong>{{ $payment->submittedBy?->name ?? 'Unavailable' }}</strong></div>
        <div class="review-row"><span>Business</span><strong>{{ $payment->business?->name ?? 'Unavailable' }}</strong></div>
        <div class="review-row"><span>Submitted</span><strong>{{ $payment->created_at?->format('d M Y, H:i') }}</strong></div>
        @if($payment->decided_at)<div class="review-row"><span>Decision date</span><strong>{{ $payment->decided_at->format('d M Y, H:i') }}</strong></div>@endif
        <p class="review-note">This page deliberately excludes transaction IDs and payer phone numbers. Verify payment evidence through an authorized channel before approval.</p>
    </section>
    <section class="review-card">
        <h2>Decision</h2>
        @if($payment->status === \App\Enums\PlanPaymentStatus::Pending)
            <p class="review-note">Approval grants access through the existing PaymentApprovalService. Decisions cannot be reversed from this page.</p>
            <form method="POST" action="{{ route('admin.payments.decision.submit', ['payment' => $payment->id]) }}" onsubmit="return confirm('Approve this payment and activate its entitlement?');">
                @csrf
                <input type="hidden" name="decision" value="approve">
                <input type="hidden" name="confirm_payment_id" value="{{ $payment->id }}">
                <button class="review-action review-approve" type="submit">Approve payment</button>
            </form>
            <hr style="border:0;border-top:1px solid #e8efea;margin:23px 0">
            <form method="POST" action="{{ route('admin.payments.decision.submit', ['payment' => $payment->id]) }}" onsubmit="return confirm('Reject this payment request?');">
                @csrf
                <input type="hidden" name="decision" value="reject">
                <input type="hidden" name="confirm_payment_id" value="{{ $payment->id }}">
                <div class="review-field"><label for="reason">Reason for rejection</label><textarea name="reason" id="reason" required maxlength="255" placeholder="Explain why this payment cannot be approved.">{{ old('reason') }}</textarea></div>
                <button class="review-action review-reject" type="submit">Reject payment</button>
            </form>
        @else
            <div class="review-alert">This payment has already been decided. Further changes are not available.</div>
        @endif
    </section>
</div>
@endsection