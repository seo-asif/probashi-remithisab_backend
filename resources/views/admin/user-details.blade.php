@extends('admin.layouts.app')
@section('title', $profile->name.' Â· Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/user-details.css') }}">
@endpush
@section('content')
<div id="profile-page">
<div class="top"><div><div class="eyebrow">Users / Account profile</div><h1>{{ $profile->name }}</h1><div class="sub">Member #{{ $profile->member_no ?? $profile->id }} &middot; Read-only details</div></div><a class="back" href="{{ route('admin.users') }}">&#8592; All users</a></div>

<div class="grid">
<section class="card"><h2>Account details</h2><div class="fields"><div><div class="label">Full name</div><div class="value">{{ $profile->name }}</div></div><div><div class="label">Email address</div><div class="value">{{ $profile->email }}</div></div><div><div class="label">Member number</div><div class="value">#{{ $profile->member_no ?? 'N/A' }}</div></div><div><div class="label">Account status</div><span class="pill {{ $profile->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($profile->status->value) }}</span></div><div><div class="label">Email verification</div><div class="value">{{ $profile->email_verified_at ? 'Verified' : 'Not verified' }}</div></div><div><div class="label">Registered</div><div class="value">{{ $profile->created_at?->format('d M Y, H:i') ?? 'Unknown' }}</div></div></div></section>
<section class="card"><h2>Sender Plus</h2>@if($senderSubscription)<div class="fields fields-single"><div><div class="label">Tier</div><div class="value">{{ ucfirst((string) $senderSubscription->tier) }}</div></div><div><div class="label">Access source</div><div class="value">{{ $senderSubscription->access_source ?? 'Not specified' }}</div></div><div><div class="label">Starts</div><div class="value">{{ $senderSubscription->access_starts_at?->format('d M Y') ?? 'Not set' }}</div></div><div><div class="label">Expires</div><div class="value">{{ $senderSubscription->access_ends_at?->format('d M Y') ?? 'Not set' }}</div></div></div>@else<div class="empty">No Sender Plus subscription record.</div>@endif</section>
<section class="card span"><h2>Business memberships</h2>@forelse($profile->memberships as $membership)<div class="member"><div><strong>{{ $membership->business?->name ?? 'Business unavailable' }}</strong><span class="sub">Role: {{ ucfirst(str_replace('_', ' ', $membership->role->value)) }}</span></div><span class="pill {{ $membership->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($membership->status->value) }}</span></div>@empty<div class="empty">No business memberships found.</div>@endforelse</section>
</div>
</div>
@endsection
