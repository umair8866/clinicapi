<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use HasFactory;

    protected $fillable = ['visit_id', 'billing_date', 'currency', 'total_minor', 'discount_minor', 'vat_rate_bps', 'vat_minor', 'net_minor', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_date' => 'immutable_date',
            'total_minor' => 'integer',
            'discount_minor' => 'integer',
            'vat_rate_bps' => 'integer',
            'vat_minor' => 'integer',
            'net_minor' => 'integer',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
