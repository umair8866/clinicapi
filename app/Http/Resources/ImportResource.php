<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,'status' => $this->status,'processed_rows' => $this->processed_rows,
         'valid_rows' => $this->valid_rows,'invalid_rows' => $this->invalid_rows,
         'error_report_url' => $this->status === 'done' && $this->error_object_key
          ? Storage::disk()->temporaryUrl($this->error_object_key, now()->addMinutes(5)) : null];
    }
}
