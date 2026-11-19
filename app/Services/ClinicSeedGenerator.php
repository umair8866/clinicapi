<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class ClinicSeedGenerator
{
    public const VERSION = 1;

    private int $start;
    private int $end;

    public function __construct(
        public readonly int $patients,
        public readonly int $visits,
        public readonly string $rangeStart,
        public readonly string $rangeEnd,
        public readonly int $seed = 1729,
    ) {
        if ($patients < 1 || $patients > 100000000 || $visits < 2 * $patients || $visits > 1000000000) {
            throw new InvalidArgumentException('Use 1..100000000 patients and 2*patients..1000000000 visits.');
        }
        $this->start = (new DateTimeImmutable($rangeStart, new DateTimeZone('UTC')))->getTimestamp();
        $this->end = (new DateTimeImmutable($rangeEnd, new DateTimeZone('UTC')))->getTimestamp();
        if ($this->end - $this->start < 86400) {
            throw new InvalidArgumentException('Seed date range must span at least one day.');
        }
    }

    /** @return array<string, int> */
    public function targets(): array
    {
        return ['branches' => 25, 'doctors' => 1000, 'patients' => $this->patients,
            'visits' => $this->visits, 'invoices' => $this->visits,
            'invoice_items' => $this->visits * 3, 'payments' => intdiv($this->visits * 4, 5)];
    }

    /** @return array<string, int|string|bool> */
    public function branch(int $id): array
    {
        return ['id' => $id, 'code' => sprintf('SYN-%02d', $id), 'name' => 'Synthetic Clinic '.$id,
            'city' => ['Manama', 'Muharraq', 'Riffa', 'Isa Town', 'Hamad Town'][($id - 1) % 5],
            'timezone' => 'Asia/Bahrain', 'is_active' => true,
            'created_at' => $this->rangeStart, 'updated_at' => $this->rangeStart];
    }

    /** @return array<string, int|string|bool> */
    public function doctor(int $id): array
    {
        return ['id' => $id, 'branch_id' => intdiv($id - 1, 40) + 1,
            'name' => 'Dr Synthetic '.$id, 'specialty' => ['General Practice', 'Cardiology', 'Pediatrics', 'Dermatology'][($id - 1) % 4],
            'is_active' => true, 'created_at' => $this->rangeStart, 'updated_at' => $this->rangeStart];
    }

    /** @return array<string, int|string> */
    public function patient(int $id): array
    {
        $names = ['Ali', 'Fatima', 'Ahmed', 'Mariam', 'Hassan', 'Noor', 'Yusuf', 'Sara'];
        return ['id' => $id, 'mrn' => sprintf('SYN%010d', $id), 'cpr' => sprintf('SYN-ID-%010d', $id),
            'name' => $names[($id - 1) % 8].' Synthetic '.$id,
            'phone' => '+973'.sprintf('%08d', 30000000 + $id % 9999999),
            'dob' => gmdate('Y-m-d', strtotime('1940-01-01 UTC') + ($id % 20000) * 86400),
            'gender' => $id % 2 === 0 ? 'female' : 'male',
            'created_at' => $this->rangeStart, 'updated_at' => $this->rangeStart];
    }

    /** @return array{patient_id: int, sequence: int} */
    public function patientForVisit(int $id): array
    {
        if ($id <= 2 * $this->patients) {
            return ['patient_id' => ($id - 1) % $this->patients + 1, 'sequence' => intdiv($id - 1, $this->patients)];
        }
        $tailPatients = max(1, intdiv($this->patients, 100));
        $tailVisits = min($this->visits - 2 * $this->patients, 48 * $tailPatients);
        $extra = $id - 2 * $this->patients - 1;
        if ($extra < $tailVisits) {
            return ['patient_id' => $extra % $tailPatients + 1, 'sequence' => 2 + intdiv($extra, $tailPatients)];
        }
        $extra -= $tailVisits;
        $patient = $extra % $this->patients + 1;
        return ['patient_id' => $patient, 'sequence' => 2 + ($patient <= $tailPatients ? 48 : 0) + intdiv($extra, $this->patients)];
    }

    /** @return array{visits: list<array<string, mixed>>, invoices: list<array<string, mixed>>, invoice_items: list<array<string, mixed>>, payments: list<array<string, mixed>>} */
    public function visitBundle(int $id): array
    {
        $owner = $this->patientForVisit($id);
        $patient = $owner['patient_id'];
        $branch = ($patient - 1 + ($patient % 5 === 0 ? $owner['sequence'] % 2 : 0)) % 25 + 1;
        $doctor = ($branch - 1) * 40 + ($patient + $id) % 40 + 1;
        $span = $this->end - $this->start - 120;
        $offset = $id === 1 ? 0 : ($id === $this->visits ? $span : (int) sprintf('%u', crc32($this->seed.':'.$id)) % ($span + 1));
        $visitTime = $this->start + $offset;
        $created = gmdate('Y-m-d H:i:s', $visitTime);
        $billed = gmdate('Y-m-d H:i:s', $visitTime + 1);
        $updated = gmdate('Y-m-d H:i:s', $visitTime + 120);
        $items = [];
        $total = 0;
        for ($line = 1; $line <= 3; $line++) {
            $price = 1000 + (($id + $line) % 40) * 250;
            $qty = $line === 3 ? 2 : 1;
            $total += $qty * $price;
            $items[] = ['id' => ($id - 1) * 3 + $line, 'invoice_id' => $id,
                'service_code' => ['CONSULT', 'LAB', 'MED'][$line - 1], 'qty' => $qty,
                'unit_price_minor' => $price, 'created_at' => $billed, 'updated_at' => $billed];
        }
        $discount = $id % 4 === 0 ? 500 : 0;
        $vat = intdiv(($total - $discount) * 1000 + 5000, 10000);
        $net = $total - $discount + $vat;
        $group = intdiv($id - 1, 5);
        $position = ($id - 1) % 5;
        $remaining = min(5, $this->visits - $group * 5);
        $counts = $remaining === 5 ? [2, 1, 1, 0, 0] : array_merge(array_fill(0, intdiv($remaining * 4, 5), 1), array_fill(0, 5, 0));
        $count = $counts[$position];
        $paid = $count === 0 ? 0 : ($position === 1 ? intdiv($net, 2) : $net);
        $payments = [];
        $previous = array_sum(array_slice($counts, 0, $position));
        for ($part = 0; $part < $count; $part++) {
            $amount = $count === 1 ? $paid : ($part === 0 ? intdiv($paid, 2) : $paid - intdiv($paid, 2));
            $paidAt = gmdate('Y-m-d H:i:s', $visitTime + 60 + $part * 60);
            $payments[] = ['id' => $group * 4 + $previous + $part + 1, 'invoice_id' => $id,
                'method' => ['cash', 'card', 'insurance'][($id + $part) % 3], 'amount_minor' => $amount,
                'paid_at' => $paidAt, 'created_at' => $paidAt, 'updated_at' => $paidAt];
        }
        $void = $position === 4;
        return [
            'visits' => [['id' => $id, 'patient_id' => $patient, 'doctor_id' => $doctor, 'branch_id' => $branch,
                'created_by' => null, 'visit_date' => $created, 'status' => $void ? 'cancelled' : 'completed',
                'created_at' => $created, 'updated_at' => $created]],
            'invoices' => [['id' => $id, 'visit_id' => $id,
                'billing_date' => (new DateTimeImmutable($created, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Bahrain'))->format('Y-m-d'),
                'currency' => 'BHD', 'total_minor' => $total, 'discount_minor' => $discount, 'vat_rate_bps' => 1000,
                'vat_minor' => $vat, 'net_minor' => $net, 'status' => $void ? 'void' : ($paid === 0 ? 'unpaid' : ($paid === $net ? 'paid' : 'partially_paid')),
                'created_at' => $billed, 'updated_at' => $count > 0 ? $updated : $billed]],
            'invoice_items' => $items, 'payments' => $payments,
        ];
    }
}
