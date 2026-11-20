<?php

namespace App\Http\Requests;

use App\UserRole;

class StoreExportRequest extends IndexVisitRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAbility('exports:write') && in_array($this->user()->role, [UserRole::Admin,UserRole::ClinicAdmin], true);
    }
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['cursor'],$rules['per_page']);
        return $rules;
    }
}
