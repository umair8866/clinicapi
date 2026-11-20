<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Carbon\CarbonImmutable;

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
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->any()) {
                return;
            }
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('from'));
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('to'));
            if ($end->greaterThanOrEqualTo($start->addYearNoOverflow())) {
                $validator->errors()->add('to', 'The report range cannot exceed one calendar year.');
            }
        }];
    }
}
