<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'patient_id' => $this->patient_id, 'doctor_id' => $this->doctor_id,
            'doctor' => $this->whenLoaded('doctor', fn (): ?string => $this->doctor?->name),
            'branch_id' => $this->branch_id, 'branch' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->name),
            'visit_date' => $this->visit_date?->toIso8601String(), 'status' => $this->status,
            'invoice_id' => $this->whenLoaded('invoice', fn (): ?int => $this->invoice?->id),
            'invoice_total' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice === null ? null : number_format($this->invoice->net_minor / 1000, 3, '.', ''))];
    }
}
