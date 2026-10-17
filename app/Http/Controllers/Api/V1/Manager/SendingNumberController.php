<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Resources\Manager\SendingNumberResource;
use App\Http\Responses\ApiResponse;
use App\Models\SendingNumber;
use App\Support\Manager\ManagerValidation;
use App\Support\Records\RecordId;
use App\Support\Validation\Reject;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The operator's own wallet numbers used to pay customers (app: Settings > Sending numbers).
 * GET /sending-numbers, POST /sending-numbers, PATCH /sending-numbers/{id}, DELETE /sending-numbers/{id}
 * Phase 4 limits how many numbers each plan may save.
 */
class SendingNumberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $numbers = SendingNumber::query()->orderBy('id')->get();

        return ApiResponse::success(['items' => $numbers->map(fn ($n) => SendingNumberResource::make($n)->resolve($request))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($existing = RecordId::existing(SendingNumber::class, $request->input('id'))) {
            return ApiResponse::success(SendingNumberResource::make($existing)->resolve($request), 'Already saved.');
        }

        $number = SendingNumber::make([...$this->validated($request, true), 'uuid' => RecordId::make($request->input('id'))]);

        $this->saveUnique($number);

        return ApiResponse::success(SendingNumberResource::make($number->refresh())->resolve($request), 'Sending number saved.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $number = SendingNumber::findByUuidOrFail($id);
        $number->fill($this->validated($request, false));

        $this->saveUnique($number);

        return ApiResponse::success(SendingNumberResource::make($number->refresh())->resolve($request), 'Sending number updated.');
    }

    public function destroy(string $id): JsonResponse
    {
        SendingNumber::findByUuidOrFail($id)->delete();

        return ApiResponse::success(null, 'Sending number removed.');
    }

    private function validated(Request $request, bool $creating): array
    {
        if ($request->has('number')) {
            $request->merge(['number' => ManagerValidation::cleanWallet($request->input('number'))]);
        }

        return $request->validate([
            'number' => [$creating ? 'required' : 'sometimes', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && ($message = ManagerValidation::sendingNumberError($value)) !== null) {
                    $fail(Reject::msg('sending_number_invalid', $message));
                }
            }],
            'label'  => ['sometimes', 'nullable', 'string', 'max:40', new \App\Rules\PlainText()],
        ], ['number.required' => Reject::msg('sending_number_required', 'Enter a sending number.')]);
    }

    private function saveUnique(SendingNumber $number): void
    {
        $duplicate = SendingNumber::query()
            ->where('number', $number->number)
            ->when($number->exists, fn ($q) => $q->whereKeyNot($number->id))
            ->exists();

        if ($duplicate) {
            Reject::fail(['number' => ['sending_number_duplicate', 'This sending number is already saved.']]);
        }

        try {
            $number->save();
        } catch (QueryException $e) {
            Reject::fail(['number' => ['sending_number_duplicate', 'This sending number is already saved.']]);
        }
    }
}
