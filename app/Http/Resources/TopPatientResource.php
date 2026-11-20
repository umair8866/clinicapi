<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TopPatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['patient_id' => $this->patient_id, 'name' => $this->name,
            'invoice_count' => $this->invoice_count, 'net' => number_format($this->net_minor / 1000, 3, '.', '')];
    }
}
