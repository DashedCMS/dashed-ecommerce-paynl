<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Throwable;
use InvalidArgumentException;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Classes\CurrencyHelper;
use Dashed\DashedEcommerceCore\Services\OrderReturn\RefundRegistrar;

/**
 * Koppelt wat er bij Pay.nl op een betaling is terugbetaald aan de creditorder
 * van een verwerkte retour. Rekent altijd met het cumulatieve Pay.nl-bedrag
 * minus wat wij al onder dezelfde transactie boekten, dus een tweede aanroep
 * zonder nieuw bedrag doet niets. Alleen een exacte treffer op precies één
 * kandidaat wordt geboekt; al het andere is 'unmatched' en wordt gemeld
 * (PaynlRefundUnmatchedNotifier). Boeken gebeurt uitsluitend via
 * RefundRegistrar, met zijn lock en zijn guard tegen dubbel boeken.
 */
class PaynlRefundMatcher
{
    public const TAG_FAILED = 'order.paynl-refund-check-failed';

    public const TAG_SKIPPED = 'order.paynl-refund-skipped';

    public function __construct(
        protected PaynlTransactions $transactions,
        protected RefundRegistrar $registrar,
        protected PaynlRefundUnmatchedNotifier $notifier,
    ) {
    }

    public function match(OrderPayment $payment): PaynlRefundMatch
    {
        $order = $payment->order;
        if ($payment->psp !== 'paynl' || $payment->status !== 'paid' || ! $payment->psp_id || ! $order) {
            return new PaynlRefundMatch('nothing');
        }

        try {
            $refunded = (float) ($this->transactions->refundedAmount($payment) ?? 0.0);
        } catch (Throwable $e) {
            OrderLog::createLog(
                orderId: $order->id,
                tag: self::TAG_FAILED,
                note: __('Pay.nl-terugbetaling kon niet gecontroleerd worden: :fout', ['fout' => $e->getMessage()]),
            );

            throw $e;
        }

        $registered = $this->registeredBefore($order, $payment->psp_id);
        $new = round($refunded - $registered, 2);

        if ($new < 0.01) {
            return new PaynlRefundMatch('nothing', $refunded, $registered, max(0.0, $new));
        }

        [$candidates, $others] = $this->openCreditOrders($order);
        $candidateAmounts = $candidates->mapWithKeys(fn (Order $c) => [$c->id => round(abs((float) $c->total), 2)])->all();
        $otherAmounts = $others->mapWithKeys(fn (Order $c) => [$c->id => round(abs((float) $c->total), 2)])->all();

        $exact = $candidates->filter(fn (Order $c) => abs(round(abs((float) $c->total), 2) - $new) < 0.01)->values();

        if ($exact->count() === 1) {
            /** @var Order $creditOrder */
            $creditOrder = $exact->first();

            try {
                $this->registrar->register(
                    $creditOrder->originReturn,
                    $new,
                    'Pay.nl',
                    'paynl',
                    ['psp_id' => $payment->psp_id, 'paynl_refunded_amount' => $refunded],
                );
            } catch (InvalidArgumentException $e) {
                // Race met een handmatige registratie: inmiddels terugbetaald is 'nothing',
                // anders een gewone unmatched met de reden van de registrar.
                if ($creditOrder->fresh()->orderPayments()->where('status', 'paid')->exists()) {
                    // Wel een spoor: zonder orderlog verdwijnt een overgeslagen
                    // bedrag hier geruisloos en is later niet na te gaan waarom
                    // Pay.nl meer meldt dan er geboekt staat.
                    OrderLog::createLog(
                        orderId: $order->id,
                        tag: self::TAG_SKIPPED,
                        note: __('Pay.nl-terugbetaling overgeslagen: :nieuw was intussen al geregistreerd op creditorder :factuur. :fout', [
                            'nieuw' => CurrencyHelper::formatPrice($new),
                            'factuur' => $creditOrder->invoice_id,
                            'fout' => $e->getMessage(),
                        ]),
                    );

                    return new PaynlRefundMatch('nothing', $refunded, $registered, $new);
                }

                $match = new PaynlRefundMatch('unmatched', $refunded, $registered, $new, null, $e->getMessage(), $candidateAmounts, $otherAmounts);
                $this->notifier->notify($payment, $match);

                return $match;
            }

            return new PaynlRefundMatch('registered', $refunded, $registered, $new, $creditOrder, '', $candidateAmounts, $otherAmounts);
        }

        $reason = match (true) {
            $candidates->isEmpty() => __('Geen openstaande creditorder met retour voor deze bestelling.'),
            $exact->count() > 1 => __('Meer dan één openstaande creditorder met dit bedrag.'),
            default => __('Het terugbetaalde bedrag past bij geen enkele openstaande creditorder.'),
        };

        $match = new PaynlRefundMatch('unmatched', $refunded, $registered, $new, null, $reason, $candidateAmounts, $otherAmounts);
        $this->notifier->notify($payment, $match);

        return $match;
    }

    /** Wat er al onder deze Pay.nl-transactie op creditorders van deze bestelling is geboekt. */
    protected function registeredBefore(Order $order, string $pspId): float
    {
        $creditOrderIds = Order::query()->where('credit_for_order_id', $order->id)->pluck('id');

        $sum = OrderPayment::query()
            ->whereIn('order_id', $creditOrderIds)
            ->where('status', 'paid')
            ->where('psp', 'paynl')
            ->where('psp_id', $pspId)
            ->get(['amount'])
            ->sum(fn (OrderPayment $p) => abs((float) $p->amount));

        return round((float) $sum, 2);
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Order>, 1: \Illuminate\Support\Collection<int, Order>}
     *   [kandidaten met herkomstretour, overige open creditorders]
     */
    protected function openCreditOrders(Order $order): array
    {
        $open = Order::query()
            ->where('credit_for_order_id', $order->id)
            ->where('status', 'return')
            ->whereDoesntHave('orderPayments', fn ($q) => $q->where('status', 'paid'))
            ->with('originReturn')
            ->orderBy('id')
            ->get();

        return [
            $open->filter(fn (Order $c) => $c->originReturn !== null)->values(),
            $open->filter(fn (Order $c) => $c->originReturn === null)->values(),
        ];
    }
}
