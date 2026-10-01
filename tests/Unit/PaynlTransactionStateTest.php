<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommercePaynl\Classes\PaynlTransactionState;

final class PaynlTransactionStateTest extends TestCase
{
    public function test_leest_de_staat_uit_paymentDetails_stateName_en_zet_hem_in_hoofdletters(): void
    {
        $this->assertSame(
            'DENIED_63',
            PaynlTransactionState::fromTransactionData(['paymentDetails' => ['stateName' => 'DENIED_63']])
        );

        $this->assertSame(
            'CANCEL',
            PaynlTransactionState::fromTransactionData(['paymentDetails' => ['stateName' => 'cancel']])
        );
    }

    public function test_geeft_null_zonder_paymentDetails_zonder_stateName_of_bij_een_lege_staat(): void
    {
        $this->assertNull(
            PaynlTransactionState::fromTransactionData([])
        );

        $this->assertNull(
            PaynlTransactionState::fromTransactionData(['paymentDetails' => []])
        );

        $this->assertNull(
            PaynlTransactionState::fromTransactionData(['paymentDetails' => ['stateName' => '']])
        );

        $this->assertNull(
            PaynlTransactionState::fromTransactionData(['paymentDetails' => ['stateName' => 42]])
        );
    }

    public function test_voegt_de_staat_en_het_tijdstip_toe_zonder_andere_attributen_te_verliezen(): void
    {
        $nu = Carbon::parse('2026-10-01 12:00:00', 'Europe/Amsterdam');

        $resultaat = PaynlTransactionState::mergeInto(['terminal' => ['statusUrl' => 'x']], 'DENIED_63', $nu);

        $this->assertSame([
            'terminal' => ['statusUrl' => 'x'],
            'psp_state' => 'DENIED_63',
            'psp_state_at' => $nu->toIso8601String(),
        ], $resultaat);
    }

    public function test_overschrijft_een_eerdere_staat_bij_een_nieuwe_check(): void
    {
        $nu = Carbon::parse('2026-10-01 12:05:00', 'Europe/Amsterdam');

        $resultaat = PaynlTransactionState::mergeInto(['psp_state' => 'PENDING', 'psp_state_at' => 'eerder'], 'PAID', $nu);

        $this->assertSame('PAID', $resultaat['psp_state']);
        $this->assertSame($nu->toIso8601String(), $resultaat['psp_state_at']);
    }

    public function test_laat_de_attributen_met_rust_als_er_geen_staat_is(): void
    {
        $attributen = ['terminal' => ['statusUrl' => 'x']];

        $resultaat = PaynlTransactionState::mergeInto($attributen, null, Carbon::now());

        $this->assertSame($attributen, $resultaat);
    }
}
