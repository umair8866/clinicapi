<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class TopPatientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAbility('reports:read') && $this->user()->role === UserRole::Admin;
    }
    public function rules(): array
    {
        return ['year' => ['required', 'integer', 'between:2020,2100']];
    }
}
