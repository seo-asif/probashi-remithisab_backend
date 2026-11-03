@extends('admin.layouts.app')
@section('title', 'Daily Messages · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/daily-messages.css') }}">
<link rel="stylesheet" href="{{ asset('css/admin/pages/daily-message-tools.css') }}">
@endpush
@section('content')
<div class="dm-page">
    <div class="dm-heading">
        <div><div class="dm-eyebrow">Content management</div><h1>Daily Messages</h1><p>Manage the messages shown in the app. Changes to published messages affect the next message sync.</p></div>
        <a class="dm-button" href="{{ route('admin.daily-messages.create') }}">+ Add message</a>
    </div>
    <div class="dm-utility-bar">
        <a href="{{ route('admin.daily-messages.tools') }}">CSV import / export</a>
        <a href="{{ route('admin.daily-messages.analytics') }}">Likes analytics</a>
    </div>
    <form id="dm-bulk-form" method="POST" action="{{ route('admin.daily-messages.bulk-status') }}" class="dm-bulk-bar">
        @csrf
        <span>Select messages on this page:</span>
        <select name="status" aria-label="Bulk publication action" required><option value="published">Publish selected</option><option value="draft">Unpublish selected</option></select>
        <button type="submit">Apply to selected</button>
    </form>
    @if(session('notice')) <div class="dm-notice" role="status">{{ session('notice') }}</div> @endif
    <div class="dm-stats">
        <div><span>Total messages</span><strong>{{ number_format($counts['total']) }}</strong></div>
        <div><span>Published</span><strong>{{ number_format($counts['published']) }}</strong></div>
        <div><span>Drafts</span><strong>{{ number_format($counts['draft']) }}</strong></div>
    </div>
    <section class="dm-panel" aria-label="Message library">
        <div class="dm-panel-title"><div><h2>Message library</h2><p>Search Bengali or English text, message code, category, or publication status.</p></div><span>{{ number_format($messages->total()) }} results</span></div>
        <form class="dm-filters" method="GET" action="{{ route('admin.daily-messages') }}">
            <input type="search" name="q" maxlength="200" aria-label="Search messages" value="{{ $search }}" placeholder="Search messages or code">
            <select name="status" aria-label="Filter message status"><option value="all">All statuses</option><option value="published" @selected($status === 'published')>Published</option><option value="draft" @selected($status === 'draft')>Draft</option></select>
            <select name="category" aria-label="Filter message category"><option value="all">All categories</option>@foreach($categories as $code => $labels)<option value="{{ $code }}" @selected($category === $code)>{{ $labels['en'] }}</option>@endforeach</select>
            <button type="submit">Apply filters</button>
            <a class="dm-clear" href="{{ route('admin.daily-messages') }}">Clear</a>
        </form>
        <div class="dm-table-wrap"><table><thead><tr><th>Message</th><th>Category</th><th>Status</th><th>Order</th><th>Actions</th></tr></thead><tbody>
        @forelse($messages as $message)
            <tr><td><label class="dm-select-message"><input type="checkbox" name="ids[]" value="{{ $message->id }}" form="dm-bulk-form" aria-label="Select {{ $message->code }}"> <span>Select</span></label><div class="dm-code">{{ $message->code }}</div><div class="dm-message" lang="bn">{{ $message->message_bn }}</div>@if($message->message_en)<div class="dm-translation">{{ $message->message_en }}</div>@endif</td>
            <td>{{ $categories[$message->category]['en'] ?? 'Uncategorized' }}</td><td><span class="dm-pill {{ $message->status === 'published' ? 'dm-live' : '' }}">{{ ucfirst($message->status) }}</span></td>
            <td>{{ $message->sort_order }}</td><td><div class="dm-actions"><a href="{{ route('admin.daily-messages.edit', $message->id) }}">Edit</a>
            <form method="POST" action="{{ route('admin.daily-messages.status', $message->id) }}">@csrf<input type="hidden" name="status" value="{{ $message->status === 'published' ? 'draft' : 'published' }}"><button type="submit">{{ $message->status === 'published' ? 'Unpublish' : 'Publish' }}</button></form></div></td></tr>
        @empty <tr><td colspan="5" class="dm-empty">No messages match the selected filters.</td></tr> @endforelse
        </tbody></table></div>
        <div class="dm-foot"><span>{{ $messages->total() ? $messages->firstItem().'-'.$messages->lastItem().' of '.$messages->total() : '0 messages' }}</span><div>@if($messages->onFirstPage())<span>Previous</span>@else<a href="{{ $messages->previousPageUrl() }}">Previous</a>@endif <span>Page {{ $messages->currentPage() }} of {{ $messages->lastPage() }}</span> @if($messages->hasMorePages())<a href="{{ $messages->nextPageUrl() }}">Next</a>@else<span>Next</span>@endif</div></div>
    </section>
</div>
@endsection
