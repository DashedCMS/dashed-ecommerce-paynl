<?php

namespace Dashed\DashedEcommercePaynl\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommercePaynl\Classes\PaynlRefundMatcher;

/**
 * Enige ingang naar de matcher: de exchange-listener en het nachtelijke
 * vangnet dispatchen allebei deze job. Uniek per betaling, zodat webhook en
 * vangnet elkaar niet dubbel laten draaien; de matcher zelf is bovendien
 * idempotent.
 */
class MatchPaynlRefundJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public OrderPayment $payment)
    {
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

    public function handle(PaynlRefundMatcher $matcher): void
    {
        $matcher->match($this->payment->fresh() ?? $this->payment);
    }
}
