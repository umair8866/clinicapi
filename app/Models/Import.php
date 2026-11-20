<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    protected $fillable = ['user_id','status','object_key','error_object_key','source_bytes','next_byte_offset','processed_rows','valid_rows','invalid_rows','error_code'];
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
