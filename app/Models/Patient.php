<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Patient extends Model
{
    /** @use HasFactory<\Database\Factories\PatientFactory> */
    use HasFactory;

    protected $fillable = ['mrn', 'cpr', 'name', 'phone', 'dob', 'gender', 'flat_number', 'building_number', 'road_number', 'block', 'area'];

    protected $hidden = ['cpr', 'phone', 'dob', 'gender', 'flat_number', 'building_number', 'road_number', 'block', 'area'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dob' => 'immutable_date',
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, Visit::class);
    }
}
