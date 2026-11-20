<?php

namespace App\Services;

use App\Models\User;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevenueReportService
{
    /** @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    public function revenue(User $user, array $filters): Collection
    {
        $group = $filters['group_by'];
        $doctor = $group === 'doctor';
        $table = $doctor ? 'daily_doctor_revenue' : 'daily_branch_revenue';
        $column = $doctor ? 'doctor_id' : ($group === 'branch' ? 'branch_id' : null);
        $key = $column === null ? "DATE_FORMAT(billing_date, '%Y-%m')" : $column;
        $query = DB::table($table)->where('billing_date', '>=', $filters['from'])
            ->where('billing_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        if ($user->role !== UserRole::Admin) {
            $query->whereIn('branch_id', $user->branches()->select('branches.id'));
        }
        if (isset($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        return $query->selectRaw($key.' AS group_key, SUM(gross_minor) AS gross_minor, SUM(discount_minor) AS discount_minor, SUM(vat_minor) AS vat_minor, SUM(net_minor) AS net_minor, SUM(paid_minor) AS paid_minor')
            ->groupByRaw($key)->orderBy('group_key')->get();
    }
    /** @return Collection<int, object> */
    public function topPatients(int $year): Collection
    {
        return DB::table('patient_year_totals as totals')->join('patients as patient', 'patient.id', '=', 'totals.patient_id')
            ->where('year', $year)->orderByDesc('net_minor')->orderBy('patient_id')->limit(100)
            ->select('totals.patient_id', 'totals.invoice_count', 'totals.net_minor', 'patient.name')->get();
    }
}
