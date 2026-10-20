<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Writes security and business events.
 *
 * A failure to log never breaks the request.
 * Authentication metadata is restricted by event.
 */
class AuditLogger
{
    public function record(
        string $action,
        ?User $actor = null,
        ?int $businessId = null,
        ?Model $target = null,
        array $metadata = []
    ): void {
        try {
            $safeMetadata = $this->validatedMetadata($action, $metadata);

            $request = request();

            AuditLog::create([
                'business_id'   => $businessId,
                'actor_user_id' => $actor?->id,
                'action'        => $action,
                'target_type'   => $target ? class_basename($target) : null,
                'target_id'     => $target?->getKey(),
                'metadata'      => $safeMetadata ?: null,
                'ip'            => $request?->ip(),
                'user_agent'    => $request
                    ? Str::limit((string) $request->userAgent(), 255, '')
                    : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function validatedMetadata(string $action, array $metadata): array
    {
        if ($action !== 'auth.login_failed') {
            return [];
        }

        if (!array_key_exists('email_hash', $metadata)) {
            throw new InvalidArgumentException(
                'Missing authentication audit metadata: email_hash'
            );
        }

        $hash = $metadata['email_hash'];

        if (
            !is_string($hash)
            || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid authentication audit metadata: email_hash'
            );
        }

        return ['email_hash' => $hash];
    }
}
