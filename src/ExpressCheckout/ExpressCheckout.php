<?php

namespace Dashed\DashedEcommercePaynl\ExpressCheckout;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommercePaynl\Classes\PayNL;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;

/**
 * Express-checkout (Apple Pay / Google Pay in één stap vanaf de wagen) met
 * Pay. als verwerker. Staat standaard UIT (`paynl_express_checkout_enabled`);
 * zonder die schakelaar geven de endpoints 404 en verandert er niets aan de
 * gewone betaalroute.
 *
 * Instellingen per site:
 *  - paynl_express_checkout_enabled  schakelaar, standaard uit
 *  - paynl_parts_secret              secret van de Sales Location (Pay.Parts)
 *  - paynl_parts_environment         'production' (standaard) of 'zero'
 *  - paynl_apple_domain_association  inhoud van Apple's domeinverificatiebestand
 */
class ExpressCheckout
{
    public const WALLETS = [
        'apple' => 2277,
        'google' => 2558,
    ];

    public const SESSION_KEY = 'paynl_express';

    /**
     * Gezet door de CheckoutRunner tijdens het plaatsen van een order binnen
     * een Pay.Parts-sessie: PayNL::startTransaction() start dan geen tweede
     * transactie bij Pay., maar koppelt de betaling aan deze sessie.
     *
     * @var array{session_id: string, reference: string}|null
     */
    public static ?array $partsLink = null;

    /** Id van de OrderPayment die PayNL::startTransaction() aan $partsLink koppelde. */
    public static ?int $linkedPaymentId = null;

    public static function enabled(?string $siteId = null): bool
    {
        $siteId ??= Sites::getActive();

        return (bool) Customsetting::get('paynl_express_checkout_enabled', $siteId, false)
            && (bool) Customsetting::get('paynl_connected', $siteId, false)
            && PayNL::serviceId($siteId) !== ''
            && self::partsSecret($siteId) !== '';
    }

    public static function partsSecret(?string $siteId = null): string
    {
        return trim((string) Customsetting::get('paynl_parts_secret', $siteId ?? Sites::getActive()));
    }

    public static function environment(?string $siteId = null): string
    {
        return Customsetting::get('paynl_parts_environment', $siteId ?? Sites::getActive(), 'production') === 'zero' ? 'zero' : 'production';
    }

    public static function appleDomainAssociation(?string $siteId = null): string
    {
        return trim((string) Customsetting::get('paynl_apple_domain_association', $siteId ?? Sites::getActive()));
    }

    public static function walletId(string $wallet): ?int
    {
        return self::WALLETS[$wallet] ?? null;
    }

    public static function isWalletMethodId(mixed $pspId): bool
    {
        return is_numeric($pspId) && in_array((int) $pspId, self::WALLETS, true);
    }

    /** De actieve Pay.-betaalmethode van deze site voor de wallet, of null. */
    public static function paymentMethod(string $wallet, ?string $siteId = null): ?PaymentMethod
    {
        $pspId = self::walletId($wallet);
        if (! $pspId) {
            return null;
        }

        return PaymentMethod::query()
            ->where('site_id', $siteId ?? Sites::getActive())
            ->where('psp', PayNL::PSP)
            ->where('psp_id', $pspId)
            ->where('active', 1)
            ->first();
    }

    /** Bedrag in centen zoals Pay. het wil. */
    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
