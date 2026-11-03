@extends('admin.layouts.app')
@section('title', 'Subscriptions &middot; Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/subscriptions.css') }}">
@endpush
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