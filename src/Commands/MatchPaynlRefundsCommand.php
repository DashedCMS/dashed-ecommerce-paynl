<?php

namespace Dashed\DashedEcommercePaynl\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommercePaynl\Jobs\MatchPaynlRefundJob;

/**
 * Vangnet naast de exchange-webhook: loopt de creditorders zonder betaling van
 * de afgelopen 90 dagen langs waarvan de oorspronkelijke bestelling met Pay.nl
 * betaald is, en zet per ouderbetaling één MatchPaynlRefundJob klaar. De job
 * is uniek per betaling en de matcher is idempotent, dus dit mag elke nacht.
 */
class MatchPaynlRefundsCommand extends Command
{
    protected $signature = 'paynl:match-refunds {--order= : Alleen deze oorspronkelijke bestelling (id), ongeacht de 90 dagen}';

    protected $description = 'Koppel terugbetalingen bij Pay.nl aan openstaande creditorders van retouren';

    public function handle(): int
    {
        $creditOrders = Order::query()
            ->where('status', 'return')
            ->whereNotNull('credit_for_order_id')
            ->whereDoesntHave('orderPayments', fn ($q) => $q->where('status', 'paid'))
            ->when(
                $this->option('order'),
                fn ($q, $orderId) => $q->where('credit_for_order_id', (int) $orderId),
                fn ($q) => $q->where('created_at', '>=', now()->subDays(90)),
            );

        $parentIds = $creditOrders->pluck('credit_for_order_id')->unique();

        $payments = OrderPayment::query()
            ->whereIn('order_id', $parentIds)
            ->where('status', 'paid')
            ->where('psp', 'paynl')
            ->whereNotNull('psp_id')
            ->orderBy('id')
            ->get()
            ->unique('id');

        foreach ($payments as $payment) {
            MatchPaynlRefundJob::dispatch($payment);
        }

        $this->info(__(':aantal job(s) klaargezet voor :orders bestelling(en).', ['aantal' => $payments->count(), 'orders' => $parentIds->count()]));

        return self::SUCCESS;
    }
}
