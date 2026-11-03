@extends('admin.layouts.app')
@section('title', 'Businesses &middot; Probashi Admin')
@section('page_styles')
.biz-title{font-size:clamp(27px,3vw,35px);letter-spacing:-1px;margin:8px 0}.biz-muted{color:#708a81;font-size:13px}
.biz-header{margin:16px 0 27px}.biz-card{background:#fff;border:1px solid #e2ebe6;border-radius:16px;overflow:hidden}
.biz-toolbar{display:flex;align-items:center;justify-content:space-between;padding:23px;gap:15px;border-bottom:1px solid #edf1ee}
.biz-toolbar h2{font-size:16px;margin:0}.biz-filters{display:flex;flex-wrap:wrap;gap:10px;padding:17px 23px;border-bottom:1px solid #edf1ee}
.biz-filters input,.biz-filters select{border:1px solid #d6e4dd;border-radius:9px;padding:10px 12px;min-height:42px;background:#fff;color:#183c35;font:inherit;font-size:13px}
.biz-filters input{flex:1;min-width:190px}.biz-filters button{background:#155149;color:white;border:0;border-radius:9px;padding:0 17px;font:inherit;font-size:13px;cursor:pointer}
.biz-table-wrap{overflow:auto}.biz-table{border-collapse:collapse;width:100%;text-align:left;min-width:620px}
.biz-table th{background:#f9fbfa;color:#799087;font-size:10px;letter-spacing:1px;text-transform:uppercase;padding:15px 23px}
.biz-table td{border-top:1px solid #eaf0ec;padding:17px 23px;font-size:13px}.biz-table tbody tr:hover{background:#fcfdfc}
.biz-name{font-weight:700;color:#174c41}.biz-name:hover{text-decoration:underline}.biz-sub{font-size:12px;color:#789087;margin-top:4px}
.biz-pill{border-radius:100px;background:#e6f4ec;padding:6px 10px;font-size:11px;font-weight:700;color:#17694e}.biz-pill.off{background:#f5eae8;color:#954d43}
.biz-footer{display:flex;justify-content:space-between;align-items:center;gap:13px;flex-wrap:wrap;padding:18px 23px;border-top:1px solid #edf1ee;font-size:12px;color:#6f857d}
.biz-page{border:1px solid #dbe5df;border-radius:7px;padding:8px 12px;display:inline-block;margin-left:7px}.biz-empty{text-align:center;padding:38px;color:#758a82}
@media(max-width:600px){.biz-toolbar,.biz-filters,.biz-footer{padding:16px}.biz-title{font-size:28px}}
@endsection
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