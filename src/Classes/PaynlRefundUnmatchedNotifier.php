<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Illuminate\Support\Facades\Cache;
use Dashed\DashedCore\Classes\Mails;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedCore\Notifications\AdminNotifier;
use Dashed\DashedEcommerceCore\Classes\CurrencyHelper;
use Dashed\DashedEcommercePaynl\Mail\AdminPaynlRefundUnmatchedMail;

/**
 * Orderlog altijd; mail en push hooguit één keer per betaling plus cumulatief
 * Pay.nl-bedrag, zodat het nachtelijke vangnet niet elke nacht dezelfde
 * melding stuurt. Een nieuw bedrag bij Pay.nl is een nieuwe melding.
 */
class PaynlRefundUnmatchedNotifier
{
    public const TAG = 'order.paynl-refund-unmatched';

    public function notify(OrderPayment $payment, PaynlRefundMatch $match): void
    {
        $order = $payment->order;
        if (! $order) {
            return;
        }

        OrderLog::createLog(
            orderId: $order->id,
            tag: self::TAG,
            note: __('Pay.nl-terugbetaling niet gekoppeld: bij Pay.nl :totaal, al geregistreerd :geregistreerd, nieuw :nieuw. :reden', [
                'totaal' => CurrencyHelper::formatPrice($match->refundedAtPaynl),
                'geregistreerd' => CurrencyHelper::formatPrice($match->registeredBefore),
                'nieuw' => CurrencyHelper::formatPrice($match->newAmount),
                'reden' => $match->reason,
            ]),
        );

        $key = 'paynl-refund-unmatched:' . $payment->id . ':' . number_format($match->refundedAtPaynl, 2, '.', '');
        if (! Cache::add($key, true, now()->addDays(30))) {
            return;
        }

        rescue(function () use ($order, $match) {
            AdminNotifier::send(new AdminPaynlRefundUnmatchedMail($order, $match), Mails::getAdminNotificationEmails());
        }, null, true);
    }
}
