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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DailyMessagesToolsController extends Controller
{
    public function index(Request $request): View
    {
        $preview = $request->session()->get('daily_messages.import_preview');
        if (! is_array($preview) || ($preview['user_id'] ?? null) !== $request->user()->id
            || time() - (int) ($preview['created_at'] ?? 0) > 600) {
            $request->session()->forget('daily_messages.import_preview');
            $preview = null;
        }

        return view('admin.daily-message-tools', ['preview' => $preview]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $rows = $this->parseCsv($request);
        $existing = DailyMessage::query()->whereIn('code', array_keys($rows))->pluck('code')->flip();
        $preview = [
            'user_id' => $request->user()->id,
            'created_at' => time(),
            'token' => Str::random(40),
            'rows' => $rows,
            'create' => count($rows) - $existing->count(),
            'update' => $existing->count(),
        ];
        $request->session()->put('daily_messages.import_preview', $preview);
        return redirect()->route('admin.daily-messages.tools');
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);
        $preview = $request->session()->get('daily_messages.import_preview');
        if (! is_array($preview) || ($preview['user_id'] ?? null) !== $request->user()->id
            || time() - (int) ($preview['created_at'] ?? 0) > 600
            || ! hash_equals((string) ($preview['token'] ?? ''), $data['preview_token'])
            || ! isset($preview['rows']) || ! is_array($preview['rows']) || $preview['rows'] === []) {
            $request->session()->forget('daily_messages.import_preview');
            throw ValidationException::withMessages(['file' => 'Import preview has expired. Upload the CSV again.']);
        }

        // Invalidate the preview before a commit attempt so browser resubmissions cannot replay it.
        $request->session()->forget('daily_messages.import_preview');
        $rows = $preview['rows'];
        DB::transaction(function () use ($request, $rows): void {
            foreach ($rows as $row) {
                $message = DailyMessage::query()->firstOrNew(['code' => $row['code']]);
                $message->fill($row)->save();
            }
            $this->audit($request, 'imported', ['count' => count($rows)]);
        });

        return redirect()->route('admin.daily-messages')->with('notice', count($rows).' messages imported.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
        $ids = array_map('intval', $data['ids']);
        DB::transaction(function () use ($request, $data, $ids): void {
            $messages = DailyMessage::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($messages->count() !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'One or more selected messages no longer exist.']);
            }
            DailyMessage::query()->whereIn('id', $ids)->update(['status' => $data['status']]);
            $this->audit($request, 'bulk_status', ['count' => count($ids), 'status' => $data['status']]);
        });
        return redirect()->route('admin.daily-messages')->with('notice', count($ids).' messages updated.');
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['code', 'message_bn', 'message_en', 'status', 'category']);
            DailyMessage::query()->orderBy('id')->chunkById(200, function ($messages) use ($output): void {
                foreach ($messages as $message) {
                    $cells = [$message->code, $message->message_bn, $message->message_en, $message->status, $message->category];
                    fputcsv($output, array_map(static function ($value): string {
                        $value = (string) ($value ?? '');
                        return preg_match('/^\s*[=+@\-]/u', $value) ? "'".$value : $value;
                    }, $cells));
                }
            });
            fclose($output);
        }, 'daily-messages.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function parseCsv(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Unable to read the uploaded CSV.']);
        }
        try {
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw ValidationException::withMessages(['file' => 'CSV is empty.']);
            }
            $headers = array_map(static fn ($value): string => trim((string) $value), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            if (! in_array($headers, [
                ['code', 'message_bn', 'message_en', 'status'],
                ['code', 'message_bn', 'message_en', 'status', 'category'],
            ], true)) {
                throw ValidationException::withMessages(['file' => 'CSV columns must be code,message_bn,message_en,status[,category].']);
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > 1001) {
                    throw ValidationException::withMessages(['file' => 'A CSV can contain at most 1000 messages.']);
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => "Invalid column count on row {$line}."]);
                }
                $row = array_combine($headers, array_map('trim', $values));
                foreach ($row as $value) {
                    if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                        throw ValidationException::withMessages(['file' => "Invalid text on row {$line}."]);
                    }
                }
                $validator = validator($row, [
                    'code' => ['required', 'string', 'max:64'],
                    'message_bn' => ['required', 'string', 'max:1000'],
                    'message_en' => ['nullable', 'string', 'max:1000'],
                    'status' => ['required', Rule::in(['draft', 'published'])],
                    'category' => ['sometimes', 'nullable', Rule::in(DailyMessageCategories::codes())],
                ]);
                if ($validator->fails() || isset($rows[$row['code'] ?? ''])) {
                    throw ValidationException::withMessages(['file' => "Invalid or duplicate message on row {$line}."]);
                }
                $rows[$row['code']] = $validator->validated();
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'CSV contains no messages.']);
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function audit(Request $request, string $action, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'action' => 'platform.daily_message.'.$action,
            'target_type' => 'daily_message',
            'target_id' => null,
            'metadata' => $metadata,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
