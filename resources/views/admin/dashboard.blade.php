@extends('admin.layouts.app')
@section('title', 'Platform Overview · Probashi Admin')
@push('head')
    <link rel="stylesheet" href="{{ asset('css/admin/pages/dashboard.css') }}">
@endpush
@section('content')
    <div id="overview-page" class="ov3">
        <header class="ov3-top">
            <div>
                <div class="ov3-kicker">PLATFORM OVERVIEW</div>
                <h1>Good to see you.</h1>
                <p>Your operations workspace, with live Laravel data.</p>
            </div>
            <form class="ov3-period" method="GET" action="{{ route('admin.dashboard') }}" aria-label="Reporting period">
                @foreach (['7' => '7 days', '30' => '30 days', '90' => '90 days'] as $value => $label)
                    <button name="period" value="{{ $value }}" type="submit"
                        class="{{ (int) $period === (int) $value ? 'selected' : '' }}"
                        aria-pressed="{{ (int) $period === (int) $value ? 'true' : 'false' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </form>
        </header>
        <section class="ov3-brief" aria-label="Daily brief">
            <div>
                <div class="ov3-brief-label"><span class="ov3-dot"></span> YOUR DAILY BRIEF</div>

                <h2>
                    {{ $metrics['pendingPayments'] > 0
                        ? $metrics['pendingPayments'] .
                            ' payment ' .
                            ($metrics['pendingPayments'] === 1 ? 'needs' : 'requests need') .
                            ' review.'
                        : 'No payment requests are awaiting review.' }}
                    Your platform has {{ number_format($metrics['users']) }}
                    registered {{ $metrics['users'] === 1 ? 'user' : 'users' }}.
                </h2>

            </div>
            <a href="{{ route('admin.payments', ['status' => 'pending']) }}">Review payment queue <span
                    aria-hidden="true">→</span></a>
        </section>
        <div class="ov3-main-grid">
            <div class="ov3-column">
                <section class="ov3-panel">
                    <header class="ov3-panel-header">
                        <div>
                            <h2>Needs your attention</h2>
                            <p>Items requiring a decision, not just another number.</p>
                        </div><span class="ov3-chip ov3-warning">{{ number_format($metrics['pendingPayments']) }}
                            pending</span>
                    </header>
                    <div class="ov3-queue">
                        @forelse($recentPayments as $payment)
                            <button type="button" class="ov3-queue-row ov3-inspect"
                                data-title="Payment request #{{ $payment->id }}"
                                data-description="Currently pending review. Open the protected Payments workspace to examine the request and make a decision."
                                data-status="Pending" data-link="{{ route('admin.payments.show', $payment->id) }}"
                                data-link-label="Open payment details">
                                <span class="ov3-symbol ov3-gold">▣</span><span class="ov3-grow"><strong>Payment request
                                        #{{ $payment->id }} is pending</strong><small>Submitted
                                        {{ \Illuminate\Support\Carbon::parse($payment->created_at)->timezone('Pacific/Fiji')->format('d M Y, H:i') }}
                                        Fiji time</small></span><span aria-hidden="true">›</span>
                            </button>
                        @empty
                            <div class="ov3-empty">No payment requests currently need review.</div>
                        @endforelse
                    </div>
                    <footer class="ov3-panel-footer"><span>
                            @if ($metrics['pendingOlderThan48h'] > 0)
                                {{ $metrics['pendingOlderThan48h'] }} pending for more than 48 hours
                            @else
                                No overdue payment requests detected
                            @endif
                        </span><a href="{{ route('admin.payments', ['status' => 'pending']) }}">Open payments →</a>
                    </footer>
                </section>
                <section class="ov3-panel">
                    <header class="ov3-panel-header">
                        <div>
                            <h2>What happened recently</h2>
                            <p>Real registrations and payment activity in the selected period.</p>
                        </div><span class="ov3-chip">{{ $activityEvents->count() }} events</span>
                    </header>
                    <div class="ov3-tabs" role="group" aria-label="Activity filter"><button type="button"
                            class="selected" data-event-filter="all" aria-pressed="true">All activity</button><button
                            type="button" data-event-filter="payment" aria-pressed="false">Payments</button><button
                            type="button" data-event-filter="account" aria-pressed="false">Accounts</button></div>
                    <div class="ov3-timeline" id="ov3-timeline">
                        @forelse($activityEvents as $event)
                            <button type="button" class="ov3-event ov3-inspect" data-kind="{{ $event->type }}"
                                data-title="{{ $event->title }}" data-description="{{ $event->detail }}"
                                data-status="Recorded" data-link="{{ $event->link }}" data-link-label="Open record">
                                <span class="ov3-symbol">{{ $event->type === 'account' ? '◉' : '▣' }}</span><span
                                    class="ov3-grow"><strong>{{ $event->title }}</strong><small>{{ $event->detail }}</small></span><time
                                    datetime="{{ \Illuminate\Support\Carbon::parse($event->occurred_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($event->occurred_at)->timezone('Pacific/Fiji')->format('d M') }}</time><span
                                    aria-hidden="true">›</span>
                            </button>
                        @empty<div class="ov3-empty">No recorded activity in this period.</div>
                        @endforelse
                        <div id="ov3-no-match" class="ov3-empty" hidden>No events of this type in this period.</div>
                    </div>
                </section>
                <section class="ov3-panel" id="ov3-registrations">
                    <header class="ov3-panel-header">
                        <div>
                            <h2>Registrations over time</h2>
                            <p>New registered accounts, grouped across {{ $periodLabel }}.</p>
                        </div><a class="ov3-simple-link" href="{{ route('admin.users') }}">View users →</a>
                    </header>
                    <div class="ov3-chart-summary"><strong>{{ number_format($metrics['newUsers']) }}</strong><span>new
                            registrations</span><span class="ov3-compare">Previous {{ $period }} days:
                            {{ number_format($metrics['previousUsers']) }}</span></div>
                    <div class="ov3-bars" role="img" aria-label="Registration counts in {{ $periodLabel }}">
                        @foreach ($registrationTrend as $entry)
                            <div class="ov3-bar-cell" title="{{ $entry['label'] }}: {{ $entry['count'] }} registrations">
                                <span class="ov3-bar-count">{{ $entry['count'] }}</span><span class="ov3-bar-track"><span
                                        class="ov3-bar"
                                        style="height:{{ max(3, round((100 * $entry['count']) / $trendMax)) }}%"></span></span><span
                                    class="ov3-bar-date">{{ $entry['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
            <div class="ov3-column">
                <section class="ov3-panel">
                    <header class="ov3-panel-header">
                        <div>
                            <h2>Understand your platform</h2>
                            <p>Useful questions with one-click answers.</p>
                        </div>
                    </header>
                    <div class="ov3-questions">
                        <a href="{{ route('admin.users') }}"><span class="ov3-question-icon">♙</span><span><strong>Who
                                    joined recently?</strong><small>Search users and inspect registration
                                    details.</small></span><b>Explore →</b></a>
                        <a href="{{ route('admin.user-activity') }}"><span
                                class="ov3-question-icon">▥</span><span><strong>Who is using the app?</strong><small>Compare
                                    recorded remittances and message likes.</small></span><b>Explore →</b></a>
                        <a href="{{ route('admin.subscriptions') }}"><span
                                class="ov3-question-icon">▤</span><span><strong>Are subscriptions
                                    growing?</strong><small>Review paid tiers and access dates.</small></span><b>Explore
                                →</b></a>
                    </div>
                </section>
                <section class="ov3-panel">
                    <header class="ov3-panel-header">
                        <div>
                            <h2>Feature usage</h2>
                            <p>What Laravel can currently measure.</p>
                        </div>
                    </header>
                    <div class="ov3-feature-list">
                        <a href="{{ route('admin.user-activity', ['period' => $period]) }}"><span
                                class="ov3-symbol">▤</span><span class="ov3-grow"><strong>Remittance
                                    records</strong><small>{{ number_format($metrics['remittances']) }} saved in this
                                    period, not money transferred</small></span><b>View →</b></a>
                        <a href="{{ route('admin.daily-messages.analytics') }}"><span class="ov3-symbol">♡</span><span
                                class="ov3-grow"><strong>Daily Message
                                    likes</strong><small>{{ number_format($metrics['likes']) }} currently saved in this
                                    period</small></span><b>View →</b></a>
                        <div><span class="ov3-symbol">◷</span><span class="ov3-grow"><strong>Daily active
                                    users</strong><small>Flutter active-day reporting isn't connected
                                    yet.</small></span><span class="ov3-chip">Pending</span></div>
                    </div>
                </section>
                <section class="ov3-panel ov3-snapshot">
                    <header class="ov3-panel-header">
                        <div>
                            <h2>Platform snapshot</h2>
                            <p>Lifetime records · not affected by date filter.</p>
                        </div>
                    </header>
                    <div class="ov3-snapshot-grid">
                        <a
                            href="{{ route('admin.users') }}"><span>Users</span><strong>{{ number_format($metrics['users']) }}</strong></a>
                        <a
                            href="{{ route('admin.businesses') }}"><span>Businesses</span><strong>{{ number_format($metrics['businesses']) }}</strong></a>
                        <a href="{{ route('admin.subscriptions') }}"><span>Sender Plus
                                tiers</span><strong>{{ number_format($metrics['senderPlus']) }}</strong></a>
                        <a href="{{ route('admin.subscriptions') }}"><span>Manager Pro
                                tiers</span><strong>{{ number_format($metrics['managerPro']) }}</strong></a>
                    </div>
                </section>
                <section class="ov3-panel">
                    <header class="ov3-panel-header">
                        <div>
                            <h2>Recent decisions</h2>
                            <p>Approved or rejected within {{ $periodLabel }}.</p>
                        </div>
                    </header>
                    <div class="ov3-decision-list">
                        @forelse($recentDecisions as $decision)
                            <a href="{{ route('admin.payments.show', $decision->id) }}"><span>Payment
                                    #{{ $decision->id }}</span><span
                                    class="ov3-chip {{ $decision->status === 'approved' ? 'ov3-success' : 'ov3-warning' }}">{{ ucfirst($decision->status) }}</span></a>
                        @empty<div class="ov3-empty">No decisions recorded during this period.</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>
        <p class="ov3-data-note">Figures come from stored Laravel records. Remittances mean saved records, not verified
            transfers. Saved likes can be removed by users. Current tier-record totals do not establish revenue or paid
            subscription validity.</p>
    </div>
    <div id="ov3-backdrop" class="ov3-backdrop" hidden></div>
    <aside id="ov3-drawer" class="ov3-drawer" role="dialog" aria-modal="true" aria-labelledby="ov3-drawer-title"
        aria-hidden="true">
        <div class="ov3-drawer-head">
            <div>
                <div class="ov3-kicker">QUICK INSPECT</div>
                <h2 id="ov3-drawer-title">Details</h2>
            </div><button id="ov3-close" class="ov3-close" type="button" aria-label="Close details">×</button>
        </div>
        <div class="ov3-drawer-body">
            <p id="ov3-drawer-description"></p>
            <p id="ov3-drawer-status"></p>
        </div>
        <div class="ov3-drawer-footer"><button id="ov3-dismiss" type="button">Close</button><a id="ov3-drawer-link"
                href="#">Open record →</a></div>
    </aside>
    @push('scripts')
        <script src="{{ asset('js/admin/pages/overview-v3.js') }}" defer></script>
    @endpush
@endsection
