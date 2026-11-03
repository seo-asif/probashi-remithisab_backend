@extends('admin.layouts.app')
@section('title', 'Daily Messages Tools · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/daily-messages.css') }}">
<link rel="stylesheet" href="{{ asset('css/admin/pages/daily-message-tools.css') }}">
@endpush
@section('content')
<div class="dm-page dm-tools">
    <a class="dm-back" href="{{ route('admin.daily-messages') }}">← Back to Daily Messages</a>
    <div class="dm-heading"><div><div class="dm-eyebrow">Content management</div><h1>CSV tools</h1><p>Preview the file before importing. Existing message codes will be updated; new codes will be created.</p></div></div>
    @if($errors->any())<div class="dm-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="dm-tool-panel">
        <h2>Import messages</h2><p>UTF-8 CSV, up to 1 MB and 1,000 rows. Required columns: <code>code,message_bn,message_en,status</code>. Optional final column: <code>category</code>.</p>
        <form method="POST" action="{{ route('admin.daily-messages.import.preview') }}" enctype="multipart/form-data" class="dm-tool-form">
            @csrf
            <label for="dm-csv-file">Choose CSV file</label><input id="dm-csv-file" type="file" name="file" accept=".csv,.txt,text/csv" required>
            <button class="dm-button" type="submit">Preview import</button>
        </form>
        @if($preview)
        <div class="dm-preview" aria-label="Import preview">
            <h3>Import preview</h3><p><strong>{{ count($preview['rows']) }}</strong> rows · <strong>{{ $preview['create'] }}</strong> new · <strong>{{ $preview['update'] }}</strong> existing</p>
            <div class="dm-table-wrap"><table><thead><tr><th>Code</th><th>Bengali message</th><th>Status</th></tr></thead><tbody>
            @foreach(array_slice($preview['rows'], 0, 10, true) as $row)
                <tr><td>{{ $row['code'] }}</td><td lang="bn">{{ $row['message_bn'] }}</td><td>{{ ucfirst($row['status']) }}</td></tr>
            @endforeach
            </tbody></table></div>
            @if(count($preview['rows']) > 10)<p>Showing the first 10 of {{ count($preview['rows']) }} rows.</p>@endif
            <form method="POST" action="{{ route('admin.daily-messages.import.commit') }}" class="dm-tool-form">
                @csrf
                <input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                <label class="dm-check"><input type="checkbox" name="confirm" value="1" required> I understand that matching message codes will be updated, including publication status.</label>
                <button class="dm-button" type="submit">Import {{ count($preview['rows']) }} messages</button>
                <p>The preview expires after 10 minutes. Do not import if any details are incorrect.</p>
            </form>
        </div>
        @endif
    </section>
    <section class="dm-tool-panel"><h2>Export all messages</h2><p>Download a UTF-8 CSV containing Bengali and English text, message codes, status, and category.</p><a class="dm-button" href="{{ route('admin.daily-messages.export') }}">Download CSV</a></section>
</div>
@endsection
