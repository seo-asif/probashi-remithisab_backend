<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Probashi Admin')</title>
    <link rel="stylesheet" href="{{ asset('css/admin/layout.css') }}">
    @stack('head')
</head>

<body>
    <div class="admin-shell">
        <aside class="admin-side" aria-label="Administrator navigation">
            <div class="admin-brand">Probashi Admin<small>PLATFORM OPERATIONS</small></div>
            <nav class="admin-nav">
                <a href="{{ route('admin.dashboard') }}"
                    @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif>Overview</a>
                <a href="{{ route('admin.users') }}"
                    @if (request()->routeIs('admin.users', 'admin.users.show')) aria-current="page" @endif>Users</a>
                <a href="{{ route('admin.payments') }}"
                    @if (request()->routeIs('admin.payments', 'admin.payments.show')) aria-current="page" @endif>Payments</a>
                <a href="{{ route('admin.subscriptions') }}"
                    @if (request()->routeIs('admin.subscriptions')) aria-current="page" @endif>Subscriptions</a>
                <a href="{{ route('admin.businesses') }}"
                    @if (request()->routeIs('admin.businesses', 'admin.businesses.show')) aria-current="page" @endif>Businesses</a>
                <a href="{{ route('admin.activity-logs') }}"
                    @if (request()->routeIs('admin.activity-logs')) aria-current="page" @endif>Activity Logs</a>
            </nav>
            <div class="admin-side-footer">
                <div>Administrator workspace</div>
                <form method="POST" action="{{ route('admin.logout') }}">@csrf<button class="admin-logout"
                        type="submit">Sign out</button></form>
            </div>
        </aside>
        <main class="admin-content" id="main-content">
            <div class="admin-content-inner">
                <details class="admin-mobile">
                    <summary>Probashi Admin &middot; Menu</summary>
                    <div class="admin-mobile-links">
                        <a href="{{ route('admin.dashboard') }}">Overview</a>
                        <a href="{{ route('admin.users') }}">Users</a>
                        <a href="{{ route('admin.payments') }}">Payments</a>
                        <a href="{{ route('admin.subscriptions') }}"
                            @if (request()->routeIs('admin.subscriptions')) aria-current="page" @endif>Subscriptions</a>
                        <a href="{{ route('admin.businesses') }}">Businesses</a>
                        <a href="{{ route('admin.activity-logs') }}">Activity Logs</a>
                        <form method="POST" action="{{ route('admin.logout') }}">@csrf<button type="submit">Sign
                                out</button></form>
                    </div>
                </details>
                @yield('content')
            </div>
        </main>
    </div>
    @stack('scripts')
</body>

</html>
