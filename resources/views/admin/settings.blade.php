@extends('admin.layouts.app')
@section('title', 'Account Settings · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/settings.css') }}">
@endpush
@section('content')
<div class="settings-page">
    <header class="settings-header">
        <div class="settings-eyebrow">Administrator account</div>
        <h1>Account Settings</h1>
        <p>Review your account security and change your administrator password.</p>
    </header>

    <div class="settings-grid">
        <section class="settings-card" aria-labelledby="settings-account-title">
            <h2 id="settings-account-title">Account details</h2>
            <dl class="settings-details">
                <div><dt>Name</dt><dd>{{ $account->name }}</dd></div>
                <div><dt>Email</dt><dd>{{ $account->email }}</dd></div>
                <div><dt>Account status</dt><dd>{{ ucfirst($account->status->value) }}</dd></div>
                <div><dt>Email verification</dt><dd>{{ $account->email_verified_at ? 'Verified' : 'Not verified' }}</dd></div>
            </dl>
        </section>

        <section class="settings-card" aria-labelledby="settings-mfa-title">
            <h2 id="settings-mfa-title">Two-step verification</h2>
            <p class="settings-status">{{ $mfaEnabled ? 'Authenticator configured' : 'Authenticator not configured' }}</p>
            <p class="settings-help">{{ $mfaEnabled ? 'Your administrator sign-in requires an authenticator code or a single-use recovery code.' : 'Contact an authorized operator to provision administrator MFA.' }}</p>
            @if($mfaEnabled)
                <p class="settings-help">Unused recovery codes: <strong>{{ $recoveryCodesRemaining }}</strong></p>
            @endif
            @if($mfaEnabled)
                <div class="settings-recovery-action">
                    <h3>Generate new recovery codes</h3>
                    <p class="settings-help">All existing recovery codes will stop working immediately. Enter your current password and a fresh 6-digit authenticator code. Eight replacement codes will be displayed once.</p>
                    <form class="settings-form" method="POST" action="{{ route('admin.settings.recovery.regenerate') }}" autocomplete="off">
                        @csrf
                        <div class="settings-field">
                            <label for="recovery_current_password">Current password</label>
                            <input type="password" id="recovery_current_password" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="settings-field">
                            <label for="mfa_code">Authenticator code</label>
                            <input type="text" id="mfa_code" name="mfa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                            @error('mfa_code') <span class="settings-error" role="alert">{{ $message }}</span> @enderror
                        </div>
                        <button type="submit">Replace recovery codes</button>
                    </form>
                </div>
            @endif
        </section>

        <section class="settings-card settings-full" aria-labelledby="settings-password-title">
            <h2 id="settings-password-title">Change password</h2>
            <p class="settings-help">Changing your password signs you out of this browser session. Other active sessions are not automatically revoked.</p>
            @if($errors->any())
                <div class="settings-errors" role="alert">Please correct the highlighted fields.</div>
            @endif
            <form class="settings-form" method="POST" action="{{ route('admin.settings.password') }}">
                @csrf
                <div class="settings-field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}">
                    @error('current_password') <span class="settings-error">{{ $message }}</span> @enderror
                </div>
                <div class="settings-field">
                    <label for="password">New password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                    <small>At least 12 characters, including uppercase, lowercase, and numbers.</small>
                    @error('password') <span class="settings-error">{{ $message }}</span> @enderror
                </div>
                <div class="settings-field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required>
                </div>
                <button type="submit">Update password and sign out</button>
            </form>
        </section>
    </div>
</div>
@endsection
