<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevenueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAbility('reports:read')
            && in_array($this->user()->role, [UserRole::Admin, UserRole::ClinicAdmin], true);
    }
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['required', Rule::in(['branch', 'doctor', 'month'])],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
