<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DailyMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyMessageController extends Controller
{
    private function published(): array
    {
        return DailyMessage::query()
            ->where('status', 'published')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['uuid', 'code', 'message_bn', 'message_en', 'category'])
            ->map(fn (DailyMessage $message) => [
                'id' => $message->uuid,
                'code' => $message->code,
                'category' => $message->category,
                'bn' => $message->message_bn,
                'en' => $message->message_en,
            ])->all();
    }

    public function sync(Request $request): JsonResponse
    {
        $messages = $this->published();
        $version = hash('sha256', json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $unchanged = hash_equals($version, (string) $request->query('version', ''));

        return ApiResponse::success([
            'version' => $version,
            'sync_interval_days' => 7,
            'unchanged' => $unchanged,
            'messages' => $unchanged ? null : $messages,
        ]);
    }

    public function today(): JsonResponse
    {
        $messages = $this->published();
        if ($messages === []) {
            return ApiResponse::success(['message' => null]);
        }

        $date = now('UTC')->toDateString();
        $index = hexdec(substr(hash('sha256', $date), 0, 7)) % count($messages);

        return ApiResponse::success(['date' => $date, 'message' => $messages[$index]]);
    }
}
