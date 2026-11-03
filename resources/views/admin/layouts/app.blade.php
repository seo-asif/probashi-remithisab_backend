<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Probashi Admin')</title>
    <style>
        :root {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: #1b3533;
            background: #f6f8f7
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0
        }

        a {
            color: inherit;
            text-decoration: none
        }

        button {
            font: inherit
        }

        .admin-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 244px minmax(0, 1fr)
        }

        .admin-side {
            background: #123d39;
            color: #e9f3ef;
            padding: 30px 18px;
            display: flex;
            flex-direction: column;
            gap: 30px
        }

        .admin-brand {
            padding: 3px 12px;
            font-size: 17px;
            font-weight: 750;
            letter-spacing: -.4px
        }

        .admin-brand small {
            display: block;
            margin-top: 5px;
            color: #a9c9bd;
            font-size: 10px;
            letter-spacing: 1.6px;
            font-weight: 600
        }

        .admin-nav {
            display: grid;
            gap: 5px
        }

        .admin-nav a {
            padding: 12px 13px;
            font-size: 13px;
            border-radius: 10px;
            color: #bdd8cf;
            display: block
        }

        .admin-nav a:hover,
        .admin-nav a[aria-current=page] {
            background: #275a52;
            color: white
        }

        .admin-side-footer {
            margin-top: auto;
            padding: 15px 10px;
            border-top: 1px solid #ffffff21;
            font-size: 12px;
            color: #c1d7d0
        }

        .admin-logout {
            margin-top: 10px;
            border: 1px solid #ffffff42;
            border-radius: 9px;
            color: white;
            background: transparent;
            padding: 9px 12px;
            cursor: pointer
        }

        .admin-content {
            min-width: 0;
            padding: 30px clamp(20px, 3.5vw, 54px) 60px
        }

        .admin-content-inner {
            max-width: 1320px;
            margin: 0 auto
        }

        .admin-mobile {
            display: none
        }

        .admin-mobile summary {
            cursor: pointer;
            font-size: 13px;
            font-weight: 650
        }

        .admin-mobile-links {
            padding: 12px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 14px
        }

        .admin-mobile button {
            border: 1px solid #d5e0db;
            padding: 8px 11px;
            border-radius: 8px;
            background: white;
            color: #19473f
        }

        @media(max-width:850px) {
            .admin-shell {
                display: block
            }

            .admin-side {
                display: none
            }

            .admin-content {
                padding: 22px 16px 44px
            }

            .admin-mobile {
                display: block;
                margin-bottom: 25px;
                background: white;
                border: 1px solid #e3ebe6;
                border-radius: 12px;
                padding: 15px
            }
        }

        @yield('page_styles')
    </style>
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
