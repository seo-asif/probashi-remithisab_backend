@extends('admin.layouts.app')
@section('title', 'Daily Message Analytics · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/daily-messages.css') }}">
<link rel="stylesheet" href="{{ asset('css/admin/pages/daily-message-tools.css') }}">
@endpush
@section('content')
<div class="dm-page dm-tools">
    <a class="dm-back" href="{{ route('admin.daily-messages') }}">← Back to Daily Messages</a>
    <div class="dm-heading"><div><div class="dm-eyebrow">Content analytics</div><h1>Message likes</h1><p>Aggregated likes from the existing app activity. Values reflect current records.</p></div></div>
    <div class="dm-stats"><div><span>Total likes</span><strong>{{ number_format($totalLikes) }}</strong></div><div><span>Unique users who liked</span><strong>{{ number_format($uniqueLikers) }}</strong></div><div><span>Messages liked</span><strong>{{ number_format($likedMessages) }}</strong></div></div>
    <section class="dm-tool-panel"><h2>Most liked messages</h2>
        <form class="dm-tool-form dm-filter-row" action="{{ route('admin.daily-messages.analytics') }}" method="GET"><label for="dm-category-filter">Category</label><select id="dm-category-filter" name="category"><option value="all">All categories</option>@foreach($categories as $code=>$label)<option value="{{ $code }}" @selected($category === $code)>{{ $label['en'] }}</option>@endforeach</select><button class="dm-button" type="submit">Filter</button></form>
        <div class="dm-table-wrap"><table><thead><tr><th>Message</th><th>Category</th><th>Likes</th></tr></thead><tbody>
        @forelse($topMessages as $message)<tr><td><div class="dm-code">{{ $message->code }}</div><div class="dm-message" lang="bn">{{ $message->message_bn }}</div></td><td>{{ $categories[$message->category]['en'] ?? 'Uncategorized' }}</td><td>{{ number_format($message->likes_count) }}</td></tr>
        @empty<tr><td colspan="3" class="dm-empty">No messages available.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="dm-tool-panel"><h2>Likes by category</h2><div class="dm-table-wrap"><table><thead><tr><th>Category</th><th>Messages</th><th>Likes</th></tr></thead><tbody>
        @foreach($categoryStats as $stat)<tr><td>{{ $categories[$stat->category]['en'] ?? 'Uncategorized' }}</td><td>{{ number_format($stat->messages_count) }}</td><td>{{ number_format($stat->likes_count) }}</td></tr>@endforeach
    </tbody></table></div></section>
</div>
@endsection
