# Changelog

All notable changes to `dashed-ecommerce-paynl` will be documented in this file.

## v4.3.0 - 2026-09-17

### Added
- **Terugbetalingen bij Pay.nl worden gekoppeld aan de creditorder van een retour.** `PaynlRefundMatcher` (via `MatchPaynlRefundJob`, gestart door `PaymentRefundReportedEvent` uit ec-core en door het nachtelijke `paynl:match-refunds`) boekt een exact passend bedrag via `RefundRegistrar` en mailt de klant; alles wat niet exact past wordt gemeld met `AdminPaynlRefundUnmatchedMail` en orderlog `order.paynl-refund-unmatched`, en niet geboekt. Vereist dashed-ecommerce-core met `PaymentRefundReportedEvent`; zonder dat event werkt alleen het vangnet.

### Fixed
- **De exchange van een terugbetaling werd weggegooid door de idempotentie-middleware.** De `event_id` van Pay.nl was alleen de transactie-id, dus een `action=refund` weken na `action=paid` werd gededupliceerd tegen de betaling van toen en kwam nooit bij de controller aan. De event-id is nu `<transactie>:<action>` zodra de aanroep een `action` meestuurt (Pay.nl stuurt `new_ppt`, `pending`, `paid`, `cancel`, `refund`, `chargeback`), en zonder `action` de kale transactie-id zoals voorheen. Retries van dezelfde actie worden nog steeds afgevangen.
- Een niet-gekoppelde terugbetaling valt voor de melding terug op de superadmins als er geen meldingsadressen zijn ingesteld; de anti-herhalingssleutel wordt pas geclaimd als de mail echt verstuurd is, zodat een mailstoring de melding niet dertig dagen het zwijgen oplegt.
- Een terugbetaling die intussen al met de hand geregistreerd bleek, laat nu een orderlog `order.paynl-refund-skipped` achter in plaats van geruisloos te verdwijnen.
- `PaynlTransactions::refundedAmount()` doet één API-aanroep (`Transaction::status()`) in plaats van twee.

## v4.1.3 - 2026-07-19

### Fixed
- `PayNL::startTransaction()` gooide `PAY-2008 - Parameter enduser.dob is invalid: Expected value to be of type string, string given` waardoor de betaling niet startte. `date_of_birth` is een ongecaste `date`-kolom en werd als ruwe `Y-m-d`-string (of leeg) doorgegeven, terwijl PayNL `DD-MM-YYYY` verwacht. De fout trad ook op wanneer er geen geboortedatum was ingevuld, omdat de `dob`-key dan met een lege/ongeldige waarde werd meegestuurd. Nieuwe `PayNL::formatDateOfBirth()` zet de waarde om naar `DD-MM-YYYY` en geeft `null` terug bij leeg/whitespace/onparseerbaar/`0000-00-00`/toekomstige datum; de `dob`-key wordt nu alleen meegestuurd als er een geldige datum is.

## v4.0.4 - 2026-05-03

### Fixed
- `PayNL::startTransaction()` gooide `PAY-405 - Parameter 'gender' is invalid: Input is too long, maximum length is 1` als de bestelling een gender-waarde van meer dan 1 teken had (bv. `FEMALE`, `MALE`, `UNKNOWN`). Gender wordt nu genormaliseerd naar `M`, `F` of `null` voordat het naar PayNL gaat. Symptoom in productie: betaallinks toonden de generieke "Er is momenteel geen betaalprovider beschikbaar"-pagina omdat de exception in de `RemainderPaymentController` werd opgevangen.
- Null-safe operators op `$orderPayment->paymentMethod` in `paymentMethod` + `bank` velden van `startTransaction()`. Bij betaallinks is er geen `paymentMethod` gekoppeld aan de `OrderPayment`, wat PHP-warnings veroorzaakte (`Attempt to read property "pinTerminal" on null`).

## 1.0.0 - 202X-XX-XX

- initial release
