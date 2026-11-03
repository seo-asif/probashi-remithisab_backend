@extends('admin.layouts.app')
@section('title', 'Activity Logs · Probashi Admin')
@section('page_styles')
.activity-head{margin:8px 0 26px}.activity-head h1{font-size:clamp(28px,3vw,38px);letter-spacing:-1.1px;margin:8px 0}.activity-kicker{font-size:11px;letter-spacing:1.6px;text-transform:uppercase;font-weight:750;color:#348476}.activity-sub{color:#708680;font-size:13px;line-height:1.6}.activity-panel{background:#fff;border:1px solid #e0e9e4;border-radius:17px;overflow:hidden}.activity-panel-head{padding:22px 24px;display:flex;gap:15px;justify-content:space-between;align-items:center;border-bottom:1px solid #edf1ef}.activity-panel-head h2{margin:0 0 4px;font-size:16px}.activity-muted{color:#738780;font-size:12px}.activity-filters{display:flex;flex-wrap:wrap;gap:10px;padding:18px 24px;background:#fbfcfb}.activity-filters input,.activity-filters select{min-height:42px;border:1px solid #d4e1da;border-radius:9px;padding:9px 12px;background:#fff;color:#244b43;font:inherit;font-size:13px}.activity-filters input[type=search]{flex:1;min-width:180px}.activity-filters input[type=date]{max-width:158px}.activity-filters button{border:0;border-radius:9px;padding:10px 17px;background:#18584d;color:#fff;font:inherit;font-size:13px;cursor:pointer}.activity-reset{padding:12px;font-size:12px;color:#176453;text-decoration:underline}.activity-scroll{overflow:auto}.activity-table{width:100%;border-collapse:collapse;text-align:left}.activity-table th{background:#f7faf8;color:#80968e;letter-spacing:.8px;text-transform:uppercase;font-size:10px;white-space:nowrap}.activity-table th,.activity-table td{padding:15px 18px;border-top:1px solid #edf1ef}.activity-table td{font-size:12px;vertical-align:top}.activity-table td:nth-child(2){min-width:180px}.activity-table .action{font-weight:700;color:#175b50;overflow-wrap:anywhere}.activity-table .actor{font-weight:700}.activity-table .second{color:#779087;font-size:11px;margin-top:4px;overflow-wrap:anywhere}.activity-table a{color:#176453;text-decoration:underline;text-underline-offset:3px}.activity-empty{text-align:center;color:#7a8e87;padding:42px 18px!important}.activity-foot{padding:18px 23px;display:flex;gap:14px;justify-content:space-between;flex-wrap:wrap;color:#738780;font-size:12px}.activity-pages{display:flex;gap:12px;align-items:center}.activity-pages a{font-weight:700;color:#176453}.activity-filters :is(input,select,button,a):focus-visible,.activity-table a:focus-visible{outline:3px solid #93d4c8;outline-offset:2px}@media(max-width:650px){.activity-filters{padding:15px}.activity-table th,.activity-table td{padding:12px 13px}}
@endsection
@section('content')
<div class="activity-head"><div class="activity-kicker">Platform operations</div><h1>Activity Logs</h1><p class="activity-sub">Read-only history of recorded system actions. Sensitive metadata and technical request details are not displayed.</p></div>
<section class="activity-panel" aria-label="Activity history">
    <div class="activity-panel-head"><div><h2>Audit history</h2><div class="activity-muted">Latest activity first</div></div><span class="activity-muted">{{ number_format($logs->total()) }} matching</span></div>
    <form class="activity-filters" method="GET" action="{{ route('admin.activity-logs') }}">
        <input type="search" name="q" aria-label="Search activity" maxlength="100" placeholder="Action, actor, or numeric ID" value="{{ $search }}">
        <select name="action" aria-label="Filter action"><option value="">All actions</option>@foreach($actions as $item)<option value="{{ $item }}" @selected($action === $item)>{{ $item }}</option>@endforeach</select>
        <input type="date" name="from" aria-label="From date" value="{{ $from }}">
        <input type="date" name="to" aria-label="To date" value="{{ $to }}">
        <button type="submit">Apply filters</button><a class="activity-reset" href="{{ route('admin.activity-logs') }}">Clear</a>
    </form>
    <div class="activity-scroll"><table class="activity-table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>Business</th></tr></thead><tbody>
    @forelse($logs as $log)
        <tr>
            <td><span title="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->format('d M Y, H:i') ?? 'Unknown' }}</span></td>
            <td><div class="actor">@if($log->actor)<a href="{{ route('admin.users.show', $log->actor->id) }}">{{ $log->actor->name }}</a>@else System / deleted user @endif</div>@if($log->actor)<div class="second">{{ $log->actor->email }}</div>@endif</td>
            <td><span class="action">{{ $log->action }}</span></td>
            <td>@if($log->target_type && $log->target_id)@if($log->target_type === 'PlanPaymentRequest')<a href="{{ route('admin.payments.show', $log->target_id) }}">Payment #{{ $log->target_id }}</a>@elseif($log->target_type === 'User')<a href="{{ route('admin.users.show', $log->target_id) }}">User #{{ $log->target_id }}</a>@elseif($log->target_type === 'Business')<a href="{{ route('admin.businesses.show', $log->target_id) }}">Business #{{ $log->target_id }}</a>@else {{ $log->target_type }} #{{ $log->target_id }} @endif @else — @endif</td>
            <td>{{ $log->business_id ? '#'.$log->business_id : '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="activity-empty">No activity matches your filters.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="activity-foot"><span>{{ $logs->total() ? $logs->firstItem().'-'.$logs->lastItem().' of '.$logs->total() : '0 results' }}</span><div class="activity-pages">@if(!$logs->onFirstPage())<a rel="prev" href="{{ $logs->previousPageUrl() }}">Previous</a>@endif<span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>@if($logs->hasMorePages())<a rel="next" href="{{ $logs->nextPageUrl() }}">Next</a>@endif</div></div>
</section>
@endsection
