<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Account\SenderBackupPreviewService;
use App\Services\Account\SenderBackupValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SenderBackupPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        SenderBackupValidator $validator,
        SenderBackupPreviewService $preview,
    ): JsonResponse {
        $validated = $validator->validate($request->json()->all());
        $response = ApiResponse::success(
            $preview->preview($validated),
            'Sender backup preview completed. No records were changed.'
        );
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
