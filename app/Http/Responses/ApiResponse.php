<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Every API response has the same envelope:
 *   { "success": bool, "code": "OK" | "SOME_ERROR_CODE", "message": string, "data": mixed|null }
 * Validation failures add "errors": { field: [messages] }.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        mixed $data = null,
        ?array $errors = null,
        ?array $errorCodes = null
    ): JsonResponse {
        $body = [
            'success' => false,
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        if ($errorCodes !== null) {
            $body['error_codes'] = $errorCodes;
        }

        return response()->json($body, $status);
    }
}
