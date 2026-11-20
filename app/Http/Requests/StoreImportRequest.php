<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAbility('imports:write') && $this->user()->role === UserRole::Admin;
    }
    public function rules(): array
    {
        return ['file' => ['required','file','mimes:csv,txt','extensions:csv','max:102400']];
    }
}
