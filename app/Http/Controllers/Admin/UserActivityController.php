<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Read-only, privacy-minimal activity indicators from existing server-side records. */
final class UserActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'period' => ['sometimes', Rule::in(['7', '30', '90', 'all'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['activity', 'remittances', 'likes', 'login'])],
        ]);
        $period = $validated['period'] ?? '30';
        $search = trim($validated['q'] ?? '');
        $sort = $validated['sort'] ?? 'activity';
        $since = $period === 'all' ? null : now('UTC')->subDays((int) $period);

        $remittances = DB::table('sender_remittances')
            ->select('owner_user_id')
            ->selectRaw('COUNT(*) as remittance_count')
            ->whereNull('deleted_at')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('owner_user_id');

        $likes = DB::table('daily_message_likes')
            ->select('user_id')
            ->selectRaw('COUNT(*) as like_count')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('user_id');

        $query = DB::table('users as u')
            ->leftJoinSub($remittances, 'r', 'r.owner_user_id', '=', 'u.id')
            ->leftJoinSub($likes, 'l', 'l.user_id', '=', 'u.id')
            ->select('u.id', 'u.name', 'u.member_no', 'u.last_login_at')
            ->selectRaw('COALESCE(r.remittance_count, 0) as remittance_count')
            ->selectRaw('COALESCE(l.like_count, 0) as like_count')
            ->selectRaw('(COALESCE(r.remittance_count, 0) + COALESCE(l.like_count, 0)) as recorded_actions')
            ->when($search !== '', function ($q) use ($search): void {
                $escaped = addcslashes($search, '%_\\');
                $q->where(function ($nested) use ($escaped): void {
                    $nested->where('u.name', 'like', '%'.$escaped.'%')
                        ->orWhere('u.member_no', 'like', '%'.$escaped.'%');
                });
            });

        if ($sort === 'remittances') {
            $query->orderByDesc('remittance_count');
        } elseif ($sort === 'likes') {
            $query->orderByDesc('like_count');
        } elseif ($sort === 'login') {
            $query->orderByDesc('u.last_login_at');
        } else {
            $query->orderByDesc('recorded_actions');
        }

        $users = $query->orderBy('u.id')->paginate(20)->withQueryString();

        $totals = [
            'recorded_remittances' => DB::table('sender_remittances')->whereNull('deleted_at')
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))->count(),
            'current_likes' => DB::table('daily_message_likes')
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))->count(),
            'users_with_actions' => DB::query()->fromSub(
                DB::table('users as u')
                    ->leftJoinSub(clone $remittances, 'r', 'r.owner_user_id', '=', 'u.id')
                    ->leftJoinSub(clone $likes, 'l', 'l.user_id', '=', 'u.id')
                    ->select('u.id')
                    ->where(fn ($q) => $q->whereNotNull('r.owner_user_id')->orWhereNotNull('l.user_id')),
                'engaged_users'
            )->count(),
            'recent_logins' => $since
                ? DB::table('users')->where('last_login_at', '>=', $since)->count()
                : DB::table('users')->whereNotNull('last_login_at')->count(),
        ];

        return view('admin.user-activity', compact('users', 'totals', 'period', 'search', 'sort'));
    }
}
