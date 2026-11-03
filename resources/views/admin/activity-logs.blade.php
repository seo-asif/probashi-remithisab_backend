@extends('admin.layouts.app')
@section('title', 'Activity Logs · Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/activity-logs.css') }}">
@endpush
@section('content')
<div class="activity-head"><div class="activity-kicker">Platform operations</div><h1>Activity Logs</h1><p class="activity-sub">Read-only history of recorded system actions. Sensitive metadata and technical request details are not displayed.</p></div>
<section class="activity-panel" aria-label="Activity history">
    <div class="activity-panel-head"><div><h2>Audit history</h2><div class="activity-muted">Latest activity first · Fiji time (UTC+12)</div></div><span class="activity-muted">{{ number_format($logs->total()) }} matching</span></div>
    @php
        $actionLabels = [
            'admin.mfa_verified' => 'Two-step verification completed',
            'admin.password_verified' => 'Administrator password verified',
            'plan_payment.submitted' => 'Payment request submitted',
            'plan_payment.approved' => 'Payment request approved',
            'plan_payment.rejected' => 'Payment request rejected',
            'work_day.created' => 'Workday recorded',
            'work_day.updated' => 'Workday record updated',
            'work_day.deleted' => 'Workday record deleted',
        ];
    @endphp
    <form class="activity-filters" method="GET" action="{{ route('admin.activity-logs') }}">
        <input type="search" name="q" aria-label="Search activity" maxlength="100" placeholder="Action, actor, or numeric ID" value="{{ $search }}">
        <select name="action" aria-label="Filter action"><option value="">All actions</option>@foreach($actions as $item)<option value="{{ $item }}" @selected($action === $item)>{{ $actionLabels[$item] ?? ucfirst(str_replace(['_', '.'], [' ', ' · '], $item)) }}</option>@endforeach</select>
        <label class="activity-muted">From (Fiji date) <input type="date" name="from" aria-label="From date" value="{{ $from }}"></label>
        <label class="activity-muted">To (Fiji date) <input type="date" name="to" aria-label="To date" value="{{ $to }}"></label>
        <button type="submit">Apply filters</button><a class="activity-reset" href="{{ route('admin.activity-logs') }}">Clear</a>
    </form>
    <div class="activity-scroll"><table class="activity-table"><thead><tr><th>Time (Fiji)</th><th>Actor</th><th>Action</th><th>Target</th><th>Business</th></tr></thead><tbody>
    @forelse($logs as $log)
        <tr>
            <td><span title="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->timezone('Pacific/Fiji')->format('d M Y, H:i') ?? 'Unknown' }}</span></td>
            <td><div class="actor">@if($log->actor)<a href="{{ route('admin.users.show', $log->actor->id) }}">{{ $log->actor->name }}</a>@else System / deleted user @endif</div>@if($log->actor)<div class="second">{{ $log->actor->email }}</div>@endif</td>
            <td>@php
                $actionLabel = $actionLabels[$log->action] ?? ucfirst(str_replace(['_', '.'], [' ', ' · '], $log->action));
                // Describe only fields actually recorded in the audit log.
                $actorName = $log->actor?->name;
                $targetId = $log->target_id;
                $descriptions = [
                    'admin.mfa_verified' => 'Administrator completed two-step verification.',
                    'admin.password_verified' => 'Administrator password verification succeeded.',
                    'plan_payment.submitted' => $actorName && $targetId
                        ? $actorName.' submitted payment request #'.$targetId.'.'
                        : 'A payment request was submitted.',
                    'plan_payment.approved' => $targetId
                        ? 'Payment request #'.$targetId.' was approved.'
                        : 'A payment request was approved.',
                    'plan_payment.rejected' => $targetId
                        ? 'Payment request #'.$targetId.' was rejected.'
                        : 'A payment request was rejected.',
                    'work_day.created' => $actorName && $targetId
                        ? $actorName.' created workday record #'.$targetId.'.'
                        : 'A workday record was created.',
                    'work_day.updated' => $actorName && $targetId
                        ? $actorName.' updated workday record #'.$targetId.'.'
                        : 'A workday record was updated.',
                    'work_day.deleted' => $actorName && $targetId
                        ? $actorName.' deleted workday record #'.$targetId.'.'
                        : 'A workday record was deleted.',
                ];
                $actionDescription = $descriptions[$log->action] ?? null;
            @endphp
            <span class="action" title="{{ $log->action }}">{{ $actionLabel }}</span>
            @if($actionDescription)<span class="action-description">{{ $actionDescription }}</span>@endif
            <span class="action-code" title="Audit action code">{{ $log->action }}</span></td>
            <td>@if($log->target_type && $log->target_id)@if($log->target_type === 'PlanPaymentRequest')<a href="{{ route('admin.payments.show', $log->target_id) }}">Payment #{{ $log->target_id }}</a>@elseif($log->target_type === 'User')<a href="{{ route('admin.users.show', $log->target_id) }}">User #{{ $log->target_id }}</a>@elseif($log->target_type === 'Business')<a href="{{ route('admin.businesses.show', $log->target_id) }}">Business #{{ $log->target_id }}</a>@else {{ $log->target_type }} #{{ $log->target_id }} @endif @else — @endif</td>
            <td>@if($log->business_id)<a href="{{ route('admin.businesses.show', $log->business_id) }}">#{{ $log->business_id }}</a>@else — @endif</td>
        </tr>
    @empty
        <tr><td colspan="5" class="activity-empty">No activity matches your filters.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="activity-foot"><span>{{ $logs->total() ? $logs->firstItem().'-'.$logs->lastItem().' of '.$logs->total() : '0 results' }}</span><div class="activity-pages">@if(!$logs->onFirstPage())<a rel="prev" href="{{ $logs->previousPageUrl() }}">Previous</a>@endif<span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>@if($logs->hasMorePages())<a rel="next" href="{{ $logs->nextPageUrl() }}">Next</a>@endif</div></div>
</section>
@endsection
