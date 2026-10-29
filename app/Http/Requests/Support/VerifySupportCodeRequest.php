<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class VerifySupportCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'regex:/^\d{6}$/',
            ],
        ];
    }
}