@extends('admin.layouts.app')
@section('title', 'Overview &middot; Probashi Admin')
@section('page_styles')

        .brand strong{display:block;font-size:15px;letter-spacing:-.4px}.brand small{display:block;font-size:11px;color:#abc8c1;margin-top:3px}
        .nav-item.active{background:#28615a;color:#fff}.nav-item.inactive{color:#a5c0ba}.nav-item svg{width:17px;height:17px;flex-shrink:0}
        .nav-item em{font-style:normal;font-size:10px;margin-left:auto;color:#83aaa3}
        .eyebrow{color:#3b897a;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;font-weight:780}
        h1{font-size:clamp(29px,3vw,39px);letter-spacing:-1.6px;line-height:1.1;margin:11px 0}h2{letter-spacing:-.5px}
        .intro{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:32px}.intro p{margin:8px 0 0;color:#7c8d87;font-size:13px}
        .live-label{display:inline-flex;gap:8px;align-items:center;border:1px solid #d4e6df;background:#edf7f2;border-radius:99px;color:#386d60;padding:9px 13px;font-size:11px;font-weight:720;white-space:nowrap}
        .live-label:before{content:"";width:7px;height:7px;background:#4caa88;border-radius:50%}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:28px}
        .stat{border:1px solid #e5ebe7;background:#fff;border-radius:16px;padding:21px;min-height:155px;display:flex;flex-direction:column;justify-content:space-between}
        .stat-head{display:flex;align-items:center;justify-content:space-between;color:#71827b;font-size:12px;font-weight:630}
        .stat-icon{display:grid;place-items:center;width:33px;height:33px;border-radius:10px;background:#edf5f1;color:#357766}
        .stat-icon svg{width:17px;height:17px}.stat-number{font-size:34px;line-height:1.1;letter-spacing:-1.5px;font-weight:740;font-variant-numeric:tabular-nums;color:#193d36}
        .stat-note{font-size:11px;color:#91a09b;margin-top:8px}
        .columns{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(265px,1fr);gap:18px}
        .panel{background:#fff;border:1px solid #e5ebe7;border-radius:17px;overflow:hidden;min-width:0}
        .panel-heading{padding:22px 24px 19px;display:flex;justify-content:space-between;align-items:start;gap:10px}
        .panel-heading h2{font-size:16px;margin:0;color:#203d37}.panel-heading p{font-size:12px;margin:6px 0 0;color:#8c9c96}
        .mini-label{padding:6px 9px;border-radius:6px;background:#f0f5f2;color:#5a8075;font-size:10px;font-weight:700}
        .table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap;text-align:left}th{font-size:10px;letter-spacing:.7px;color:#8da099;text-transform:uppercase;background:#fafbfa;font-weight:750}th,td{padding:14px 24px;border-top:1px solid #eef1ee}td{font-size:12px;color:#506660}td strong{color:#214940;font-weight:750}
        .status{display:inline-flex;padding:5px 9px;border-radius:6px;background:#f1f3f1;color:#66766e;font-size:10px;font-weight:720;text-transform:capitalize}.status.pending{background:#fff6e5;color:#9c692b}.status.approved{background:#eaf6ef;color:#2c7a55}.status.rejected{background:#fceeee;color:#a65252}
        .empty{padding:42px 24px;text-align:center;color:#8b9b94;font-size:12px}
        .side-body{padding:2px 23px 24px}.side-body h3{font-size:13px;margin:10px 0 6px;color:#264e45}.side-body p{font-size:12px;line-height:1.7;color:#80948b;margin:0}
        .divider{height:1px;background:#edf0ed;margin:22px 0}.inline-metric{display:flex;align-items:center;justify-content:space-between}.inline-metric strong{font-size:24px;color:#214c42;letter-spacing:-1px}
        .notice{margin-top:18px;padding:15px 18px;border:1px solid #dfece6;border-radius:12px;background:#f5faf7;color:#638076;font-size:12px;line-height:1.6}

@media(max-width:1130px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.columns{grid-template-columns:1fr}}
@media(max-width:600px){.stats{gap:10px}.stat{padding:16px;min-height:135px}.stat-number{font-size:29px}.intro{align-items:flex-start;flex-wrap:wrap}.panel-heading{padding:18px}th,td{padding:12px 15px}}
@endsection
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
                    <div class="panel-heading"><div><h2 id="payments-title">Payment queue</h2><p>Latest payment requests, from the existing backend</p></div><span class="mini-label">Recent 6</span></div>
                    <div class="table-wrap"><table><thead><tr><th>Request</th><th>Status</th><th>Submitted</th></tr></thead>
                        <tbody>
                        @forelse ($recentPayments as $payment)
                            <tr><td><strong>#{{ $payment->id }}</strong></td><td><span class="status {{ in_array($payment->status, ['pending','approved','rejected'], true) ? $payment->status : '' }}">{{ str_replace('_', ' ', $payment->status) }}</span></td><td>{{ \Illuminate\Support\Carbon::parse($payment->created_at)->format('d M Y \u{00B7} H:i') }}</td></tr>
                        @empty
                            <tr><td colspan="3"><div class="empty">No payment requests have been recorded yet.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                </section>
                <section class="panel" aria-labelledby="attention-title">
                    <div class="panel-heading"><div><h2 id="attention-title">Needs your attention</h2><p>Pending review workload</p></div></div>
                    <div class="side-body"><div class="inline-metric"><div><h3>Pending payments</h3><p>Requests awaiting review</p></div><strong>{{ number_format($metrics['pendingPayments']) }}</strong></div>
                        <div class="divider"></div><h3>Review workflow</h3><p>Detailed payment review, approvals, and rejection actions will be available in the Payments workspace. Existing approval rules remain authoritative.</p>
                        <div class="notice">Security is active. This overview is read-only; no payment or account changes can be made here.</div>
                    </div>
                </section>
            </div>

</div>
@endsection
