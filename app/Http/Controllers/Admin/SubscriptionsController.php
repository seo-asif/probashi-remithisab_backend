<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManagerSubscription;
use App\Models\SenderSubscription;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class SubscriptionsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'in:sender,manager'],
            'q' => ['nullable', 'string', 'max:100'],
            'access' => ['sometimes', 'in:all,active,expired,free'],
        ]);
        $type = $validated['type'] ?? 'sender';
        $search = trim($validated['q'] ?? '');
        $access = $validated['access'] ?? 'all';
        $now = now();

        if ($type === 'sender') {
            $query = SenderSubscription::query()->with('user:id,name,email,member_no');
            if ($search !== '') {
                $query->whereHas('user', fn ($q) => $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
                }));
            }
        } else {
            $query = ManagerSubscription::query()->with('business:id,name');
            if ($search !== '') {
                $query->whereHas('business', fn ($q) => $q->where('name', 'like', '%'.$search.'%'));
            }
        }

        // Paid access requires the paid tier AND a currently valid interval.
        // Absence of an end date does not imply lifetime paid access.
        $paidTier = $type === 'sender' ? 'plus' : 'pro';
        $active = static fn ($q) => $q->where('tier', $paidTier)
            ->whereNotNull('access_starts_at')->where('access_starts_at', '<=', $now)
            ->whereNotNull('access_ends_at')->where('access_ends_at', '>', $now);
        if ($access === 'active') {
            $active($query);
        } elseif ($access === 'free') {
            $query->where('tier', 'free');
        } elseif ($access === 'expired') {
            $query->where('tier', $paidTier)->where(function ($q) use ($now) {
                $q->whereNull('access_ends_at')->orWhere('access_ends_at', '<=', $now);
            });
        }

        return view('admin.subscriptions', [
            'subscriptions' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'type' => $type,
            'search' => $search,
            'access' => $access,
            'paidTier' => $paidTier,
            'currentTime' => $now,
        ]);
    }
}