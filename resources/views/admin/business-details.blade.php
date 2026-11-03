@extends('admin.layouts.app')
@section('title', 'Business profile &middot; Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/business-details.css') }}">
@endpush
@section('content')
<div class="bd-head"><div><div class="bd-muted">Businesses / Profile</div><h1>{{ $business->name }}</h1><p class="bd-muted">Read-only business information</p></div><a class="bd-back" href="{{ route('admin.businesses') }}">&#8592; All businesses</a></div>
<div class="bd-grid">
<section class="bd-panel"><h2>Business details</h2><div class="bd-fields">
<div class="bd-field"><label>Business ID</label><strong>#{{ $business->id }}</strong></div>
<div class="bd-field"><label>Status</label><span class="bd-pill {{ $business->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($business->status->value) }}</span></div>
<div class="bd-field"><label>Country code</label><strong>{{ $business->country_code ?? 'Not set' }}</strong></div>
<div class="bd-field"><label>Default currency</label><strong>{{ $business->default_currency ?? 'Not set' }}</strong></div>
<div class="bd-field"><label>Timezone</label><strong>{{ $business->timezone ?? 'Not set' }}</strong></div>
<div class="bd-field"><label>Created</label><strong>{{ $business->created_at?->format('d M Y') ?? 'Unknown' }}</strong></div>
</div></section>
<section class="bd-panel"><h2>Manager Pro</h2>
@if($subscription)<div class="bd-fields bd-fields-single">
<div class="bd-field"><label>Tier</label><strong>{{ ucfirst((string) $subscription->tier) }}</strong></div>
<div class="bd-field"><label>Access source</label><strong>{{ $subscription->access_source ?? 'Not set' }}</strong></div>
<div class="bd-field"><label>Starts</label><strong>{{ $subscription->access_starts_at?->format('d M Y') ?? 'Not set' }}</strong></div>
<div class="bd-field"><label>Expires</label><strong>{{ $subscription->access_ends_at?->format('d M Y') ?? 'Not set' }}</strong></div>
</div>@else<div class="bd-empty">No Manager Pro subscription record.</div>@endif
</section>
<section class="bd-panel bd-wide"><h2>Members ({{ $business->memberships->count() }})</h2>
@forelse($business->memberships as $member)
<div class="bd-person"><div><a href="{{ route('admin.users.show', $member->user_id) }}">{{ $member->user?->name ?? 'User unavailable' }}</a><small>{{ ucfirst(str_replace('_',' ', $member->role->value)) }} &middot; {{ $member->user?->email ?? '' }}</small></div><span class="bd-pill {{ $member->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($member->status->value) }}</span></div>
@empty<div class="bd-empty">No business members found.</div>@endforelse
</section></div>
@endsection