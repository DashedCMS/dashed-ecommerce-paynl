<?php

namespace Dashed\DashedEcommercePaynl\ExpressCheckout;

/**
 * Zet de contactgegevens uit een wallet om naar de velden van de checkout.
 *
 * Drie vormen komen binnen, afhankelijk van wie het betaalblad toont:
 *  - Apple Pay (ApplePayPaymentContact): givenName, familyName, emailAddress,
 *    phoneNumber, addressLines[], locality, postalCode, countryCode;
 *  - Google Pay (Address uit PaymentData): name, address1..3, locality,
 *    postalCode, countryCode, phoneNumber, met e-mail los ernaast;
 *  - Pay.Parts (customer uit de SDK): firstName, lastName, email, phone en
 *    shippingAddress met streetName/houseNumber/zipCode/city/countryCode.
 *
 * Zuiver: geen database en geen Laravel, zodat het zonder app te testen is.
 */
class WalletContact
{
    /**
     * @param  array<string, mixed>  $contact
     * @return array{firstName: string, lastName: string, email: string, phoneNumber: string, street: string, houseNr: string, zipCode: string, city: string, countryCode: string}
     */
    public static function normalize(array $contact): array
    {
        $address = is_array($contact['shippingAddress'] ?? null) ? $contact['shippingAddress'] : $contact;

        [$firstName, $lastName] = self::name($contact, $address);

        $street = self::text($address['streetName'] ?? $address['street'] ?? null);
        $houseNr = self::text($address['houseNumber'] ?? $address['streetNumber'] ?? null);

        if ($street === '') {
            $lines = self::addressLines($address);
            [$street, $houseNr] = self::splitStreet($lines);
        }

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => self::text($contact['emailAddress'] ?? $contact['email'] ?? $address['emailAddress'] ?? $address['email'] ?? null),
            'phoneNumber' => self::text($contact['phoneNumber'] ?? $contact['phone'] ?? $address['phoneNumber'] ?? $address['phone'] ?? null),
            'street' => $street,
            'houseNr' => $houseNr,
            'zipCode' => self::text($address['postalCode'] ?? $address['zipCode'] ?? null),
            'city' => self::text($address['locality'] ?? $address['city'] ?? null),
            'countryCode' => strtoupper(self::text($address['countryCode'] ?? null)),
        ];
    }

    /**
     * Straat en huisnummer uit de vrije adresregels van een wallet.
     * "Keizersgracht 12 A" geeft ["Keizersgracht", "12 A"], "12 Rue de la Paix"
     * geeft ["Rue de la Paix", "12"]. Een tweede regel (zoals "bus 2") komt
     * achter het huisnummer; zonder herkenbaar nummer blijft het nummer leeg en
     * weigert de checkout de order met zijn eigen melding.
     *
     * @param  array<int, string>  $lines
     * @return array{0: string, 1: string}
     */
    public static function splitStreet(array $lines): array
    {
        $lines = array_values(array_filter(array_map(fn ($line) => self::text($line), $lines), fn ($line) => $line !== ''));
        $first = $lines[0] ?? '';
        $rest = trim(implode(' ', array_slice($lines, 1)));

        if (preg_match('/^(.*\D)[\s,]+(\d+\S*(?:\s+\S{1,4})?)$/u', $first, $m)) {
            $street = trim($m[1], " ,");
            $houseNr = trim($m[2]);
        } elseif (preg_match('/^(\d+\S*)[\s,]+(.+)$/u', $first, $m)) {
            $street = trim($m[2]);
            $houseNr = trim($m[1]);
        } else {
            $street = $first;
            $houseNr = '';

            if ($rest !== '' && preg_match('/^\d/', $rest)) {
                return [$street, $rest];
            }
        }

        if ($rest !== '' && $houseNr !== '') {
            $houseNr .= ' ' . $rest;
        }

        return [$street, $houseNr];
    }

    /**
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $address
     * @return array{0: string, 1: string}
     */
    private static function name(array $contact, array $address): array
    {
        $first = self::text($contact['givenName'] ?? $contact['firstName'] ?? $address['givenName'] ?? $address['firstName'] ?? null);
        $last = self::text($contact['familyName'] ?? $contact['lastName'] ?? $address['familyName'] ?? $address['lastName'] ?? null);

        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }

        // Google geeft één naamveld: alles tot de laatste spatie is de voornaam.
        $full = self::text($address['name'] ?? $contact['name'] ?? null);
        if ($full === '') {
            return ['', ''];
        }

        $position = mb_strrpos($full, ' ');
        if ($position === false) {
            return ['', $full];
        }

        return [trim(mb_substr($full, 0, $position)), trim(mb_substr($full, $position + 1))];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<int, string>
     */
    private static function addressLines(array $address): array
    {
        if (is_array($address['addressLines'] ?? null)) {
            return array_map(fn ($line) => self::text($line), $address['addressLines']);
        }

        return [
            self::text($address['address1'] ?? null),
            self::text($address['address2'] ?? null),
            self::text($address['address3'] ?? null),
        ];
    }

    private static function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $value)), 0, 255);
    }
}
