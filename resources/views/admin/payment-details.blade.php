@extends('admin.layouts.app')
@section('title', 'Payment review &middot; Probashi Admin')
@section('page_styles')
    .pd-head{display:flex;justify-content:space-between;gap:18px;align-items:center;margin:14px 0 30px}.pd-head
    h1{font-size:clamp(28px,3vw,36px);margin:5px
    0;letter-spacing:-1px}.pd-back{font-size:13px;font-weight:700;color:#166355}.pd-grid{display:grid;grid-template-columns:minmax(0,1.5fr)
    minmax(250px,.8fr);gap:18px}.pd-card{border:1px solid #e0e9e4;background:white;border-radius:16px;padding:25px}.pd-card
    h2{margin:0 0
    24px;font-size:16px}.pd-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}.pd-label{color:#779188;font-size:11px;margin-bottom:7px}.pd-value{font-size:13px;font-weight:650;overflow-wrap:anywhere}.pd-muted{font-size:12px;color:#799086;line-height:1.7}.pd-card
    a{color:#166557;text-decoration:underline;text-underline-offset:3px}.pd-note{background:#f4f8f5;border-radius:10px;padding:15px;margin-top:20px}@media(max-width:850px){.pd-grid{grid-template-columns:1fr}}@media(max-width:480px){.pd-fields{grid-template-columns:1fr}.pd-card{padding:20px}}.pd-review-btn
    {
    display: block;
    margin-top: 20px;
    padding: 14px 18px;
    background: #166355;
    color: #fff !important;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    text-align: center;
    text-decoration: none !important;
    transition: background .2s ease;
    }

    .pd-review-btn:hover {
    background: #104b40;
    }

    .pd-review-btn:focus-visible {
    outline: 3px solid #81b8a9;
    outline-offset: 3px;
    }
@endsection
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
