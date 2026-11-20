<?php

namespace Tests\Unit;

use App\Services\ClinicSeedGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClinicSeedGeneratorTest extends TestCase
{
    private function generator(int $patients = 100, int $visits = 500): ClinicSeedGenerator
    {
        return new ClinicSeedGenerator($patients, $visits, '2021-11-19 12:00:00', '2026-11-19 12:00:00');
    }

    public function test_default_targets_match_required_business_volume(): void
    {
        $generator = $this->generator(1000000, 5000000);
        $this->assertSame(30001025, array_sum($generator->targets()));
        $this->assertSame(4000000, $generator->targets()['payments']);
    }

    public function test_patient_cpr_encodes_birth_year_and_month_with_unique_random_suffix(): void
    {
        $generator = $this->generator();
        $cprs = [];

        for ($id = 1; $id <= 10000; $id++) {
            $patient = $generator->patient($id);
            $this->assertMatchesRegularExpression('/^\d{9}$/', $patient['cpr']);
            $this->assertSame(substr(str_replace('-', '', $patient['dob']), 2, 4), substr($patient['cpr'], 0, 4));
            $this->assertMatchesRegularExpression('/^\+9733\d{7}$/', $patient['phone']);
            $cprs[$patient['cpr']] = true;
        }

        $this->assertCount(10000, $cprs);
        $this->assertSame($generator->patient(50000), $this->generator()->patient(50000));
    }

    public function test_restart_generates_identical_rows_without_random_state(): void
    {
        $first = $this->generator();
        $expected = $first->visitBundle(314);
        $first->visitBundle(315);
        $this->assertSame($expected, $this->generator()->visitBundle(314));
    }

    public function test_payment_counts_ids_and_amounts_reconcile_for_partial_groups(): void
    {
        for ($visits = 20; $visits < 30; $visits++) {
            $generator = $this->generator(10, $visits);
            $ids = [];
            for ($id = 1; $id <= $visits; $id++) {
                $bundle = $generator->visitBundle($id);
                $invoice = $bundle['invoices'][0];
                $gross = 0;
                foreach ($bundle['invoice_items'] as $item) {
                    $gross += $item['qty'] * $item['unit_price_minor'];
                }
                $this->assertSame($invoice['total_minor'], $gross);
                $paid = array_sum(array_column($bundle['payments'], 'amount_minor'));
                $this->assertLessThanOrEqual($invoice['net_minor'], $paid);
                if ($invoice['status'] === 'void') {
                    $this->assertSame(0, $paid);
                }
                if ($invoice['status'] === 'paid') {
                    $this->assertSame($invoice['net_minor'], $paid);
                }
                foreach ($bundle['payments'] as $payment) {
                    $ids[] = $payment['id'];
                }
            }
            $this->assertSame(range(1, intdiv($visits * 4, 5)), $ids);
        }
    }

    public function test_histories_have_a_long_tail_and_repeat_across_branches(): void
    {
        $generator = $this->generator();
        $histories = [];
        for ($id = 1; $id <= 500; $id++) {
            $visit = $generator->visitBundle($id)['visits'][0];
            $histories[$visit['patient_id']][] = $visit['branch_id'];
            $this->assertSame($visit['branch_id'], intdiv($visit['doctor_id'] - 1, 40) + 1);
        }
        $this->assertGreaterThanOrEqual(50, count($histories[1]));
        $this->assertCount(5, $histories[2]);
        $this->assertSame([5, 6, 5], array_slice($histories[5], 0, 3));
        $this->assertCount(20, array_filter($histories, fn (array $branches): bool => count(array_unique($branches)) > 1));
    }

    public function test_timestamps_and_billing_dates_follow_the_frozen_range(): void
    {
        $generator = $this->generator();
        $years = [];
        for ($id = 1; $id <= 500; $id++) {
            $bundle = $generator->visitBundle($id);
            $visit = $bundle['visits'][0];
            $invoice = $bundle['invoices'][0];
            $years[substr($visit['visit_date'], 0, 4)] = true;
            $this->assertGreaterThanOrEqual($generator->rangeStart, $visit['visit_date']);
            $this->assertLessThanOrEqual($generator->rangeEnd, $visit['visit_date']);
            $this->assertGreaterThan($visit['visit_date'], $invoice['created_at']);
            foreach ($bundle['payments'] as $payment) {
                $this->assertGreaterThan($invoice['created_at'], $payment['paid_at']);
                $this->assertLessThanOrEqual($generator->rangeEnd, $payment['paid_at']);
            }
        }
        $this->assertCount(6, $years);
        $this->assertSame('2021-11-19 12:00:00', $generator->visitBundle(1)['visits'][0]['visit_date']);
        $this->assertSame('2026-11-19 11:58:00', $generator->visitBundle(500)['visits'][0]['visit_date']);
    }

    public function test_invalid_volume_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator(100, 199);
    }

    public function test_integer_billing_and_split_payments_preserve_minor_units(): void
    {
        $bundle = $this->generator()->visitBundle(1);
        $this->assertSame(7250, $bundle['invoices'][0]['total_minor']);
        $this->assertSame(725, $bundle['invoices'][0]['vat_minor']);
        $this->assertSame(7975, $bundle['invoices'][0]['net_minor']);
        $this->assertSame([3987, 3988], array_column($bundle['payments'], 'amount_minor'));
        $discounted = $this->generator()->visitBundle(4)['invoices'][0];
        $this->assertSame(500, $discounted['discount_minor']);
        $this->assertSame(975, $discounted['vat_minor']);
        $this->assertSame(10725, $discounted['net_minor']);
    }
}
