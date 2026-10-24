<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DailyMessage;
use App\Support\DailyMessageCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DailyMessageAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'category' => ['sometimes', Rule::in(DailyMessageCategories::codes())],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = DailyMessage::query()->orderBy('sort_order')->orderBy('id');
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $q = $filters['q'];
            $query->where(fn ($builder) => $builder->where('code', 'like', '%'.$q.'%')
                ->orWhere('message_bn', 'like', '%'.$q.'%')
                ->orWhere('message_en', 'like', '%'.$q.'%'));
        }

        return ApiResponse::success($query->paginate($filters['per_page'] ?? 30));
    }

    public function summary(): JsonResponse
    {
        return ApiResponse::success([
            'total' => DailyMessage::count(),
            'published' => DailyMessage::where('status', 'published')->count(),
            'draft' => DailyMessage::where('status', 'draft')->count(),
        ]);
    }

    private function rules(?DailyMessage $message = null, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$presence, 'string', 'max:64', Rule::unique('daily_messages', 'code')->ignore($message?->id)],
            'message_bn' => [$presence, 'string', 'max:1000'],
            'message_en' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => [$presence, Rule::in(['draft', 'published'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'category' => ['sometimes', 'nullable', Rule::in(DailyMessageCategories::codes())],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        return ApiResponse::success(DailyMessage::create($data), 'Message created.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $message = DailyMessage::where('uuid', $id)->firstOrFail();
        $data = $request->validate($this->rules($message, true));
        if ($data === []) {
            throw ValidationException::withMessages(['fields' => 'At least one field is required.']);
        }
        $message->update($data);
        return ApiResponse::success($message->refresh(), 'Message updated.');
    }

    public function destroy(string $id): JsonResponse
    {
        DailyMessage::where('uuid', $id)->firstOrFail()->delete();
        return ApiResponse::success(null, 'Message deleted.');
    }

    public function preview(Request $request): JsonResponse
    {
        $rows = $this->parseCsv($request);
        $existing = DailyMessage::query()->whereIn('code', array_keys($rows))
            ->pluck('code')->all();
        $existingCodes = array_fill_keys($existing, true);

        return ApiResponse::success([
            'total' => count($rows),
            'create' => count($rows) - count($existing),
            'update' => count($existing),
            'rows' => array_values(array_map(fn (array $row) => [
                ...$row,
                'action' => isset($existingCodes[$row['code']]) ? 'update' : 'create',
            ], $rows)),
        ]);
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);

        $ids = $data['ids'];
        $updated = DB::transaction(function () use ($ids, $data): int {
            $query = DailyMessage::query()->whereIn('uuid', $ids);
            if ($query->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'ids' => 'One or more messages do not exist.',
                ]);
            }

            return $query->update(['status' => $data['status']]);
        });

        return ApiResponse::success(['updated' => $updated, 'status' => $data['status']]);
    }

    public function import(Request $request): JsonResponse
    {
        $rows = $this->parseCsv($request);
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $message = DailyMessage::firstOrNew(['code' => $row['code']]);
                if (!$message->exists) {
                    $message->uuid = (string) Str::uuid();
                }
                $message->fill($row)->save();
            }
        });

        return ApiResponse::success(['imported' => count($rows)], 'CSV imported.');
    }

    private function parseCsv(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Could not read CSV.']);
        }
        try {
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw ValidationException::withMessages(['file' => 'CSV is empty.']);
            }
            $headers = array_map(fn ($s) => trim((string) $s), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            if (!in_array($headers, [['code', 'message_bn', 'message_en', 'status'], ['code', 'message_bn', 'message_en', 'status', 'category']], true)) {
                throw ValidationException::withMessages(['file' => 'Expected columns: code,message_bn,message_en,status[,category]']);
            }

            $rows = [];
            $line = 1;
            while (($values = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > 1001) {
                    throw ValidationException::withMessages(['file' => 'Maximum 1000 rows per upload.']);
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => "Invalid column count on row {$line}."]);
                }
                $row = array_combine($headers, array_map('trim', $values));
                $validator = validator($row, [
                    'code' => ['required', 'string', 'max:64'],
                    'message_bn' => ['required', 'string', 'max:1000'],
                    'message_en' => ['nullable', 'string', 'max:1000'],
                    'status' => ['required', Rule::in(['draft', 'published'])],
                    'category' => ['sometimes', 'nullable', Rule::in(DailyMessageCategories::codes())],
                ]);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(['file' => "Invalid data on row {$line}."]);
                }
                if (isset($rows[$row['code']])) {
                    throw ValidationException::withMessages(['file' => "Duplicate code on row {$line}."]);
                }
                $rows[$row['code']] = $row;
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['code', 'message_bn', 'message_en', 'status', 'category']);
            DailyMessage::orderBy('id')->chunk(200, function ($messages) use ($output): void {
                foreach ($messages as $message) {
                    $cells = [$message->code, $message->message_bn, $message->message_en, $message->status, $message->category];
                    fputcsv($output, array_map(function ($value) {
                        $value = (string) ($value ?? '');
                        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
                    }, $cells));
                }
            });
            fclose($output);
        }, 'daily-messages.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
