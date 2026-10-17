<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutAllController extends Controller
{
    /** Logs out every device. */
    public function __invoke(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        $user->tokens()->delete();

        $audit->record('auth.logout_all', $user, null, $user);

        return ApiResponse::success(null, 'Logged out on all devices.');
    }
}
