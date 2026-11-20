<?php

namespace App\Services;

use App\Http\Resources\VisitResource;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VisitWriterService
{
    /** @param array<string, mixed> $data */
    public function create(User $user, array $data, string $key): JsonResponse
    {
        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);
        [$body, $status] = DB::transaction(function () use ($user, $data, $hash, $keyHash): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->refresh();
            $branch = Branch::query()->whereKey($data['branch_id'])->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('create', [Visit::class, $branch]);
            $existing = DB::table('idempotency_keys')->where('user_id', $user->id)
                ->where('endpoint', 'visits.store')->where('key_hash', $keyHash)->first();
            if ($existing !== null) {
                abort_if(! hash_equals($existing->request_hash, $hash), 409, 'Idempotency key already used with different data.');
                return [json_decode($existing->response_body, true, flags: JSON_THROW_ON_ERROR), $existing->response_status];
            }
            $doctor = Doctor::query()->whereKey($data['doctor_id'])->lockForUpdate()->firstOrFail();
            if (! $doctor->is_active || $doctor->branch_id !== $branch->id) {
                throw ValidationException::withMessages(['doctor_id' => 'The doctor must be active at the selected branch.']);
            }
            $gross = 0;
            foreach ($data['items'] as $item) {
                $gross += $item['qty'] * $this->minor($item['unit_price']);
            }
            $discount = $this->minor($data['discount']);
            if ($discount > $gross || $gross > 1000000000000) {
                throw ValidationException::withMessages(['discount' => 'Invalid invoice total or discount.']);
            }
            $vatRate = 1000;
            $vat = intdiv(($gross - $discount) * $vatRate + 5000, 10000);
            $net = $gross - $discount + $vat;
            $visit = new Visit([
                'patient_id' => $data['patient_id'], 'branch_id' => $branch->id, 'doctor_id' => $doctor->id,
                'visit_date' => $data['visit_date'], 'status' => $data['status'],
            ]);
            $visit->created_by = $user->id;
            $visit->save();
            $invoice = Invoice::query()->create([
                'visit_id' => $visit->id,
                'billing_date' => CarbonImmutable::parse($data['visit_date'], 'UTC')->setTimezone('Asia/Bahrain')->toDateString(),
                'currency' => 'BHD', 'total_minor' => $gross, 'discount_minor' => $discount,
                'vat_rate_bps' => $vatRate, 'vat_minor' => $vat, 'net_minor' => $net,
                'status' => $data['status'] === 'cancelled' ? 'void' : ($net === 0 ? 'paid' : 'unpaid'),
            ]);
            foreach ($data['items'] as $item) {
                $invoice->items()->create(['service_code' => $item['service_code'],
                    'qty' => $item['qty'], 'unit_price_minor' => $this->minor($item['unit_price'])]);
            }
            if ($invoice->status !== 'void') {
                $this->addSummary('daily_branch_revenue', 'branch_id', $branch->id, $invoice, $branch->id);
                $this->addSummary('daily_doctor_revenue', 'doctor_id', $doctor->id, $invoice, $branch->id);
                DB::statement(
                    'INSERT INTO patient_year_totals (year, patient_id, invoice_count, net_minor, updated_at)
                    VALUES (?, ?, 1, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
                    invoice_count = invoice_count + 1, net_minor = net_minor + VALUES(net_minor), updated_at = UTC_TIMESTAMP()',
                    [(int) substr($invoice->billing_date->toDateString(), 0, 4), $visit->patient_id, $net]
                );
            }
            $visit->load(['doctor:id,name', 'branch:id,name', 'invoice:id,visit_id,net_minor']);
            $body = ['data' => (new VisitResource($visit))->resolve()];
            DB::table('idempotency_keys')->insert(['user_id' => $user->id, 'endpoint' => 'visits.store',
                'key_hash' => $keyHash, 'request_hash' => $hash, 'response_status' => 201,
                'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            return [$body, 201];
        }, 3);
        return response()->json($body, $status);
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = explode('.', $amount);
        return (int) $whole * 1000 + (int) $fraction;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function canonical(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }
        if (! array_is_list($data)) {
            ksort($data);
        }
        return $data;
    }

    private function addSummary(string $table, string $dimension, int $dimensionId, Invoice $invoice, int $branchId): void
    {
        if ($dimension === 'doctor_id') {
            DB::statement(
                'INSERT INTO daily_doctor_revenue
                (billing_date, doctor_id, branch_id, invoice_count, gross_minor, discount_minor, vat_minor, net_minor, paid_minor, updated_at)
                VALUES (?, ?, ?, 1, ?, ?, ?, ?, 0, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE invoice_count = invoice_count + 1,
                gross_minor = gross_minor + VALUES(gross_minor), discount_minor = discount_minor + VALUES(discount_minor),
                vat_minor = vat_minor + VALUES(vat_minor), net_minor = net_minor + VALUES(net_minor), updated_at = UTC_TIMESTAMP()',
                [$invoice->billing_date->toDateString(), $dimensionId, $branchId, $invoice->total_minor, $invoice->discount_minor, $invoice->vat_minor, $invoice->net_minor]
            );
        } else {
            DB::statement(
                'INSERT INTO daily_branch_revenue
                (billing_date, branch_id, invoice_count, gross_minor, discount_minor, vat_minor, net_minor, paid_minor, updated_at)
                VALUES (?, ?, 1, ?, ?, ?, ?, 0, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE invoice_count = invoice_count + 1,
                gross_minor = gross_minor + VALUES(gross_minor), discount_minor = discount_minor + VALUES(discount_minor),
                vat_minor = vat_minor + VALUES(vat_minor), net_minor = net_minor + VALUES(net_minor), updated_at = UTC_TIMESTAMP()',
                [$invoice->billing_date->toDateString(), $dimensionId, $invoice->total_minor, $invoice->discount_minor, $invoice->vat_minor, $invoice->net_minor]
            );
        }
    }
}
