<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
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

    /** Querybuilder zonder databaseverbinding: alleen SQL opbouwen. */
    private function builder(): Builder
    {
        $connection = $this->createMock(ConnectionInterface::class);

        return (new Builder($connection, new MySqlGrammar(), new Processor()))->from('dashed__order_payments');
    }

    public function test_de_scope_laat_alleen_geldbetalingen_door_en_houdt_betalingen_zonder_psp(): void
    {
        $query = MoneyPayment::scope($this->builder()->where('status', 'paid'));

        $this->assertSame(
            'select * from `dashed__order_payments` where `status` = ? and (`psp` is null or `psp` != ?)',
            $query->toSql(),
        );
        $this->assertSame(['paid', 'giftcard'], $query->getBindings());
    }

    public function test_de_scope_blijft_gegroepeerd_naast_een_andere_psp_voorwaarde(): void
    {
        // Zoals PaynlRefundMatcher::coveredElsewhere(): geen Pay.nl en geen cadeaubon.
        $query = MoneyPayment::scope(
            $this->builder()
                ->whereIn('order_id', [1, 2])
                ->where('status', 'paid')
                ->where(fn ($q) => $q->whereNull('psp')->orWhere('psp', '!=', 'paynl'))
        );

        $this->assertSame(
            'select * from `dashed__order_payments` where `order_id` in (?, ?) and `status` = ? and (`psp` is null or `psp` != ?) and (`psp` is null or `psp` != ?)',
            $query->toSql(),
        );
        $this->assertSame([1, 2, 'paid', 'paynl', 'giftcard'], $query->getBindings());
    }
}
