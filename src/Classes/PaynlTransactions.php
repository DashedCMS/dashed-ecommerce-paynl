<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Dashed\DashedEcommerceCore\Models\OrderPayment;
use PayNL\Sdk\Model\Request\TransactionStatusRequest;

/**
 * Dun laagje om de Pay.-SDK, zodat de matcher zonder Pay.nl te testen is:
 * tests binden hier een nep voor in de container. Gooit door wat de SDK gooit.
 */
class PaynlTransactions
{
    /** Cumulatief terugbetaald bedrag bij Pay. voor deze transactie, in euro. */
    public function refundedAmount(OrderPayment $payment): ?float
    {
        // Het transactie-endpoint van Multicore geeft amountRefunded in
        // centen; de SDK rekent dat om naar euro. Dat bedrag staat in de
        // valuta van de transactie, dus bij een niet-euro-shop matcht het
        // niet met het ordertotaal en valt de koppeling terug op een melding.
        $transaction = (new TransactionStatusRequest((string) $payment->psp_id))
            ->setConfig(PayNL::config($payment->order?->site_id))
            ->start();

        return round((float) $transaction->getAmountRefunded(), 2);
    }
}
