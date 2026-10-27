<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommercePaynl\Classes\MoneyPayment;

final class MoneyPaymentTest extends TestCase
{
    public function test_een_cadeaubonboeking_is_geen_geldbetaling(): void
    {
        $this->assertFalse(MoneyPayment::isMoney('giftcard'));
    }

    public function test_zonder_psp_en_met_andere_psp_is_het_geld(): void
    {
        $this->assertTrue(MoneyPayment::isMoney(null));
        $this->assertTrue(MoneyPayment::isMoney(''));
        $this->assertTrue(MoneyPayment::isMoney('paynl'));
        $this->assertTrue(MoneyPayment::isMoney('mollie'));
    }
}
