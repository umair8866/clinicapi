<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'visit_id' => $this->visit_id, 'billing_date' => $this->billing_date?->toDateString(),
            'currency' => $this->currency, 'status' => $this->status,
            'gross' => number_format($this->total_minor / 1000, 3, '.', ''),
            'discount' => number_format($this->discount_minor / 1000, 3, '.', ''),
            'vat' => number_format($this->vat_minor / 1000, 3, '.', ''),
            'net' => number_format($this->net_minor / 1000, 3, '.', ''),
            'paid' => number_format($this->payments->sum('amount_minor') / 1000, 3, '.', ''),
            'outstanding' => number_format(($this->net_minor - $this->payments->sum('amount_minor')) / 1000, 3, '.', ''),
            'items' => $this->items->map(fn ($item): array => ['service_code' => $item->service_code, 'qty' => $item->qty, 'unit_price' => number_format($item->unit_price_minor / 1000, 3, '.', '')]),
            'payments' => $this->payments->map(fn ($payment): array => ['id' => $payment->id, 'method' => $payment->method, 'amount' => number_format($payment->amount_minor / 1000, 3, '.', ''), 'paid_at' => $payment->paid_at?->toIso8601String()])];
    }
}
