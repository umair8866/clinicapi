<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'mrn' => $this->mrn, 'name' => $this->name,
            'cpr' => $this->cpr === null ? null : str_repeat('*', max(0, strlen($this->cpr) - 4)).substr($this->cpr, -4),
            'phone' => $this->phone === null ? null : str_repeat('*', max(0, strlen($this->phone) - 4)).substr($this->phone, -4)];
    }
}
