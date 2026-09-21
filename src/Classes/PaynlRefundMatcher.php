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
 * kandidaat wordt geboekt; staat het bedrag al als andere terugbetaling op een
 * creditorder en is er niets meer open, dan is het 'covered' (zie
 * coveredElsewhere()); al het andere is 'unmatched' en wordt gemeld
 * (PaynlRefundUnmatchedNotifier). Boeken gebeurt uitsluitend via
 * RefundRegistrar, met zijn lock en zijn guard tegen dubbel boeken.
 */
class PaynlRefundMatcher
{
    public const TAG_FAILED = 'order.paynl-refund-check-failed';

    public const TAG_SKIPPED = 'order.paynl-refund-skipped';

    public const TAG_COVERED = 'order.paynl-refund-covered';

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
        $candidateAmounts = $candidates->mapWithKeys(fn (Order $c) => [$c->id => $this->refundableFor($c)])->all();
        $otherAmounts = $others->mapWithKeys(fn (Order $c) => [$c->id => round(abs((float) $c->total), 2)])->all();

        $exact = $candidates->filter(fn (Order $c) => abs($candidateAmounts[$c->id] - $new) < 0.01)->values();

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

        // Alleen als er niets meer openstaat: ligt er nog een open creditorder,
        // dan hoort het Pay.nl-bedrag waarschijnlijk daarbij en blijft het een melding.
        if ($candidates->isEmpty() && $others->isEmpty() && $covered = $this->coveredElsewhere($order, $refunded, $registered, $new)) {
            return $covered;
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

    /**
     * Een annulering in het CMS maakt een creditorder zonder retour met meteen
     * een betaalde terugbetaling (vaak "Contant"), terwijl het geld in
     * werkelijkheid via Pay.nl teruggaat. Dekken zulke niet-Pay.nl-boekingen
     * op creditorders van deze bestelling het nieuwe bedrag, dan staat de
     * terugbetaling al in het CMS: niets boeken en niet alarmeren, alleen één
     * orderlog. Pay.nl-boekingen tellen hier niet mee; die van deze transactie
     * zitten al in registeredBefore() en die van een andere transactie zeggen
     * niets over deze.
     */
    protected function coveredElsewhere(Order $order, float $refunded, float $registered, float $new): ?PaynlRefundMatch
    {
        $creditOrderIds = Order::query()->where('credit_for_order_id', $order->id)->pluck('id');

        $payments = OrderPayment::query()
            ->whereIn('order_id', $creditOrderIds)
            ->where('status', 'paid')
            ->where(fn ($q) => $q->whereNull('psp')->orWhere('psp', '!=', 'paynl'))
            ->orderBy('id')
            ->get(['order_id', 'payment_method', 'amount']);

        $booked = round((float) $payments->sum(fn (OrderPayment $p) => abs((float) $p->amount)), 2);
        if ($booked + 0.005 < $new) {
            return null;
        }

        $invoices = Order::query()->whereIn('id', $payments->pluck('order_id')->unique())->orderBy('id')->pluck('invoice_id')->implode(', ');
        $methods = $payments->pluck('payment_method')->filter()->unique()->implode(', ') ?: __('onbekende methode');

        $note = __('Pay.nl meldt :nieuw terugbetaald; dat staat al geboekt op creditorder :factuur (:methode, :geboekt). Niets geboekt. Klopt de betaalmethode niet, pas die dan aan; is er ook echt :methode terugbetaald, dan is er dubbel terugbetaald.', [
            'nieuw' => CurrencyHelper::formatPrice($new),
            'factuur' => $invoices,
            'methode' => $methods,
            'geboekt' => CurrencyHelper::formatPrice($booked),
        ]);

        // Het nachtelijke vangnet komt elke nacht langs: dezelfde regel maar één keer.
        if (! OrderLog::query()->where('order_id', $order->id)->where('tag', self::TAG_COVERED)->where('note', $note)->exists()) {
            OrderLog::createLog(orderId: $order->id, tag: self::TAG_COVERED, note: $note);
        }

        return new PaynlRefundMatch('covered', $refunded, $registered, $new, null, $note);
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
    /**
     * Het bedrag dat bij Pay.nl terug hoort te komen: bij een order op rekening
     * is een deel van de creditering vaak al met de openstaande factuur
     * verrekend, en dan wordt alleen de rest terugbetaald.
     */
    protected function refundableFor(Order $creditOrder): float
    {
        return method_exists($creditOrder->originReturn, 'refundableAmount')
            ? round($creditOrder->originReturn->refundableAmount(), 2)
            : round(abs((float) $creditOrder->total), 2);
    }

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
