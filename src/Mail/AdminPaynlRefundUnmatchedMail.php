<?php

namespace Dashed\DashedEcommercePaynl\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Classes\CurrencyHelper;
use Dashed\DashedCore\Notifications\DTOs\TelegramSummary;
use Dashed\DashedEcommercePaynl\Classes\PaynlRefundMatch;
use Dashed\DashedCore\Notifications\Contracts\SendsToTelegram;

/**
 * Beheerdersmelding: er is bij Pay.nl terugbetaald op een bestelling, maar
 * het bedrag past bij geen enkele openstaande creditorder met retour. Niets
 * is geboekt; iemand moet kijken. Gewone mailable met html in PHP, bewust
 * geen bewerkbare e-mailtemplate.
 */
class AdminPaynlRefundUnmatchedMail extends Mailable implements SendsToTelegram
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Order $order, public PaynlRefundMatch $match)
    {
    }

    public function build()
    {
        $number = $this->order->invoice_id ?: ('#' . $this->order->id);

        return $this
            ->from(Customsetting::get('site_from_email'), Customsetting::get('site_name'))
            ->subject(__('Pay.nl-terugbetaling niet gekoppeld voor bestelling :nummer', ['nummer' => $number]))
            ->html($this->htmlBody($number));
    }

    public function telegramSummary(): TelegramSummary
    {
        return new TelegramSummary(
            title: __('Pay.nl-terugbetaling niet gekoppeld: :nummer', ['nummer' => $this->order->invoice_id ?: ('#' . $this->order->id)]),
            fields: [
                __('Nieuw bedrag') => CurrencyHelper::formatPrice($this->match->newAmount),
                __('Reden') => $this->match->reason,
            ],
            adminUrl: rescue(fn () => route('filament.dashed.resources.orders.view', ['record' => $this->order->id]), null, false),
        );
    }

    protected function htmlBody(string $number): string
    {
        $url = rescue(fn () => route('filament.dashed.resources.orders.view', ['record' => $this->order->id]), null, false);
        $rows = [
            __('Terugbetaald bij Pay.nl (totaal)') => CurrencyHelper::formatPrice($this->match->refundedAtPaynl),
            __('Al geregistreerd in het CMS') => CurrencyHelper::formatPrice($this->match->registeredBefore),
            __('Nieuw, nog niet gekoppeld') => CurrencyHelper::formatPrice($this->match->newAmount),
            __('Reden') => $this->match->reason,
        ];

        $html = '<p>' . e(__('Bij Pay.nl is terugbetaald op bestelling :nummer, maar het bedrag past bij geen enkele openstaande creditorder met retour. Er is niets geboekt.', ['nummer' => $number])) . '</p>';
        if ($url) {
            $html .= '<p><a href="' . e($url) . '">' . e(__('Open de bestelling in het CMS')) . '</a></p>';
        }
        $html .= '<table>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td><strong>' . e($label) . '</strong></td><td>' . e($value) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= $this->list(__('Openstaande creditorders met retour'), $this->match->candidates);
        $html .= $this->list(__('Openstaande creditorders zonder retour (niet automatisch te koppelen)'), $this->match->otherOpenCreditOrders);
        $html .= '<p>' . e(__('Klopt het bedrag, registreer de terugbetaling dan met de hand op de retour ("Terugbetaling registreren").')) . '</p>';

        return $html;
    }

    /** @param array<int, float> $items */
    protected function list(string $heading, array $items): string
    {
        if ($items === []) {
            return '';
        }
        $html = '<p><strong>' . e($heading) . '</strong></p><ul>';
        foreach ($items as $orderId => $amount) {
            $invoice = Order::query()->whereKey($orderId)->value('invoice_id') ?: ('#' . $orderId);
            $html .= '<li>' . e($invoice) . ': ' . e(CurrencyHelper::formatPrice($amount)) . '</li>';
        }

        return $html . '</ul>';
    }
}
