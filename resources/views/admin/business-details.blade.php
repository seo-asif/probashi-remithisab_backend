@extends('admin.layouts.app')
@section('title', 'Business profile &middot; Probashi Admin')
@section('page_styles')
.bd-head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:18px 0 30px}.bd-head h1{font-size:clamp(27px,3vw,36px);letter-spacing:-1px;margin:7px 0}.bd-muted{font-size:12px;color:#768c83}.bd-back{font-size:13px;font-weight:700;color:#217260}
.bd-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(270px,.8fr);gap:18px}.bd-panel{background:white;border:1px solid #e2eae5;border-radius:16px;padding:26px;min-width:0}.bd-panel h2{font-size:16px;margin:0 0 22px}.bd-wide{grid-column:1/-1}
.bd-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:23px}.bd-field label{display:block;font-size:11px;color:#7f908a;margin-bottom:7px}.bd-field strong{font-size:13px;overflow-wrap:anywhere}.bd-pill{border-radius:20px;padding:6px 10px;font-size:11px;background:#e9f5ed;color:#1c6c4f;font-weight:700}.bd-pill.off{background:#f2eeea;color:#886855}.bd-empty{padding:18px;background:#f6f8f7;border-radius:10px;color:#74867e;font-size:13px}.bd-person{padding:16px 0;border-top:1px solid #ebefec;display:flex;justify-content:space-between;gap:10px;font-size:13px}.bd-person:first-of-type{border-top:0}.bd-person a{font-weight:700;color:#205f53}.bd-person small{display:block;color:#74867e;margin-top:5px}
@media(max-width:920px){.bd-grid{grid-template-columns:1fr}.bd-wide{grid-column:auto}}@media(max-width:560px){.bd-fields{grid-template-columns:1fr}.bd-panel{padding:20px}.bd-head{align-items:flex-start}}
@endsection
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
@if($subscription)<div class="bd-fields" style="grid-template-columns:1fr">
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