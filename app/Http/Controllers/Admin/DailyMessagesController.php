<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DailyMessage;
use App\Support\DailyMessageCategories;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DailyMessagesController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'published'])],
            'category' => ['sometimes', Rule::in(array_merge(['all'], DailyMessageCategories::codes()))],
        ]);

        $search = trim($filters['q'] ?? '');
        $status = $filters['status'] ?? 'all';
        $category = $filters['category'] ?? 'all';
        $query = DailyMessage::query()
            ->select('daily_messages.*')
            ->selectSub(
                DB::table('daily_message_likes')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('daily_message_likes.daily_message_id', 'daily_messages.id'),
                'likes_count'
            )
            ->orderBy('sort_order')->orderBy('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($category !== 'all') {
            $query->where('category', $category);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('code', 'like', '%'.$search.'%')
                    ->orWhere('message_bn', 'like', '%'.$search.'%')
                    ->orWhere('message_en', 'like', '%'.$search.'%');
            });
        }

        return view('admin.daily-messages', [
            'messages' => $query->paginate(20)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'category' => $category,
            'categories' => DailyMessageCategories::LABELS,
            'counts' => [
                'total' => DailyMessage::query()->count(),
                'published' => DailyMessage::query()->where('status', 'published')->count(),
                'draft' => DailyMessage::query()->where('status', 'draft')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.daily-message-form', [
            'message' => null,
            'categories' => DailyMessageCategories::LABELS,
        ]);
    }

    public function edit(DailyMessage $message): View
    {
        return view('admin.daily-message-form', [
            'message' => $message,
            'categories' => DailyMessageCategories::LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        DB::transaction(function () use ($request, $data): void {
            $message = DailyMessage::query()->create($data);
            $this->audit($request, 'created', $message);
        });

        return redirect()->route('admin.daily-messages')->with('notice', 'Message created successfully.');
    }

    public function update(Request $request, DailyMessage $message): RedirectResponse
    {
        $data = $request->validate($this->rules($message));
        DB::transaction(function () use ($request, $message, $data): void {
            $message->fill($data)->save();
            $this->audit($request, 'updated', $message);
        });

        return redirect()->route('admin.daily-messages')->with('notice', 'Message updated successfully.');
    }

    public function status(Request $request, DailyMessage $message): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'published'])]]);
        DB::transaction(function () use ($request, $message, $data): void {
            $message->forceFill(['status' => $data['status']])->save();
            $this->audit($request, 'updated', $message);
        });

        return redirect()->route('admin.daily-messages')->with('notice', 'Publication status updated.');
    }

    private function rules(?DailyMessage $message = null): array
    {
        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('daily_messages', 'code')->ignore($message?->id)],
            'message_bn' => ['required', 'string', 'max:1000'],
            'message_en' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'category' => ['nullable', Rule::in(DailyMessageCategories::codes())],
        ];
    }

    private function audit(Request $request, string $action, DailyMessage $message): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'action' => 'platform.daily_message.'.$action,
            'target_type' => 'daily_message',
            'target_id' => $message->id,
            'metadata' => ['code' => $message->code],
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
