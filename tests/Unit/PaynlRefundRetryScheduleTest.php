<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommercePaynl\Classes\PaynlRefundRetrySchedule;

final class PaynlRefundRetryScheduleTest extends TestCase
{
    public function test_de_tussenpozen_lopen_op_tot_hoogstens_een_uur(): void
    {
        $delays = array_map(fn (int $attempt) => PaynlRefundRetrySchedule::nextDelay($attempt, 0), range(1, 9));

        $this->assertSame([120, 300, 600, 1200, 2400, 3600, 3600, 3600, 3600], $delays);
    }

    public function test_de_laatste_poging_valt_precies_op_24_uur_na_de_eerste_melding(): void
    {
        $this->assertSame(600, PaynlRefundRetrySchedule::nextDelay(20, 86400 - 600));
        $this->assertSame(1, PaynlRefundRetrySchedule::nextDelay(20, 86399));
    }

    public function test_na_24_uur_komt_er_geen_poging_meer(): void
    {
        $this->assertNull(PaynlRefundRetrySchedule::nextDelay(30, 86400));
        $this->assertNull(PaynlRefundRetrySchedule::nextDelay(1, 90000));
    }

    public function test_een_hele_reeks_eindigt_op_24_uur_en_wacht_nooit_langer_dan_een_uur(): void
    {
        $elapsed = 0;
        $attempt = 1;
        $delays = [];

        while (($delay = PaynlRefundRetrySchedule::nextDelay($attempt, $elapsed)) !== null) {
            $delays[] = $delay;
            $elapsed += $delay;
            $attempt++;
        }

        $this->assertSame(86400, $elapsed);
        $this->assertLessThanOrEqual(3600, max($delays));
        $this->assertLessThan(40, count($delays));
    }
}
