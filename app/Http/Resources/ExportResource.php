<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,'status' => $this->status,'rows_processed' => $this->rows_processed,
         'download_url' => $this->status === 'done' ? Storage::disk('s3')->temporaryUrl($this->object_key, now()->addMinutes(5)) : null];
    }
}
