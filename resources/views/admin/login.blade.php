<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Administrator sign in</title><link rel="stylesheet" href="{{ asset('css/admin/auth-login.css') }}"></head><body><main><h1>Administrator sign in</h1><p>Authorized platform administrators only.</p>
@if ($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<form method="post" action="{{ route('admin.login.submit') }}">@csrf
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}">
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
<button type="submit">Continue securely</button></form></main></body></html>
