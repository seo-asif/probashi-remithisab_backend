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
        ]);
        $category = $filters['category'] ?? 'all';
        $top = DailyMessage::query()
            ->leftJoin('daily_message_likes as likes', 'likes.daily_message_id', '=', 'daily_messages.id')
            ->select('daily_messages.id', 'daily_messages.code', 'daily_messages.category', 'daily_messages.message_bn')
            ->selectRaw('COUNT(likes.id) as likes_count')
            ->groupBy('daily_messages.id', 'daily_messages.code', 'daily_messages.category', 'daily_messages.message_bn');
        if ($category !== 'all') {
            $top->where('daily_messages.category', $category);
        }
        $categoryStats = DailyMessage::query()
            ->leftJoin('daily_message_likes as likes', 'likes.daily_message_id', '=', 'daily_messages.id')
            ->select('daily_messages.category')
            ->selectRaw('COUNT(DISTINCT daily_messages.id) as messages_count')
            ->selectRaw('COUNT(likes.id) as likes_count')
            ->groupBy('daily_messages.category')
            ->get();

        return view('admin.daily-message-analytics', [
            'totalLikes' => DB::table('daily_message_likes')->count(),
            'uniqueLikers' => DB::table('daily_message_likes')->distinct()->count('user_id'),
            'likedMessages' => DB::table('daily_message_likes')->distinct()->count('daily_message_id'),
            'topMessages' => $top->orderByDesc('likes_count')->orderBy('daily_messages.code')->limit(20)->get(),
            'categoryStats' => $categoryStats,
            'categories' => DailyMessageCategories::LABELS,
            'category' => $category,
        ]);
    }
}
