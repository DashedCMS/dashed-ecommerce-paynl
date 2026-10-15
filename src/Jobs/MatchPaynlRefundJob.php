<?php

namespace Dashed\DashedEcommercePaynl\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Classes\CurrencyHelper;
use Dashed\DashedEcommercePaynl\Classes\PaynlRefundMatch;
use Dashed\DashedEcommercePaynl\Classes\PaynlRefundMatcher;
use Dashed\DashedEcommercePaynl\Classes\PaynlRefundRetrySchedule;
use Dashed\DashedEcommercePaynl\Classes\PaynlRefundUnmatchedNotifier;

/**
 * Enige ingang naar de matcher: de exchange-listener en het nachtelijke
 * vangnet dispatchen allebei deze job. Uniek per betaling, zodat webhook en
 * vangnet elkaar niet dubbel laten draaien; de matcher zelf is bovendien
 * idempotent.
 *
 * Past het bedrag (nog) nergens bij, dan gaat de melding niet meteen uit: de
 * melding van Pay.nl is er vaak eerder dan de creditorder in het CMS. De job
 * zet zichzelf terug in de wachtrij volgens PaynlRefundRetrySchedule en meldt
 * pas als het 24 uur na de eerste melding nog steeds niet past. Het vangnet
 * draait elke nacht en probeert daarom één keer ($retry = false).
 */
class MatchPaynlRefundJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TAG_RETRYING = 'order.paynl-refund-retrying';

    /** Onbeperkt: release() telt als poging; retryUntil() en $maxExceptions begrenzen. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** Langer dan de hele reeks, anders start een tweede melding een tweede reeks. */
    public int $uniqueFor = PaynlRefundRetrySchedule::WINDOW + 3600;

    /** Tijdstip van de eerste melding; gaat mee in de payload en overleeft dus elke release(). */
    public int $firstReportedAt = 0;

    public bool $retry = true;

    public function __construct(public OrderPayment $payment, bool $retry = true)
    {
        $this->retry = $retry;
        $this->firstReportedAt = now()->getTimestamp();
    }

    public function uniqueId(): string
    {
        return (string) $this->payment->id;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->setTimestamp($this->firstReportedAt + PaynlRefundRetrySchedule::WINDOW + 7200);
    }

    public function handle(PaynlRefundMatcher $matcher, PaynlRefundUnmatchedNotifier $notifier): void
    {
        $payment = $this->payment->fresh() ?? $this->payment;
        $match = $matcher->match($payment, notify: false);

        if ($match->outcome !== 'unmatched') {
            return;
        }

        $delay = $this->retry
            ? PaynlRefundRetrySchedule::nextDelay(max(1, $this->attempts()), now()->getTimestamp() - $this->firstReportedAt)
            : null;

        if ($delay === null) {
            $notifier->notify($payment, $match);

            return;
        }

        if ($this->attempts() <= 1) {
            $this->logRetrying($payment, $match);
        }

        $this->release($delay);
    }

    /** Eén regel bij de eerste mislukte poging, zodat op de bestelling te zien is dat er nog iets loopt. */
    protected function logRetrying(OrderPayment $payment, PaynlRefundMatch $match): void
    {
        if (! $payment->order) {
            return;
        }

        OrderLog::createLog(
            orderId: $payment->order->id,
            tag: self::TAG_RETRYING,
            note: __('Pay.nl meldt :nieuw terugbetaald, nog niet te koppelen: :reden Wordt opnieuw geprobeerd tot :tot; past het dan nog niet, dan volgt een melding.', [
                'nieuw' => CurrencyHelper::formatPrice($match->newAmount),
                'reden' => $match->reason,
                'tot' => now()->setTimestamp($this->firstReportedAt + PaynlRefundRetrySchedule::WINDOW)->format('d-m-Y H:i'),
            ]),
        );
    }
}
