<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Writes security and business events. A failure to log never breaks the request. Never pass secrets in $metadata. */
class AuditLogger
{
    public function record(string $action, ?User $actor = null, ?int $businessId = null, ?Model $target = null, array $metadata = []): void
    {
        try {
            $request = request();

            AuditLog::create([
                'business_id'   => $businessId,
                'actor_user_id' => $actor?->id,
                'action'        => $action,
                'target_type'   => $target ? class_basename($target) : null,
                'target_id'     => $target?->getKey(),
                'metadata'      => $metadata ?: null,
                'ip'            => $request?->ip(),
                'user_agent'    => $request ? Str::limit((string) $request->userAgent(), 255, '') : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
