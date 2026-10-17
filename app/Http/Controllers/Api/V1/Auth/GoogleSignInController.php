<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleSignInRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\JsonResponse;

class GoogleSignInController extends Controller
{
    public function __invoke(GoogleSignInRequest $request, GoogleAuthService $google): JsonResponse
    {
        $data = $google->signIn($request->validated('id_token'), $request->validated('device_name'));

        return ApiResponse::success($data, 'Login successful.');
    }
}
