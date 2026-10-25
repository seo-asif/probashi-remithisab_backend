<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Account\SenderBackupRestoreService;
use App\Services\Account\SenderBackupValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SenderBackupRestoreController extends Controller
{
    public function __invoke(
        Request $request,
        SenderBackupValidator $validator,
        SenderBackupRestoreService $restore,
    ): JsonResponse {
        $validated = $validator->validate($request->json()->all());

        $response = ApiResponse::success(
            $restore->restore($validated),
            'Sender backup merged. Existing records were not changed.'
        );
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
