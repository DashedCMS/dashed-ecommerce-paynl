<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommercePaynl\ExpressCheckout\WalletContact;

final class WalletContactTest extends TestCase
{
    public function test_apple_pay_contact_wordt_checkoutvelden(): void
    {
        $fields = WalletContact::normalize([
            'givenName' => 'Anna',
            'familyName' => 'de Vries',
            'emailAddress' => 'anna@example.com',
            'phoneNumber' => '+31612345678',
            'addressLines' => ['Keizersgracht 12 A'],
            'locality' => 'Amsterdam',
            'postalCode' => '1015 CS',
            'countryCode' => 'nl',
        ]);

        $this->assertSame([
            'firstName' => 'Anna',
            'lastName' => 'de Vries',
            'email' => 'anna@example.com',
            'phoneNumber' => '+31612345678',
            'street' => 'Keizersgracht',
            'houseNr' => '12 A',
            'zipCode' => '1015 CS',
            'city' => 'Amsterdam',
            'countryCode' => 'NL',
        ], $fields);
    }

    public function test_google_pay_adres_met_een_naamveld_en_losse_email(): void
    {
        $fields = WalletContact::normalize([
            'email' => 'jan@example.com',
            'shippingAddress' => [
                'name' => 'Jan Peter Jansen',
                'address1' => 'Rue de la Loi 16',
                'address2' => 'bus 2',
                'locality' => 'Brussel',
                'postalCode' => '1000',
                'countryCode' => 'BE',
                'phoneNumber' => '+3221234567',
            ],
        ]);

        $this->assertSame('Jan Peter', $fields['firstName']);
        $this->assertSame('Jansen', $fields['lastName']);
        $this->assertSame('jan@example.com', $fields['email']);
        $this->assertSame('Rue de la Loi', $fields['street']);
        $this->assertSame('16 bus 2', $fields['houseNr']);
        $this->assertSame('BE', $fields['countryCode']);
        $this->assertSame('+3221234567', $fields['phoneNumber']);
    }

    public function test_pay_parts_customer_met_eigen_straat_en_huisnummer(): void
    {
        $fields = WalletContact::normalize([
            'firstName' => 'Kees',
            'lastName' => 'Bakker',
            'email' => 'kees@example.com',
            'phone' => '0612345678',
            'shippingAddress' => [
                'streetName' => 'Dorpsstraat',
                'houseNumber' => '3',
                'zipCode' => '1234AB',
                'city' => 'Ergens',
                'countryCode' => 'NL',
            ],
        ]);

        $this->assertSame('Dorpsstraat', $fields['street']);
        $this->assertSame('3', $fields['houseNr']);
        $this->assertSame('1234AB', $fields['zipCode']);
        $this->assertSame('0612345678', $fields['phoneNumber']);
    }

    public function test_straat_splitsen(): void
    {
        $this->assertSame(['Rue du 4 Septembre', '10'], WalletContact::splitStreet(['Rue du 4 Septembre 10']));
        $this->assertSame(['Rue de la Paix', '12'], WalletContact::splitStreet(['12 Rue de la Paix']));
        $this->assertSame(['Hoofdstraat', '1-3'], WalletContact::splitStreet(['Hoofdstraat 1-3']));
        $this->assertSame(['Hoofdstraat', '7'], WalletContact::splitStreet(['Hoofdstraat', '7']));
        $this->assertSame(['Zonder nummer', ''], WalletContact::splitStreet(['Zonder nummer']));
        $this->assertSame(['', ''], WalletContact::splitStreet([]));
    }

    public function test_alleen_postcode_en_land_zoals_apple_voor_autorisatie_geeft(): void
    {
        $fields = WalletContact::normalize(['postalCode' => '1015', 'locality' => 'Amsterdam', 'countryCode' => 'NL']);

        $this->assertSame('', $fields['street']);
        $this->assertSame('', $fields['email']);
        $this->assertSame('NL', $fields['countryCode']);
    }

    public function test_geen_tekst_wordt_leeg(): void
    {
        $fields = WalletContact::normalize(['givenName' => ['x'], 'emailAddress' => null]);

        $this->assertSame('', $fields['firstName']);
        $this->assertSame('', $fields['email']);
    }
}
