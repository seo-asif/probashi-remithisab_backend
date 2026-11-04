@extends('admin.layouts.app')
@section('title', 'Daily Messages Studio · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/dm-studio.css') }}">
@endpush
@section('content')
<div class="dms" id="dmStudio" data-update-url="{{ route('admin.daily-messages.update', ['message' => 999999999]) }}" data-create-url="{{ route('admin.daily-messages.store') }}">
  <header class="dms-head">
    <div><div class="dms-kicker">CONTENT STUDIO</div><h1>Daily Messages</h1><p>Write, preview and publish messages for people using the app.</p></div>
    <button class="dms-btn dms-primary" type="button" id="dmCreate">＋ Create message</button>
  </header>
  <nav class="dms-tabs" aria-label="Daily Messages sections">
    <a class="active" aria-current="page" href="{{ route('admin.daily-messages') }}">Message library</a>
    <a href="{{ route('admin.daily-messages.analytics') }}">Analytics &amp; users</a>
    <a href="{{ route('admin.daily-messages.tools') }}">Import / Export</a>
  </nav>
  @if(session('notice'))<div class="dms-notice" role="status">{{ session('notice') }}</div>@endif
  @if($errors->any())<div class="dms-error" role="alert"><strong>Please correct the message details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <section class="dms-metrics" aria-label="Message totals">
    <div><span>All messages</span><strong>{{ number_format($counts['total']) }}</strong><small>In your library</small></div>
    <div><span>Published</span><strong>{{ number_format($counts['published']) }}</strong><small>Available at the next app sync</small></div>
    <div><span>Drafts</span><strong>{{ number_format($counts['draft']) }}</strong><small>Not shown in the app</small></div>
    <div class="dms-metric-link"><span>Engagement</span><strong>↗</strong><a href="{{ route('admin.daily-messages.analytics') }}">Explore message likes and users</a></div>
  </section>
  <section class="dms-workgrid">
    <div class="dms-panel dms-library">
      <div class="dms-panelhead"><div><h2>Message library</h2><p>{{ number_format($messages->total()) }} messages matching your filters</p></div><button type="button" class="dms-btn dms-small" id="dmQuickCreate">＋ New</button></div>
      <form class="dms-filter" action="{{ route('admin.daily-messages') }}" method="GET" id="dmFilterForm">
        <label class="dms-search"><span>⌕</span><input type="search" id="dmSearch" name="q" value="{{ $search }}" maxlength="200" placeholder="Search Bengali, English or code" aria-label="Search messages"></label>
        <select id="dmCategoryFilter" name="category" aria-label="Filter category"><option value="all">All categories</option>@foreach($categories as $code => $labels)<option value="{{ $code }}" @selected($category === $code)>{{ $labels['en'] }}</option>@endforeach</select>
        <select id="dmStatusFilter" name="status" aria-label="Publication status"><option value="all">All statuses</option><option value="published" @selected($status === 'published')>Published</option><option value="draft" @selected($status === 'draft')>Drafts</option></select>
        <button class="dms-btn dms-small" type="submit">Search</button>
        <a class="dms-clear" href="{{ route('admin.daily-messages') }}">Reset</a>
      </form>
      <form method="POST" id="dmBulkForm" action="{{ route('admin.daily-messages.bulk-status') }}" class="dms-bulk" hidden>@csrf
        <span id="dmSelectedCount">0 selected</span>
        <button class="dms-btn dms-small" type="submit" name="status" value="published">Publish selected</button>
        <button class="dms-btn dms-small" type="submit" name="status" value="draft">Move to drafts</button>
        <button class="dms-btn dms-small dms-muted-btn" type="button" id="dmClearSelection">Clear</button>
      </form>
      <div class="dms-list" id="dmMessageList">
      @forelse($messages as $message)
        <article class="dms-item" data-id="{{ $message->id }}">
          <label class="dms-checkbox"><input type="checkbox" name="ids[]" value="{{ $message->id }}" form="dmBulkForm" aria-label="Select {{ $message->code }}"></label>
          <div class="dms-item-main">
            <div class="dms-itemmeta"><span class="dms-state {{ $message->status === 'published' ? 'is-live' : 'is-draft' }}">{{ ucfirst($message->status) }}</span><span>{{ $categories[$message->category]['en'] ?? 'Uncategorized' }}</span><span class="dms-code">{{ $message->code }}</span></div>
            <button type="button" class="dms-preview-trigger" data-preview="{{ $message->id }}" lang="bn">{{ $message->message_bn }}</button>
            @if($message->message_en)<p class="dms-translation">{{ $message->message_en }}</p>@endif
          </div>
          <div class="dms-itemactions">
            <span class="dms-likes" title="Current recorded likes">♡ {{ number_format($message->likes_count ?? 0) }}</span>
            <button type="button" class="dms-btn dms-small dms-edit" data-edit="{{ $message->id }}">Edit</button>
            <form method="POST" action="{{ route('admin.daily-messages.status', $message->id) }}" class="dms-status-form">@csrf<input type="hidden" name="status" value="{{ $message->status === 'published' ? 'draft' : 'published' }}"><button type="submit" class="dms-btn dms-small">{{ $message->status === 'published' ? 'Unpublish' : 'Publish' }}</button></form>
          </div>
        </article>
      @empty
        <div class="dms-empty"><strong>No matching messages</strong><p>Try clearing the filters, or create your first message.</p></div>
      @endforelse
      </div>
      <div class="dms-pagination"><span>{{ $messages->total() ? $messages->firstItem().'-'.$messages->lastItem().' of '.$messages->total() : '0 messages' }}</span><div>@if($messages->onFirstPage())<span>Previous</span>@else<a href="{{ $messages->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $messages->currentPage() }} / {{ $messages->lastPage() }}</span>@if($messages->hasMorePages())<a href="{{ $messages->nextPageUrl() }}">Next →</a>@else<span>Next</span>@endif</div></div>
    </div>
    <aside class="dms-panel dms-preview-panel" aria-label="App message preview">
      <div class="dms-panelhead"><div><h2>App preview</h2><p>Select a message to preview</p></div><span class="dms-preview-tag">Preview</span></div>
      <div class="dms-app-card"><div class="dms-app-caption"><strong>আজকের কথা</strong><span id="dmPreviewCategory">Message</span></div><p id="dmPreviewBn" lang="bn">Select a message from your library to see its Bengali text here.</p><div class="dms-app-foot"><span>♡ Like</span><span>↗ Share</span></div></div>
      <div class="dms-preview-info" id="dmPreviewInfo">This preview shows the text and category. The Flutter app may render the card differently.</div>
      <button type="button" id="dmPreviewEdit" class="dms-btn dms-primary" hidden>Edit selected message</button>
    </aside>
  </section>
