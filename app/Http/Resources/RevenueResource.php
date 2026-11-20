<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        return ['group' => $row->group_key, 'gross' => number_format($row->gross_minor / 1000, 3, '.', ''),
            'discount' => number_format($row->discount_minor / 1000, 3, '.', ''),
            'vat' => number_format($row->vat_minor / 1000, 3, '.', ''),
            'net' => number_format($row->net_minor / 1000, 3, '.', ''),
            'paid' => number_format($row->paid_minor / 1000, 3, '.', ''),
            'outstanding' => number_format(($row->net_minor - $row->paid_minor) / 1000, 3, '.', '')];
    }
}
