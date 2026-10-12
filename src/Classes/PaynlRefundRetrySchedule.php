<?php

namespace Dashed\DashedEcommercePaynl\Classes;

/**
 * Wanneer een nog niet te koppelen Pay.nl-terugbetaling opnieuw geprobeerd
 * wordt. De melding van Pay.nl komt vaak binnen vóórdat de creditorder in het
 * CMS bestaat (eerst terugbetalen, dan annuleren of de retour verwerken), dus
 * de matcher probeert het met opbouwende tussenpozen opnieuw: 2, 5, 10, 20 en
 * 40 minuten, daarna elk uur, tot 24 uur na de eerste melding. De laatste
 * poging valt precies op die 24 uur; pas daarna gaat de melding uit.
 */
class PaynlRefundRetrySchedule
{
    public const WINDOW = 86400;

    /** @var array<int, int> seconden tot de volgende poging, na poging 1, 2, 3, … */
    public const DELAYS = [120, 300, 600, 1200, 2400, 3600];

    /**
     * @param  int  $attempt  de poging die net gedaan is, vanaf 1
     * @param  int  $elapsed  seconden sinds de eerste melding
     * @return int|null seconden tot de volgende poging, of null als de 24 uur om zijn
     */
    public static function nextDelay(int $attempt, int $elapsed): ?int
    {
        $remaining = self::WINDOW - $elapsed;
        if ($remaining <= 0) {
            return null;
        }

        $delay = self::DELAYS[min(max($attempt, 1), count(self::DELAYS)) - 1];

        return min($delay, $remaining);
    }
}
