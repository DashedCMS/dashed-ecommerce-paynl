<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Dashed\DashedEcommerceCore\Models\OrderPayment;

/**
 * Dun laagje om de Pay.nl-SDK, zodat de matcher zonder Pay.nl te testen is:
 * tests binden hier een nep voor in de container. Gooit door wat de SDK gooit.
 */
class PaynlTransactions
{
    /** Cumulatief terugbetaald bedrag bij Pay.nl voor deze transactie. */
    public function refundedAmount(OrderPayment $payment): ?float
    {
        PayNL::initialize($payment->order?->site_id);

        $transaction = \Paynl\Transaction::get($payment->psp_id);

        return round((float) $transaction->getRefundedAmount(), 2);
    }
}
