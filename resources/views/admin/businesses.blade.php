@extends('admin.layouts.app')
@section('title', 'Businesses &middot; Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/businesses.css') }}">
@endpush
@section('content')
<header class="biz-header"><div class="biz-muted">Management / Businesses</div><h1 class="biz-title">Businesses</h1><p class="biz-muted">Browse registered businesses and their memberships. This directory is read-only.</p></header>
<section class="biz-card" aria-label="Business directory">
<div class="biz-toolbar"><h2>Business directory</h2><span class="biz-muted">{{ number_format($businesses->total()) }} matches</span></div>
<form class="biz-filters" method="GET" action="{{ route('admin.businesses') }}">
<input type="search" name="q" maxlength="100" aria-label="Search business names" value="{{ $search }}" placeholder="Search business name">
<select name="status" aria-label="Filter business status"><option value="all" @selected($status==='all')>All statuses</option><option value="active" @selected($status==='active')>Active</option><option value="suspended" @selected($status==='suspended')>Suspended</option></select>
<button type="submit">Apply filters</button>
</form>
<div class="biz-table-wrap"><table class="biz-table"><thead><tr><th>Business</th><th>Members</th><th>Status</th><th>Created</th></tr></thead><tbody>
@forelse($businesses as $business)
<tr><td><a class="biz-name" href="{{ route('admin.businesses.show', $business) }}">{{ $business->name }}</a><div class="biz-sub">Business #{{ $business->id }}</div></td><td>{{ $business->memberships_count }}</td><td><span class="biz-pill {{ $business->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($business->status->value) }}</span></td><td>{{ $business->created_at?->format('d M Y') ?? 'Unknown' }}</td></tr>
@empty
<tr><td colspan="4" class="biz-empty">No businesses match your filters.</td></tr>
@endforelse
</tbody></table></div>
<div class="biz-footer"><span>{{ $businesses->total() ? $businesses->firstItem().'-'.$businesses->lastItem().' of '.$businesses->total() : '0 results' }}</span><div>@if(!$businesses->onFirstPage())<a class="biz-page" href="{{ $businesses->previousPageUrl() }}">Previous</a>@endif <span>Page {{ $businesses->currentPage() }} of {{ $businesses->lastPage() }}</span> @if($businesses->hasMorePages())<a class="biz-page" href="{{ $businesses->nextPageUrl() }}">Next</a>@endif</div></div>
</section>
@endsection