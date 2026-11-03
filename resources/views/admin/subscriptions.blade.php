@extends('admin.layouts.app')
@section('title', 'Subscriptions &middot; Probashi Admin')
@section('page_styles')
.sub-top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:4px 0 28px}.sub-top h1{font-size:34px;letter-spacing:-1.2px;margin:7px 0}.sub-top p{color:#748a82;font-size:13px;margin:0}.eyebrow{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#3d8a7a;font-weight:800}
.sub-panel{background:white;border:1px solid #e0e8e3;border-radius:17px;overflow:hidden}.sub-tabs{display:flex;gap:4px;padding:18px 20px;border-bottom:1px solid #e9eeea}.sub-tabs a{padding:11px 16px;border-radius:9px;color:#55776f;font-size:13px;font-weight:650}.sub-tabs a.selected{background:#e7f2ed;color:#15584c}.sub-form{display:flex;gap:10px;flex-wrap:wrap;padding:20px;border-bottom:1px solid #e9eeea}.sub-form input,.sub-form select{border:1px solid #d7e4dd;border-radius:9px;background:white;padding:10px 12px;color:#163d37;min-height:42px;font:inherit;font-size:13px}.sub-form input{flex:1;min-width:185px}.sub-form button{border:0;background:#17574f;color:white;border-radius:9px;padding:0 18px;font:inherit;font-size:13px;font-weight:650;cursor:pointer}.sub-table{overflow-x:auto}.sub-table table{width:100%;border-collapse:collapse;text-align:left}.sub-table th{background:#fafbf9;color:#7e948a;font-size:10px;letter-spacing:.8px;text-transform:uppercase}.sub-table th,.sub-table td{padding:16px 20px;border-bottom:1px solid #edf1ee;white-space:nowrap}.sub-table td{font-size:12px}.sub-table td a{font-weight:700;color:#155d53}.sub-table td small{display:block;color:#8b9b94;font-size:11px;margin-top:5px}.pill{padding:6px 10px;background:#f1f3f1;color:#60776d;border-radius:30px;font-size:11px;font-weight:700}.pill.active{color:#16694b;background:#e6f4eb}.sub-foot{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding:18px 20px;color:#75887d;font-size:12px}.sub-foot a{border:1px solid #d7e4dd;border-radius:8px;padding:7px 11px;color:#275c50}.empty{padding:32px 20px;text-align:center;color:#82938b;font-size:13px}@media(max-width:600px){.sub-top h1{font-size:28px}.sub-tabs,.sub-form{padding:12px}.sub-table th,.sub-table td{padding:13px}.sub-form button{min-height:42px}}
@endsection
@section('content')
<div class="sub-top"><div><div class="eyebrow">Platform / Plans</div><h1>Subscriptions</h1><p>Review current entitlement records. Changes are managed by the existing payment workflow.</p></div></div>
<section class="sub-panel" aria-label="Subscription directory">
    <div class="sub-tabs" role="navigation" aria-label="Subscription type">
        <a href="{{ route('admin.subscriptions', ['type'=>'sender']) }}" class="{{ $type === 'sender' ? 'selected' : '' }}" @if($type === 'sender') aria-current="page" @endif>Sender Plus</a>
        <a href="{{ route('admin.subscriptions', ['type'=>'manager']) }}" class="{{ $type === 'manager' ? 'selected' : '' }}" @if($type === 'manager') aria-current="page" @endif>Manager Pro</a>
    </div>
    <form method="GET" action="{{ route('admin.subscriptions') }}" class="sub-form">
        <input type="hidden" name="type" value="{{ $type }}">
        <input type="search" aria-label="Search subscriptions" name="q" maxlength="100" value="{{ $search }}" placeholder="{{ $type === 'sender' ? 'Search name or email' : 'Search business name' }}">
        <select name="access" aria-label="Filter subscription access">
            <option value="all" @selected($access === 'all')>All records</option>
            <option value="active" @selected($access === 'active')>Active paid</option>
            <option value="expired" @selected($access === 'expired')>Expired paid</option>
            <option value="free" @selected($access === 'free')>Free</option>
        </select>
        <button type="submit">Apply filters</button>
    </form>
    <div class="sub-table"><table><thead><tr><th>{{ $type === 'sender' ? 'User' : 'Business' }}</th><th>Tier</th><th>Access</th><th>Source</th><th>Started</th><th>Expires</th></tr></thead><tbody>
        @forelse($subscriptions as $item)
            @php
                $isActive = $item->tier === $paidTier && $item->access_starts_at && $item->access_starts_at <= $currentTime && $item->access_ends_at && $item->access_ends_at > $currentTime;
                $label = $isActive ? 'Active paid' : ($item->tier === 'free' ? 'Free' : 'Inactive paid');
            @endphp
            <tr>
                <td>@if($type === 'sender')
                    @if($item->user)<a href="{{ route('admin.users.show', $item->user->id) }}">{{ $item->user->name }}</a><small>{{ $item->user->email }}</small>@else<span>Missing user</span>@endif
                @else
                    @if($item->business)<a href="{{ url('/admin/businesses/'.$item->business->id) }}">{{ $item->business->name }}</a>@else<span>Missing business</span>@endif
                @endif</td>
                <td>{{ ucfirst((string) $item->tier) }}</td><td><span class="pill {{ $isActive ? 'active' : '' }}">{{ $label }}</span></td>
                <td>{{ $item->access_source ?? 'â€”' }}</td><td>{{ $item->access_starts_at?->format('d M Y') ?? 'â€”' }}</td><td>{{ $item->access_ends_at?->format('d M Y') ?? 'â€”' }}</td>
            </tr>
        @empty<tr><td colspan="6"><div class="empty">No subscription records match these filters.</div></td></tr>@endforelse
    </tbody></table></div>
    <div class="sub-foot"><span>{{ $subscriptions->total() }} matching records</span><div>@if(!$subscriptions->onFirstPage())<a href="{{ $subscriptions->previousPageUrl() }}">Previous</a>@endif <span>Page {{ $subscriptions->currentPage() }} of {{ $subscriptions->lastPage() }}</span> @if($subscriptions->hasMorePages())<a href="{{ $subscriptions->nextPageUrl() }}">Next</a>@endif</div></div>
</section>
@endsection