<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Illuminate\Support\Carbon;
use Dashed\DashedEcommerceCore\Models\OrderPayment;

/**
 * Bewaart de ruwe Pay.nl-staat (paymentDetails.stateName: PAID, PENDING, VERIFY,
 * CANCEL, EXPIRED, DENIED_xx) op de betaling. getOrderStatus() vertaalt alles
 * naar paid/cancelled/pending en gooit daarmee het verschil weg tussen een
 * klant die zelf afbreekt (CANCEL) en een betaalmethode die weigert (DENIED,
 * bij Riverty de kredietcheck). De shop leest de staat via
 * OrderPayment::isDeclined() (ec-core ≥ 4.147).
 */
class PaynlTransactionState
{
    public const ATTRIBUTE = 'psp_state';

    public const ATTRIBUTE_AT = 'psp_state_at';

    public static function fromTransactionData(array $data): ?string
    {
        $state = $data['paymentDetails']['stateName'] ?? null;

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        return strtoupper(trim($state));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function mergeInto(array $attributes, ?string $state, Carbon $now): array
    {
        if ($state === null) {
            return $attributes;
        }

        return array_merge($attributes, [
            self::ATTRIBUTE => $state,
            self::ATTRIBUTE_AT => $now->toIso8601String(),
        ]);
    }

    /**
     * Vastleggen mag een statuscheck nooit tegenhouden: elke fout wordt
     * gerapporteerd en verder genegeerd.
     */
    public static function store(OrderPayment $payment, array $data): void
    {
        $state = self::fromTransactionData($data);

        if ($state === null) {
            return;
        }

        try {
            $attributes = $payment->getAttribute('attributes');
            $payment->setAttribute('attributes', self::mergeInto(is_array($attributes) ? $attributes : [], $state, Carbon::now()));
            $payment->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
