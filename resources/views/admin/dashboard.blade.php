@extends('admin.layouts.app')
@section('title', 'Overview &middot; Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/dashboard.css') }}">
@endpush
@section('content')
@php
$icons = [
'overview'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
'people'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6m1 4a5 5 0 0 1 3 5"/>',
'building'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-5h6v5M8 8h2m4 0h2M8 12h2m4 0h2"/>',
'payments'=>'<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
'plans'=>'<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 7h8M8 12h8M8 17h5"/>',
];
$icon = static fn (string $key) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icons[$key].'</svg>';
@endphp
<div id="overview-page">

            <div class="intro"><div><div class="eyebrow">Platform overview</div><h1>Platform overview</h1><p>Review platform activity and see what needs attention.</p></div><span class="live-label">Live database totals</span></div>
            <section class="stats" aria-label="Platform statistics">
                @foreach ([
                    ['Users','users','people','Registered accounts'],
                    ['Businesses','businesses','building','Business records'],
                    ['Sender Plus','senderPlus','plans','Tier records'],
                    ['Manager Pro','managerPro','plans','Tier records'],
                ] as $item)
                    <article class="stat"><div class="stat-head"><span>{{ $item[0] }}</span><span class="stat-icon">{!! $icon($item[2]) !!}</span></div>
                        <div><div class="stat-number">{{ number_format($metrics[$item[1]]) }}</div><div class="stat-note">{{ $item[3] }}</div></div></article>
                @endforeach
            </section>
            <div class="columns">
                <section class="panel" aria-labelledby="payments-title">
                    <div class="panel-heading"><div><h2 id="payments-title">Payment queue</h2><p>Latest requests · select a request to review</p></div><span class="mini-label">Recent 6</span></div>
                    <div class="table-wrap"><table><thead><tr><th>Request</th><th>Status</th><th>Submitted (Fiji time)</th></tr></thead>
                        <tbody>
                        @forelse ($recentPayments as $payment)
                            <tr><td><a href="{{ route('admin.payments.show', $payment->id) }}" class="dashboard-payment-link">#{{ $payment->id }}</a></td><td><span class="status {{ in_array($payment->status, ['pending','approved','rejected'], true) ? $payment->status : '' }}">{{ str_replace('_', ' ', $payment->status) }}</span></td><td>{{ \Illuminate\Support\Carbon::parse($payment->created_at)->timezone('Pacific/Fiji')->format('d M Y, H:i') }}</td></tr>
                        @empty
                            <tr><td colspan="3"><div class="empty">No payment requests have been recorded yet.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                </section>
                <section class="panel" aria-labelledby="attention-title">
                    <div class="panel-heading"><div><h2 id="attention-title">Needs your attention</h2><p>Pending review workload</p></div></div>
                    <div class="side-body"><div class="inline-metric"><div><h3>Pending payments</h3><p>Requests awaiting review</p></div><strong>{{ number_format($metrics['pendingPayments']) }}</strong></div>
                        <div class="divider"></div><h3>Review workflow</h3><p>Review pending requests and approve or reject them in the Payments workspace. Existing approval rules remain authoritative.</p>
                        <div class="notice">This overview is read-only. <a href="{{ route('admin.payments', ['status' => 'pending']) }}" class="dashboard-notice-link">Review pending payments</a> in the secure Payments workspace.</div>
                    </div>
                </section>
            </div>

</div>
@endsection
