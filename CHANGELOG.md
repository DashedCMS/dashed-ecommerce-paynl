# Changelog

All notable changes to `dashed-ecommerce-paynl` will be documented in this file.

## v4.0.4 - 2026-05-03

### Fixed
- `PayNL::startTransaction()` gooide `PAY-405 - Parameter 'gender' is invalid: Input is too long, maximum length is 1` als de bestelling een gender-waarde van meer dan 1 teken had (bv. `FEMALE`, `MALE`, `UNKNOWN`). Gender wordt nu genormaliseerd naar `M`, `F` of `null` voordat het naar PayNL gaat. Symptoom in productie: betaallinks toonden de generieke "Er is momenteel geen betaalprovider beschikbaar"-pagina omdat de exception in de `RemainderPaymentController` werd opgevangen.
- Null-safe operators op `$orderPayment->paymentMethod` in `paymentMethod` + `bank` velden van `startTransaction()`. Bij betaallinks is er geen `paymentMethod` gekoppeld aan de `OrderPayment`, wat PHP-warnings veroorzaakte (`Attempt to read property "pinTerminal" on null`).

## 1.0.0 - 202X-XX-XX

- initial release
