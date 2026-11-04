@extends('admin.layouts.app')
@section('title', 'Daily Messages Analytics · Probashi Admin')
@push('head')<link rel="stylesheet" href="{{ asset('css/admin/pages/dm-studio.css') }}"><link rel="stylesheet" href="{{ asset('css/admin/pages/dm-studio-analytics.css') }}">@endpush
@section('content')
<div class="dms dmsa">
  <header class="dms-head"><div><div class="dms-kicker">INSIGHTS &amp; AUDIENCE</div><h1>Message analytics</h1><p>See which messages get likes, and which users interact with them.</p></div><a class="dms-btn" href="{{ route('admin.daily-messages') }}">← Back to messages</a></header>
  <nav class="dms-tabs" aria-label="Daily Messages sections"><a href="{{ route('admin.daily-messages') }}">Message library</a><a class="active" aria-current="page" href="{{ route('admin.daily-messages.analytics') }}">Analytics &amp; users</a><a href="{{ route('admin.daily-messages.tools') }}">Import / Export</a></nav>
  <form class="dmsa-filters" method="GET" action="{{ route('admin.daily-messages.analytics') }}"><label>Reporting period<select name="period"><option value="7" @selected($period === '7')>Last 7 days</option><option value="30" @selected($period === '30')>Last 30 days</option><option value="90" @selected($period === '90')>Last 90 days</option><option value="all" @selected($period === 'all')>All time</option></select></label><label>Category<select name="category"><option value="all" @selected($category === 'all')>All categories</option>@foreach($categories as $code => $labels)<option value="{{ $code }}" @selected($category === $code)>{{ $labels['en'] }}</option>@endforeach</select></label><button class="dms-btn dms-primary" type="submit">Apply filters</button><a href="{{ route('admin.daily-messages.analytics') }}">Clear filters</a></form>
  <section class="dms-metrics" aria-label="Engagement overview"><div><span>Current likes</span><strong>{{ number_format($totalLikes) }}</strong><small>Saved likes during period</small></div><div><span>People who liked</span><strong>{{ number_format($uniqueLikers) }}</strong><small>Distinct user accounts</small></div><div><span>Messages liked</span><strong>{{ number_format($likedMessages) }}</strong><small>Distinct messages</small></div><div><span>Published messages</span><strong>{{ number_format($publishedMessages) }}</strong><small>Current catalogue</small></div></section>
  <nav class="dmsa-jump" aria-label="Analytics sections"><a href="#dm-categories">Categories</a><a href="#dm-top-messages">Messages</a><a href="#dm-users">Users</a></nav>
  <div class="dmsa-layout">
    <section class="dms-panel" id="dm-categories"><div class="dms-panelhead"><div><h2>Category performance</h2><p>Compare how many current likes each topic received.</p></div><span class="dmsa-mini">{{ count($categoryStats) }} categories</span></div>
    <div class="dmsa-category-list">
      @forelse($categoryStats as $stat)
        @php($maxCategoryLikes = max(1, (int) $categoryStats->max('likes_count')))
        <a class="dmsa-category" href="{{ route('admin.daily-messages.analytics', ['period' => $period, 'category' => $stat->category ?? 'all']) }}#dm-top-messages" @if($category === ($stat->category ?? 'all')) aria-current="true" @endif>
          <div class="dmsa-cat-top"><strong>{{ $categories[$stat->category]['en'] ?? 'Uncategorized' }}</strong><span>{{ number_format($stat->likes_count) }} likes</span></div>
          <div class="dmsa-bar"><span style="width:{{ min(100, (int) round((int)$stat->likes_count * 100 / $maxCategoryLikes)) }}%"></span></div>
          <div class="dmsa-cat-sub">{{ number_format($stat->messages_count) }} messages <span>·</span> {{ number_format($stat->unique_likers) }} users <span>·</span> {{ number_format($stat->messages_count ? $stat->likes_count / $stat->messages_count : 0, 2) }} likes/message</div>
        </a>
      @empty <p class="dmsa-empty">No messages in any category yet.</p> @endforelse
    </div></section>
    <aside class="dms-panel dmsa-featured"><div class="dms-panelhead"><div><h2>Most liked messages</h2><p>Top messages in the selected period</p></div></div><div class="dmsa-toplist">@forelse($topMessages->take(8) as $message)<div class="dmsa-topitem"><div class="dmsa-rank">{{ $loop->iteration }}</div><div><p lang="bn">{{ $message->message_bn }}</p><small>{{ $categories[$message->category]['en'] ?? 'Uncategorized' }} · {{ $message->code }}</small></div><strong>♡ {{ number_format($message->likes_count) }}</strong></div>@empty<p class="dmsa-empty">No messages to rank.</p>@endforelse</div></aside>
  </div>
  <section class="dms-panel dmsa-section" id="dm-top-messages"><div class="dms-panelhead"><div><h2>Individual message performance</h2><p>Click a category above to focus the list. Likes include currently saved reactions only.</p></div>@if($category !== 'all')<a class="dms-btn dms-small" href="{{ route('admin.daily-messages.analytics', ['period' => $period, 'category' => 'all']) }}#dm-top-messages">All categories</a>@endif</div>
    <div class="dmsa-message-head"><span>MESSAGE</span><span>STATUS</span><span>LIKES</span><span>MANAGE</span></div>
    <div class="dmsa-message-list">@forelse($topMessages as $message)<article class="dmsa-message-row"><div><span class="dmsa-msg-code">{{ $message->code }} · {{ $categories[$message->category]['en'] ?? 'Uncategorized' }}</span><p lang="bn">{{ $message->message_bn }}</p></div><span class="dms-state {{ $message->status === 'published' ? 'is-live' : 'is-draft' }}">{{ ucfirst($message->status) }}</span><strong>{{ number_format($message->likes_count) }}</strong><a href="{{ route('admin.daily-messages.edit', $message->id) }}">Edit</a></article>@empty<div class="dmsa-empty">No messages match this selection.</div>@endforelse</div>
    <div class="dmsa-footer">Showing up to 50 messages, including messages with zero likes.</div>
  </section>
  <section class="dms-panel dmsa-section" id="dm-users"><div class="dms-panelhead"><div><h2>People engaging with Daily Messages</h2><p>Ranked by likes, not by overall app usage. Select a user to open their protected admin profile.</p></div></div>
  <div class="dmsa-users-head"><span>USER</span><span>LIKES</span><span>ACTIVE DAYS</span><span>LAST LIKED</span></div>
  @forelse($activeUsers as $user)<div class="dmsa-user"><div><a href="{{ route('admin.users.show', $user->id) }}">{{ $user->name ?: 'User #'.$user->id }}</a><small>Member {{ $user->member_no }}</small></div><strong>{{ number_format($user->likes_count) }}</strong><span>{{ number_format($user->active_days) }}</span><span>{{ $user->last_liked_at ? \Illuminate\Support\Carbon::parse($user->last_liked_at)->format('d M Y, H:i').' UTC' : '—' }}</span></div>@empty<div class="dmsa-empty">No users have liked a message in the selected period.</div>@endforelse
  <div class="dmsa-footer">Showing up to 30 users. “Active days” means distinct days with currently recorded likes, not daily app sessions.</div></section>
  <p class="dmsa-disclaimer">These are current saved likes. When a user unlikes a message, that like is removed from the totals. Message exposure, app opens and overall app activity are not measured here.</p>
</div>
@endsection
