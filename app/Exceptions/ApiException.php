<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An expected, client-facing failure (limit reached, no business, and so on).
 * It renders itself in the standard API error shape and is not written to the error log.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly mixed $data = null,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->status, $this->data, $this->errors);
    }
}
