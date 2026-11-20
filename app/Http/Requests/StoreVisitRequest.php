<?php

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->hasAbility('visits:write')) {
            return false;
        }
        if (! is_scalar($this->input('branch_id'))) {
            return true;
        }
        $branch = Branch::find($this->input('branch_id'));
        return $branch === null || $this->user()->can('create', [\App\Models\Visit::class, $branch]);
    }
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'],
            'visit_date' => ['required', 'date_format:Y-m-d H:i:s'],
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'discount' => ['required', 'regex:/^\d{1,9}\.\d{3}$/'],
            'items' => ['required', 'array', 'between:1,20'],
            'items.*.service_code' => ['required', 'string', 'max:40'],
            'items.*.qty' => ['required', 'integer', 'between:1,1000'],
            'items.*.unit_price' => ['required', 'regex:/^\d{1,9}\.\d{3}$/'],
        ];
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            $key = (string) $this->header('Idempotency-Key');
            if (! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key)) {
                $validator->errors()->add('idempotency_key', 'A 16–128 character Idempotency-Key header is required.');
            }
            if ($validator->errors()->hasAny(['branch_id', 'doctor_id'])) {
                return;
            }
            if (! \App\Models\Doctor::query()->whereKey($this->input('doctor_id'))->where('branch_id', $this->input('branch_id'))->exists()) {
                $validator->errors()->add('doctor_id', 'The doctor must belong to the selected branch.');
            }
        }];
    }
}
