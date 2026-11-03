@extends('admin.layouts.app')
@section('title', 'New Recovery Codes · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/settings.css') }}">
@endpush
@section('content')
<div class="settings-page">
    <header class="settings-header">
        <div class="settings-eyebrow">Administrator account</div>
        <h1>New recovery codes</h1>
        <p>These eight recovery codes replace all previous codes. Each can be used once after your password.</p>
    </header>
    <section class="settings-card settings-recovery-result" aria-labelledby="recovery-result-title">
        <h2 id="recovery-result-title">Save these codes now</h2>
        <p class="settings-help">They will not be shown again. Store them offline in a secure place. Do not share them or take a public screenshot.</p>
        <ol class="settings-recovery-codes">
            @foreach ($codes as $code)
                <li><code>{{ $code }}</code></li>
            @endforeach
        </ol>
        <p class="settings-help">Closing this page or returning to Account Settings hides the codes permanently.</p>
        <a class="settings-back-link" href="{{ route('admin.settings') }}">Return to Account Settings</a>
    </section>
</div>
@endsection
