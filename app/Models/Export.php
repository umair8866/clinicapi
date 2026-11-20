<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Export extends Model
{
    protected $fillable = ['user_id','filters','branch_scope','status','max_visit_id','object_key','rows_processed','last_visit_date','last_visit_id','parts','error_code'];
    protected function casts(): array
    {
        return ['filters' => 'array','branch_scope' => 'array','last_visit_date' => 'immutable_datetime'];
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
