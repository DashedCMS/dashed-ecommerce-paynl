<?php

namespace Dashed\DashedEcommercePaynl\ExpressCheckout;

use RuntimeException;
use Illuminate\Support\Facades\Http;

/**
 * Een sessie bij Pay.Parts (PayParts.js), nodig om de express-knoppen van
 * Pay. op onze eigen pagina te tonen. De backend start de sessie met
 * Basic-auth op de Sales Location (SL-code + secret van de SL, niet de
 * AT-token) en geeft alleen het sessionToken aan de browser.
 *
 * Bron: https://developer.pay.nl/docs/create-a-session-from-your-backend
 */
class PayPartsSession
{
    public const PRODUCTION_URL = 'https://parts.pay.nl';

    public const ZERO_URL = 'https://zero-parts.pay.nl';

    public static function baseUrl(string $environment): string
    {
        return $environment === 'zero' ? self::ZERO_URL : self::PRODUCTION_URL;
    }

    public static function sdkUrl(string $environment): string
    {
        return self::baseUrl($environment) . '/sdk/payparts-sdk.js';
    }

    /**
     * Zuiver: bouwt de body van POST /v1/session/start. Bedragen in centen.
     *
     * @param  array<int, array{id: string, description: string, price: int, quantity: int|float, vatPercentage: float}>  $products
     * @return array<string, mixed>
     */
    public static function payload(
        int $amountCents,
        string $reference,
        string $returnUrl,
        string $exchangeUrl,
        string $description,
        array $products = [],
        ?string $countryCode = null,
    ): array {
        if ($amountCents < 1) {
            throw new RuntimeException('Een Pay.Parts-sessie heeft een bedrag van minstens 1 cent nodig.');
        }

        $payload = [
            'amount' => ['value' => $amountCents, 'currency' => 'EUR'],
            'returnUrl' => $returnUrl,
            'exchangeUrl' => $exchangeUrl,
            'description' => mb_substr($description, 0, 32),
            'reference' => $reference,
        ];

        $order = [];
        if ($countryCode) {
            $order['countryCode'] = strtoupper($countryCode);
        }

        $lines = [];
        foreach ($products as $product) {
            $lines[] = [
                'id' => mb_substr((string) $product['id'], 0, 64),
                'description' => mb_substr((string) $product['description'], 0, 255),
                'type' => $product['type'] ?? 'ARTICLE',
                'price' => ['value' => (int) $product['price'], 'currency' => 'EUR'],
                'quantity' => $product['quantity'],
                'vatPercentage' => (float) $product['vatPercentage'],
            ];
        }
        if ($lines !== []) {
            $order['products'] = $lines;
        }

        if ($order !== []) {
            $payload['order'] = $order;
        }

        return $payload;
    }

    /**
     * Start de sessie. Geeft sessionId en sessionToken terug, of gooit.
     *
     * @param  array<string, mixed>  $payload
     * @return array{sessionId: string, sessionToken: string}
     */
    public static function start(array $payload, string $slCode, string $secret, string $environment = 'production'): array
    {
        $response = Http::withBasicAuth($slCode, $secret)
            ->acceptJson()
            ->timeout(15)
            ->post(self::baseUrl($environment) . '/v1/session/start', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Pay.Parts-sessie niet gestart: HTTP ' . $response->status() . ' ' . mb_substr($response->body(), 0, 300));
        }

        $sessionId = (string) ($response->json('sessionId') ?? '');
        $sessionToken = (string) ($response->json('sessionToken') ?? '');

        if ($sessionId === '' || $sessionToken === '') {
            throw new RuntimeException('Pay.Parts gaf geen sessionId of sessionToken terug.');
        }

        return ['sessionId' => $sessionId, 'sessionToken' => $sessionToken];
    }
}
