<?php

namespace App\Services\Account;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;

/**
 * Keep Sender backup exports within the JSON byte limit that Preview and
 * Restore can accept. Never hand users a backup that this API cannot import.
 *
 * Byte length is checked after JSON serialization because Unicode, escaping,
 * and metadata affect the final download size.
 */
final class SenderBackupOutput
{
    public function response(array $backup): JsonResponse
    {
        $response = response()->json($backup, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="probashi-sender-backup.json"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if (strlen((string) $response->getContent()) > SenderBackupInput::MAX_BYTES) {
            throw new ApiException(
                'BACKUP_TOO_LARGE',
                'Your Sender backup exceeds the 5 MiB restore limit. No backup file was generated.',
                413,
                ['max_bytes' => SenderBackupInput::MAX_BYTES],
            );
        }

        return $response;
    }
}
