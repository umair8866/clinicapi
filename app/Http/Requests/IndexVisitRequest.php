<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', \App\Models\Visit::class) ?? false;
    }
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'doctor_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1000'],
        ];
    }
}
