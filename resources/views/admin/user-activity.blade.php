@extends('admin.layouts.app')
@section('title', 'User Activity · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/user-activity.css') }}">
@endpush
@section('content')
<div class="ua-page">
  <header class="ua-header"><div><span class="ua-kicker">PLATFORM INSIGHTS</span><h1>User activity</h1><p>Recorded activity from existing features. No new tracking or private transaction details.</p></div><a class="ua-back" href="{{ route('admin.users') }}">User directory</a></header>
  <div class="ua-note">These rankings use recorded sender remittances and currently saved Daily Message likes. A remittance here means a record saved in the app, not a money transfer. Likes may disappear when unliked. Login time indicates the last known login, not ongoing app usage.</div>
  <form class="ua-filter" action="{{ route('admin.user-activity') }}" method="GET">
    <label>Reporting period<select name="period"><option value="7" @selected($period === '7')>Last 7 days</option><option value="30" @selected($period === '30')>Last 30 days</option><option value="90" @selected($period === '90')>Last 90 days</option><option value="all" @selected($period === 'all')>All time</option></select></label>
    <label>Rank by<select name="sort"><option value="activity" @selected($sort === 'activity')>Recorded actions</option><option value="remittances" @selected($sort === 'remittances')>Remittances recorded</option><option value="likes" @selected($sort === 'likes')>Message likes</option><option value="login" @selected($sort === 'login')>Last login</option></select></label>
    <label class="ua-search">Find user<input name="q" maxlength="100" value="{{ $search }}" placeholder="Name or member number"></label>
    <button type="submit">Apply</button>
  </form>
  <section class="ua-metrics" aria-label="Activity overview">
    <div><span>Users with recorded actions</span><strong>{{ number_format($totals['users_with_actions']) }}</strong></div>
    <div><span>Remittances recorded</span><strong>{{ number_format($totals['recorded_remittances']) }}</strong></div>
    <div><span>Saved message likes</span><strong>{{ number_format($totals['current_likes']) }}</strong></div>
    <div><span>Accounts with recent login</span><strong>{{ number_format($totals['recent_logins']) }}</strong></div>
  </section>
  <section class="ua-panel"><header><div><h2>Activity by user</h2><p>Select a user to open their protected profile. Counts reflect the chosen period; last login is the latest recorded time overall.</p></div><span>{{ number_format($users->total()) }} accounts</span></header>
  <div class="ua-table-wrap"><table><thead><tr><th>User</th><th>Recorded actions</th><th>Remittance records</th><th>Saved likes</th><th>Last login</th></tr></thead><tbody>
  @forelse($users as $user)
    <tr><td><a class="ua-name" href="{{ route('admin.users.show', $user->id) }}">{{ $user->name }}</a><small>Member #{{ $user->member_no ?? $user->id }}</small></td><td><strong>{{ number_format($user->recorded_actions) }}</strong></td><td>{{ number_format($user->remittance_count) }}</td><td>{{ number_format($user->like_count) }}</td><td>{{ $user->last_login_at ? \Illuminate\Support\Carbon::parse($user->last_login_at)->utc()->format('d M Y, H:i').' UTC' : 'No recorded login' }}</td></tr>
  @empty<tr><td colspan="5" class="ua-empty">No users match these filters.</td></tr>@endforelse
  </tbody></table></div>
  <footer><span>{{ $users->total() ? $users->firstItem().'-'.$users->lastItem().' of '.$users->total() : 'No results' }}</span><div>@if($users->previousPageUrl())<a href="{{ $users->previousPageUrl() }}">Previous</a>@endif <span>Page {{ $users->currentPage() }} / {{ $users->lastPage() }}</span> @if($users->nextPageUrl())<a href="{{ $users->nextPageUrl() }}">Next</a>@endif</div></footer>
  </section>
  <p class="ua-footnote">Not measured yet: app session frequency, screen views, time spent in the app, and whether a message was displayed. These require separate, carefully limited event tracking.</p>
</div>
@endsection
