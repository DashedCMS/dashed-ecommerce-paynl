<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Dashed\DashedEcommerceCore\Models\Order;

/**
 * Uitkomst van één koppelpoging. 'nothing': niets nieuws bij Pay.nl of geen
 * Pay.nl-betaling; 'registered': geboekt op $creditOrder; 'unmatched': er is
 * een nieuw bedrag maar geen exacte kandidaat (zie $reason); 'failed': Pay.nl
 * was niet te bereiken.
 */
class PaynlRefundMatch
{
    public function __construct(
        public string $outcome,
        public float $refundedAtPaynl = 0.0,
        public float $registeredBefore = 0.0,
        public float $newAmount = 0.0,
        public ?Order $creditOrder = null,
        public string $reason = '',
        /** @var array<int, float> creditorder-id => bedrag van de kandidaten met herkomstretour */
        public array $candidates = [],
        /** @var array<int, float> creditorder-id => bedrag van open creditorders zonder herkomstretour */
        public array $otherOpenCreditOrders = [],
    ) {
    }
}
