<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profile($request->user()));
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        // Email changes must use a separately verified, two-step workflow.
        // Never accept an email change through this ordinary profile endpoint.
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{6,32}$/'],
            'email' => ['prohibited'],
        ]);

        if (!$data) {
            throw ValidationException::withMessages(['profile' => ['Provide a profile field to update.']]);
        }

        $user = $request->user();
        DB::transaction(function () use ($user, $data): void {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('name', $data)) {
                $locked->name = trim($data['name']);
            }
            if (array_key_exists('whatsapp', $data)) {
                $locked->whatsapp = $data['whatsapp'] === null ? null : trim($data['whatsapp']);
            }
            $locked->save();
        });

        $user->refresh();
        $audit->record('account.profile_updated', $user, null, $user);

        return ApiResponse::success($this->profile($user), 'Profile saved.');
    }

    private function profile($user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'whatsapp' => $user->whatsapp,
            'member_no' => $user->member_no,
        ];
    }
}
