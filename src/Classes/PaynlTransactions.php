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

        // status() is dezelfde aanroep als get(), maar dan één in plaats van twee.
        // getRefundedAmount() staat in euro; getRefundedCurrencyAmount() zou de
        // valuta van de shop zijn, dus bij een niet-euro-shop matcht dit bedrag
        // niet met het ordertotaal en valt de koppeling terug op een melding.
        return round((float) \Paynl\Transaction::status($payment->psp_id)->getRefundedAmount(), 2);
    }
}
