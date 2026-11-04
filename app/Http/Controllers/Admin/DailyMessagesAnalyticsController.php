<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DailyMessage;
use App\Support\DailyMessageCategories;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DailyMessagesAnalyticsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'category' => ['sometimes', Rule::in(array_merge(['all'], DailyMessageCategories::codes()))],
            'period' => ['sometimes', Rule::in(['7', '30', '90', 'all'])],
        ]);

        $category = $filters['category'] ?? 'all';
        $period = $filters['period'] ?? '30';
        $since = $period === 'all' ? null : now()->subDays((int) $period - 1)->startOfDay();

        // Keep the time filter on the JOIN so zero-like messages and categories remain visible.
        $top = DailyMessage::query()
            ->leftJoin('daily_message_likes as likes', function ($join) use ($since): void {
                $join->on('likes.daily_message_id', '=', 'daily_messages.id');
                if ($since !== null) {
                    $join->where('likes.created_at', '>=', $since);
                }
            })
            ->select('daily_messages.id', 'daily_messages.code', 'daily_messages.category',
                'daily_messages.message_bn', 'daily_messages.status')
            ->selectRaw('COUNT(likes.id) as likes_count')
            ->groupBy('daily_messages.id', 'daily_messages.code', 'daily_messages.category',
                'daily_messages.message_bn', 'daily_messages.status');

        if ($category !== 'all') {
            $top->where('daily_messages.category', $category);
        }

        $categoryStats = DailyMessage::query()
            ->leftJoin('daily_message_likes as likes', function ($join) use ($since): void {
                $join->on('likes.daily_message_id', '=', 'daily_messages.id');
                if ($since !== null) {
                    $join->where('likes.created_at', '>=', $since);
                }
            })
            ->select('daily_messages.category')
            ->selectRaw('COUNT(DISTINCT daily_messages.id) as messages_count')
            ->selectRaw('COUNT(likes.id) as likes_count')
            ->selectRaw('COUNT(DISTINCT likes.user_id) as unique_likers')
            ->groupBy('daily_messages.category')
            ->orderByDesc('likes_count')
            ->get();

        $likes = DB::table('daily_message_likes as likes')
            ->join('daily_messages as messages', 'messages.id', '=', 'likes.daily_message_id');
        if ($since !== null) {
            $likes->where('likes.created_at', '>=', $since);
        }
        if ($category !== 'all') {
            $likes->where('messages.category', $category);
        }

        $users = DB::table('daily_message_likes as likes')
            ->join('daily_messages as messages', 'messages.id', '=', 'likes.daily_message_id')
            ->join('users', 'users.id', '=', 'likes.user_id')
            ->select('users.id', 'users.name', 'users.member_no')
            ->selectRaw('COUNT(likes.id) as likes_count')
            ->selectRaw('COUNT(DISTINCT DATE(likes.created_at)) as active_days')
            ->selectRaw('MAX(likes.created_at) as last_liked_at')
            ->groupBy('users.id', 'users.name', 'users.member_no')
            ->orderByDesc('likes_count')
            ->orderByDesc('last_liked_at')
            ->limit(30);
        if ($since !== null) {
            $users->where('likes.created_at', '>=', $since);
        }
        if ($category !== 'all') {
            $users->where('messages.category', $category);
        }

        // This is the count of currently stored likes, not a log of all past like actions.
        return view('admin.daily-message-analytics', [
            'totalLikes' => (clone $likes)->count(),
            'uniqueLikers' => (clone $likes)->distinct()->count('likes.user_id'),
            'likedMessages' => (clone $likes)->distinct()->count('likes.daily_message_id'),
            'publishedMessages' => DailyMessage::query()->where('status', 'published')->count(),
            'topMessages' => $top->orderByDesc('likes_count')->orderBy('daily_messages.code')->limit(50)->get(),
            'categoryStats' => $categoryStats,
            'activeUsers' => $users->get(),
            'categories' => DailyMessageCategories::LABELS,
            'category' => $category,
            'period' => $period,
        ]);
    }
}
