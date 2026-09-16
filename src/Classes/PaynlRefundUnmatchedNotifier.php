<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Dashed\DashedCore\Models\User;
use Dashed\DashedCore\Classes\Mails;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedCore\Notifications\AdminNotifier;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
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

        $recipients = $this->recipients();
        if (! $recipients) {
            // Geen enkel adres: de orderlog hierboven is dan het enige spoor.
            // Niet de cache-sleutel claimen, anders blijft de melding ook uit
            // zodra er wel een adres is ingesteld.
            return;
        }

        $key = 'paynl-refund-unmatched:' . $payment->id . ':' . number_format($match->refundedAtPaynl, 2, '.', '');
        if (Cache::has($key)) {
            return;
        }

        rescue(function () use ($order, $match, $recipients, $key) {
            AdminNotifier::send(new AdminPaynlRefundUnmatchedMail($order, $match), $recipients);

            // Pas claimen als de mail eruit is: een mislukte verzending mag de
            // sleutel niet dertig dagen bezet houden, want dan is deze melding
            // stilzwijgend verdwenen.
            Cache::add($key, true, now()->addDays(30));
        }, null, true);
    }

    /**
     * De meldingsadressen uit de instellingen, en zonder die de superadmins,
     * op dezelfde manier als SecurityAlerts::recipients() dat doet. Een lege
     * lijst bij Instellingen mag de melding niet het zwijgen opleggen.
     *
     * @return array<int, string>
     */
    protected function recipients(): array
    {
        $emails = [];

        foreach ((array) (Mails::getAdminNotificationEmails() ?: []) as $email) {
            $email = strtolower(trim((string) $email));

            if ($email !== '' && ! in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        if ($emails) {
            return $emails;
        }

        return User::query()
            ->where('role', 'superadmin')
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
