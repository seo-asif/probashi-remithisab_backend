@extends('admin.layouts.app')

@section('title', 'Payment review &middot; Probashi Admin')

@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/payment-decision.css') }}">
@endpush

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
            <hr class="payment-decision-divider">
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