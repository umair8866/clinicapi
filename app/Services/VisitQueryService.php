<?php

namespace App\Services;

use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;

class VisitQueryService
{
    /** @param array<string, mixed> $filters
     * @return Builder<Visit>
     */
    public function query(User $user, array $filters): Builder
    {
        $query = Visit::query()->visibleTo($user);
        foreach (['branch_id', 'doctor_id', 'status'] as $key) {
            if (isset($filters[$key])) {
                $query->where('visits.'.$key, $filters[$key]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('visit_date', '>=', $filters['from'].' 00:00:00');
        }
        if (isset($filters['to'])) {
            $query->where('visit_date', '<', \Carbon\CarbonImmutable::parse($filters['to'])->addDay()->toDateString().' 00:00:00');
        }
        return $query->orderByDesc('visit_date')->orderByDesc('id');
    }
}
