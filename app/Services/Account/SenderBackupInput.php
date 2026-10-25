<?php

namespace App\Services\Account;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Bounded JSON reader shared by Sender backup preview and restore.
 *
 * Read no more than MAX_BYTES + 1 bytes even if the request omits or
 * misreports Content-Length. Validate before building the full JSON tree.
 * Infrastructure must also enforce an upload/body limit at the proxy.
 */
final class SenderBackupInput
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private SenderBackupValidator $validator)
    {
    }

    public function parse(Request $request): array
    {
        if (!$request->isJson()) {
            throw new ApiException(
                'BACKUP_JSON_REQUIRED',
                'Send a JSON backup with Content-Type: application/json.',
                415
            );
        }

        $length = $request->headers->get('Content-Length');
        if (is_string($length) && ctype_digit($length) && (float) $length > self::MAX_BYTES) {
            $this->tooLarge();
        }

        // Using a resource allows bounded reads for HTTP requests even when
        // Content-Length is missing (chunked transfer) or forged.
        $stream = $request->getContent(true);
        $raw = stream_get_contents($stream, self::MAX_BYTES + 1);
        if ($raw === false) {
            throw new ApiException('BACKUP_READ_FAILED', 'Could not read the backup JSON.', 400);
        }
        if (strlen($raw) > self::MAX_BYTES) {
            $this->tooLarge();
        }

        try {
            $payload = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'backup' => ['The backup file must contain valid JSON.'],
            ]);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages([
                'backup' => ['The backup must be a JSON object.'],
            ]);
        }

        return $this->validator->validate($payload);
    }

    private function tooLarge(): never
    {
        throw new ApiException(
            'BACKUP_TOO_LARGE',
            'The Sender backup exceeds the 5 MiB JSON size limit.',
            413,
            ['max_bytes' => self::MAX_BYTES]
        );
    }
}