</div>
<div class="dms-overlay" id="dmOverlay" hidden></div>
<aside class="dms-drawer" id="dmDrawer" role="dialog" aria-modal="true" aria-labelledby="dmDrawerTitle" aria-hidden="true" hidden>
  <form method="POST" id="dmEditForm" action="{{ route('admin.daily-messages.store') }}" class="dms-drawer-form">@csrf
    <header class="dms-drawer-head"><div><div class="dms-kicker">MESSAGE COMPOSER</div><h2 id="dmDrawerTitle">Create a message</h2><p>Write a short, useful message for app users.</p></div><button type="button" class="dms-close" id="dmClose" aria-label="Close editor">×</button></header>
    <div class="dms-drawer-scroll">
      <input type="hidden" id="dmEditingId" name="dm_editing_id" value="{{ old('dm_editing_id', '') }}">
      <label class="dms-field">Bengali message <b>*</b><textarea name="message_bn" id="dmBn" lang="bn" required rows="4" maxlength="1000" placeholder="বাংলা বার্তা লিখুন...">{{ old('message_bn', '') }}</textarea><small id="dmBnCount">0 / 1000</small></label>
      <label class="dms-field">English translation <span>(optional)</span><textarea name="message_en" id="dmEn" rows="3" maxlength="1000" placeholder="English text">{{ old('message_en', '') }}</textarea></label>
      <div class="dms-two"><label class="dms-field">Category<select name="category" id="dmEditCategory"><option value="">Uncategorized</option>@foreach($categories as $code => $labels)<option value="{{ $code }}" @selected(old('category') === $code)>{{ $labels['en'] }} · {{ $labels['bn'] }}</option>@endforeach</select></label>
      <label class="dms-field">Publication<select name="status" id="dmEditStatus"><option value="draft" @selected(old('status', 'draft') === 'draft')>Save as draft</option><option value="published" @selected(old('status') === 'published')>Publish</option></select></label></div>
      <details class="dms-advanced" id="dmAdvanced"><summary>Advanced settings</summary><label class="dms-field">Internal message code<input name="code" id="dmCode" maxlength="64" required value="{{ old('code', '') }}"><small>Generated automatically for new messages. Must remain unique.</small></label><label class="dms-field">Sort order<input type="number" name="sort_order" id="dmOrder" min="0" max="1000000" value="{{ old('sort_order', 0) }}" required></label></details>
      <div class="dms-composer-preview"><span>LIVE MESSAGE PREVIEW</span><div class="dms-app-card"><div class="dms-app-caption"><strong>আজকের কথা</strong><span id="dmLiveCat">Uncategorized</span></div><p id="dmLiveBn" lang="bn">আপনার বাংলা বার্তা এখানে দেখা যাবে।</p><div class="dms-app-foot"><span>♡ Like</span><span>↗ Share</span></div></div></div>
    </div>
    <footer class="dms-drawer-actions"><button type="button" class="dms-btn" id="dmCancel">Cancel</button><button type="submit" class="dms-btn dms-primary" id="dmSave">Create message</button></footer>
  </form>
</aside>
<script type="application/json" id="dmMessagesJson">@json($messages->items())</script>
@push('scripts')<script src="{{ asset('js/admin/pages/dm-studio.js') }}" defer></script>@endpush
@endsection
