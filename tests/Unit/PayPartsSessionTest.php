<?php

namespace Tests\Unit;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommercePaynl\ExpressCheckout\PayPartsSession;

final class PayPartsSessionTest extends TestCase
{
    public function test_payload_in_centen_met_verplichte_urls(): void
    {
        $payload = PayPartsSession::payload(
            amountCents: 4995,
            reference: 'EXPABC',
            returnUrl: 'https://shop.test/bedankt',
            exchangeUrl: 'https://shop.test/exchange',
            description: 'Een hele lange winkelnaam die langer is dan toegestaan',
            products: [['id' => 'SKU1', 'description' => 'Vaas', 'price' => 4995, 'quantity' => 1, 'vatPercentage' => 21]],
            countryCode: 'nl',
        );

        $this->assertSame(['value' => 4995, 'currency' => 'EUR'], $payload['amount']);
        $this->assertSame('https://shop.test/bedankt', $payload['returnUrl']);
        $this->assertSame('https://shop.test/exchange', $payload['exchangeUrl']);
        $this->assertSame('EXPABC', $payload['reference']);
        $this->assertSame(32, mb_strlen($payload['description']));
        $this->assertSame('NL', $payload['order']['countryCode']);
        $this->assertSame(['value' => 4995, 'currency' => 'EUR'], $payload['order']['products'][0]['price']);
        $this->assertSame('ARTICLE', $payload['order']['products'][0]['type']);
    }

    public function test_geen_order_zonder_land_en_producten(): void
    {
        $payload = PayPartsSession::payload(100, 'R', 'https://a.test', 'https://b.test', 'X');

        $this->assertArrayNotHasKey('order', $payload);
    }

    public function test_weigert_nul_euro(): void
    {
        $this->expectException(RuntimeException::class);

        PayPartsSession::payload(0, 'R', 'https://a.test', 'https://b.test', 'X');
    }

    public function test_omgevingen(): void
    {
        $this->assertSame('https://parts.pay.nl', PayPartsSession::baseUrl('production'));
        $this->assertSame('https://zero-parts.pay.nl', PayPartsSession::baseUrl('zero'));
        $this->assertSame('https://parts.pay.nl', PayPartsSession::baseUrl('iets anders'));
        $this->assertSame('https://parts.pay.nl/sdk/payparts-sdk.js', PayPartsSession::sdkUrl('production'));
    }
}
