<?php

namespace Dashed\DashedEcommercePaynl\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Dashed\DashedEcommercePaynl\ExpressCheckout\ExpressCheckout;

/**
 * Apple's domeinverificatie voor Apple Pay op onze eigen pagina's. Pay. levert
 * het bestand; de inhoud staat in de instelling `paynl_apple_domain_association`.
 * Werkt los van de express-schakelaar: Apple en Pay. moeten het domein kunnen
 * verifiëren vóórdat de knop aan gaat. Leeg = 404, zoals voorheen.
 */
class AppleDomainAssociationController extends Controller
{
    public function __invoke(): Response
    {
        $content = ExpressCheckout::appleDomainAssociation();

        abort_if($content === '', 404);

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
