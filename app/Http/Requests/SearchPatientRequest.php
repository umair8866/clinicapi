<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchPatientRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', \App\Models\Patient::class) ?? false;
    }
    public function rules(): array
    {
        return ['search' => ['required', 'string', 'min:2', 'max:150']];
    }
}
