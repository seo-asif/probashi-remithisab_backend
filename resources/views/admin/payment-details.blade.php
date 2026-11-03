@extends('admin.layouts.app')
@section('title', 'Payment review &middot; Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/payment-details.css') }}">
@endpush
@section('content')
    <div class="pd-head">
        <div>
            <div class="pd-label">Financial operations / Payment review</div>
            <h1>Request #{{ $payment->id }}</h1>
            <div class="pd-muted">Read-only review &middot; {{ $payment->created_at?->format('d M Y, H:i') }}</div>
        </div><a class="pd-back" href="{{ route('admin.payments') }}">&#8592; All payments</a>
    </div>
    <div class="pd-grid">
        <section class="pd-card">
            <h2>Request information</h2>
            <div class="pd-fields">
                <div>
                    <div class="pd-label">Status</div>
                    <div class="pd-value">{{ ucfirst($payment->status->value) }}</div>
                </div>
                <div>
                    <div class="pd-label">Product</div>
                    <div class="pd-value">{{ ucwords(str_replace('_', ' ', $payment->product_type->value)) }}</div>
                </div>
                <div>
                    <div class="pd-label">Duration</div>
                    <div class="pd-value">{{ $payment->package_months ?? 'Not set' }} months</div>
                </div>
                <div>
                    <div class="pd-label">Expected amount</div>
                    <div class="pd-value">BDT {{ number_format($payment->expected_amount_bdt) }}</div>
                </div>
                <div>
                    <div class="pd-label">Submitted by</div>
                    <div class="pd-value">
                        @if ($payment->submittedBy)
                            <a
                                href="{{ route('admin.users.show', $payment->submittedBy) }}">{{ $payment->submittedBy->name }}</a>
                        @else
                            Unknown
                        @endif
                    </div>
                </div>
                <div>
                    <div class="pd-label">Beneficiary</div>
                    <div class="pd-value">
                        @if ($payment->beneficiary)
                            <a
                                href="{{ route('admin.users.show', $payment->beneficiary) }}">{{ $payment->beneficiary->name }}</a>
                        @else
                            Not specified
                        @endif
                    </div>
                </div>
                <div>
                    <div class="pd-label">Business</div>
                    <div class="pd-value">
                        @if ($payment->business)
                            <a
                                href="{{ route('admin.businesses.show', $payment->business) }}">{{ $payment->business->name }}</a>
                        @else
                            Not specified
                        @endif
                    </div>
                </div>
                <div>
                    <div class="pd-label">Decision date</div>
                    <div class="pd-value">{{ $payment->decided_at?->format('d M Y, H:i') ?? 'Not decided' }}</div>
                </div>
            </div>
        </section>

        <aside class="pd-card">
            <h2>Payment decision</h2>

            @if ($payment->status === \App\Enums\PlanPaymentStatus::Pending)
                <p class="pd-muted">
                    Review the payment information and verify the
                    payment evidence before approving or rejecting.
                </p>

                <a href="{{ route('admin.payments.decision', ['payment' => $payment->id]) }}" class="pd-review-btn">
                    Review Payment &rarr;
                </a>

                <div class="pd-note pd-muted">
                    Approval activates the purchased subscription.
                    Rejection requires a reason. All decisions are
                    recorded in the administrator audit log.
                </div>
            @else
                <div class="pd-note">
                    <div class="pd-label">Decision completed</div>
                    <div class="pd-value">
                        {{ ucfirst($payment->status->value) }}
                    </div>

                    @if ($payment->decision_reason)
                        <p class="pd-muted">
                            {{ $payment->decision_reason }}
                        </p>
                    @endif
                </div>

                <p class="pd-muted">
                    This payment request has already been decided.
                </p>
            @endif
        </aside>

    </div>
@endsection
