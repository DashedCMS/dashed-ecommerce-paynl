<?php

namespace Dashed\DashedEcommercePaynl\Classes;

/**
 * Welke betalingen op een creditorder tellen als echt teruggegeven geld.
 * ec-core boekt cadeaubonnen (verzilvering en terugstorting op de bon) als
 * OrderPayment met psp 'giftcard'; dat is geen geldterugbetaling en mag de
 * Pay.nl-koppeling niet als "al terugbetaald" zien.
 */
class MoneyPayment
{
    public const GIFTCARD_PSP = 'giftcard';

    public static function isMoney(?string $psp): bool
    {
        return $psp !== self::GIFTCARD_PSP;
    }

    /** Query-constraint voor een OrderPayment-query: alleen geldbetalingen. */
    public static function scope($query)
    {
        return $query->where(fn ($q) => $q->whereNull('psp')->orWhere('psp', '!=', self::GIFTCARD_PSP));
    }
}
