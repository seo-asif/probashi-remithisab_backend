@extends('admin.layouts.app')
@section('title', $profile->name.' Â· Probashi Admin')
@section('page_styles')

#profile-page .top{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:30px}
#profile-page .eyebrow{font-size:11px;letter-spacing:1.6px;color:#65847d;font-weight:750;text-transform:uppercase}
#profile-page h1{font-size:clamp(27px,3vw,36px);letter-spacing:-1px;margin:7px 0}
#profile-page .sub{color:#778b84;font-size:13px}
#profile-page .back{font-size:13px;color:#207366;font-weight:700;white-space:nowrap}
#profile-page .grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.8fr);gap:18px}
#profile-page .card{background:#fff;border:1px solid #e3eae6;border-radius:17px;padding:26px;box-shadow:0 4px 20px #173c3210;min-width:0}
#profile-page h2{font-size:16px;margin:0 0 24px}
#profile-page .fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}
#profile-page .label{color:#81938d;font-size:11px;margin-bottom:6px}
#profile-page .value{font-size:14px;font-weight:650;overflow-wrap:anywhere}
#profile-page .pill{display:inline-block;background:#e7f4ed;color:#17694f;font-size:11px;font-weight:700;padding:6px 10px;border-radius:50px}
#profile-page .pill.off{background:#f1f3f2;color:#667a74}
#profile-page .span{grid-column:1/-1}
#profile-page .member{display:flex;justify-content:space-between;gap:15px;border-top:1px solid #ecf0ed;padding:16px 0;font-size:13px}
#profile-page .member:first-of-type{border-top:0}
#profile-page .member strong{display:block;margin-bottom:5px}
#profile-page .empty{background:#f6f8f7;padding:17px;border-radius:10px;color:#778b84;font-size:13px}
@media(max-width:950px){#profile-page .grid{grid-template-columns:1fr}}
@media(max-width:500px){#profile-page .fields{grid-template-columns:1fr}#profile-page .card{padding:20px}#profile-page .top{align-items:flex-start;flex-wrap:wrap}}

@endsection
@section('content')
<div id="profile-page">
<div class="top"><div><div class="eyebrow">Users / Account profile</div><h1>{{ $profile->name }}</h1><div class="sub">Member #{{ $profile->member_no ?? $profile->id }} &middot; Read-only details</div></div><a class="back" href="{{ route('admin.users') }}">&#8592; All users</a></div>

<div class="grid">
<section class="card"><h2>Account details</h2><div class="fields"><div><div class="label">Full name</div><div class="value">{{ $profile->name }}</div></div><div><div class="label">Email address</div><div class="value">{{ $profile->email }}</div></div><div><div class="label">Member number</div><div class="value">#{{ $profile->member_no ?? 'N/A' }}</div></div><div><div class="label">Account status</div><span class="pill {{ $profile->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($profile->status->value) }}</span></div><div><div class="label">Email verification</div><div class="value">{{ $profile->email_verified_at ? 'Verified' : 'Not verified' }}</div></div><div><div class="label">Registered</div><div class="value">{{ $profile->created_at?->format('d M Y, H:i') ?? 'Unknown' }}</div></div></div></section>
<section class="card"><h2>Sender Plus</h2>@if($senderSubscription)<div class="fields" style="grid-template-columns:1fr"><div><div class="label">Tier</div><div class="value">{{ ucfirst((string) $senderSubscription->tier) }}</div></div><div><div class="label">Access source</div><div class="value">{{ $senderSubscription->access_source ?? 'Not specified' }}</div></div><div><div class="label">Starts</div><div class="value">{{ $senderSubscription->access_starts_at?->format('d M Y') ?? 'Not set' }}</div></div><div><div class="label">Expires</div><div class="value">{{ $senderSubscription->access_ends_at?->format('d M Y') ?? 'Not set' }}</div></div></div>@else<div class="empty">No Sender Plus subscription record.</div>@endif</section>
<section class="card span"><h2>Business memberships</h2>@forelse($profile->memberships as $membership)<div class="member"><div><strong>{{ $membership->business?->name ?? 'Business unavailable' }}</strong><span class="sub">Role: {{ ucfirst(str_replace('_', ' ', $membership->role->value)) }}</span></div><span class="pill {{ $membership->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($membership->status->value) }}</span></div>@empty<div class="empty">No business memberships found.</div>@endforelse</section>
</div>
</div>
@endsection
