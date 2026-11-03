@extends('admin.layouts.app')
@section('title', ($message ? 'Edit message' : 'New message').' · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/daily-messages.css') }}">
@endpush
@section('content')
<div class="dm-page dm-editor">
    <a class="dm-back" href="{{ route('admin.daily-messages') }}">← Back to Daily Messages</a>
    <div class="dm-heading"><div><div class="dm-eyebrow">Content management</div><h1>{{ $message ? 'Edit message' : 'Add a message' }}</h1><p>Write clear, short messages for the daily app card. Bengali text is required.</p></div></div>
    <form class="dm-edit-panel" method="POST" action="{{ $message ? route('admin.daily-messages.update', $message->id) : route('admin.daily-messages.store') }}">
        @csrf
        @if($errors->any())<div class="dm-errors" role="alert"><strong>Check the highlighted fields.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="dm-fields-grid"><label>Message code <span>*</span><input name="code" required maxlength="64" value="{{ old('code', $message?->code ?? '') }}" placeholder="e.g. family_001" aria-describedby="dm-code-hint"><small id="dm-code-hint">Unique internal identifier.</small></label>
        <label>Category<select name="category"><option value="">Uncategorized</option>@foreach($categories as $code=>$labels)<option value="{{ $code }}" @selected(old('category', $message?->category) === $code)>{{ $labels['en'] }} — {{ $labels['bn'] }}</option>@endforeach</select></label></div>
        <label>Bengali message <span>*</span><textarea name="message_bn" lang="bn" required maxlength="1000" rows="5" placeholder="বাংলা বার্তা লিখুন">{{ old('message_bn', $message?->message_bn ?? '') }}</textarea></label>
        <label>English translation <small>(optional)</small><textarea name="message_en" maxlength="1000" rows="4" placeholder="Optional English text">{{ old('message_en', $message?->message_en ?? '') }}</textarea></label>
        <div class="dm-fields-grid"><label>Status<select name="status" required><option value="draft" @selected(old('status', $message?->status ?? 'draft') === 'draft')>Draft</option><option value="published" @selected(old('status', $message?->status ?? 'draft') === 'published')>Published</option></select><small>Published messages may appear in the app after synchronization.</small></label>
        <label>Sort order<input type="number" name="sort_order" required min="0" max="1000000" value="{{ old('sort_order', $message?->sort_order ?? 0) }}"><small>Lower values appear first in this dashboard.</small></label></div>
        <div class="dm-form-actions"><button class="dm-button" type="submit">{{ $message ? 'Save changes' : 'Create message' }}</button><a href="{{ route('admin.daily-messages') }}">Cancel</a></div>
    </form>
</div>
@endsection
