<?php

namespace Dashed\DashedEcommercePaynl\ExpressCheckout;

use Closure;
use Throwable;
use Livewire\Component;
use Illuminate\Support\Collection;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Classes\Countries;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Dashed\DashedEcommerceCore\Livewire\Frontend\Checkout\Checkout;

/**
 * Rekent en bestelt via de checkout-component van de site zelf (in Lovora
 * LovoraCheckout, geregistreerd als 'checkout.checkout'), zodat express precies
 * dezelfde prijzen, verzendregels, kortingen en weigeringen kent als de
 * gewone checkout. De component wordt buiten Livewire gebruikt: geen mount()
 * (die meldt een InitiateCheckout bij Meta en opent een meting), alleen de
 * instellingen die mount() zet, daarna de adresvelden uit de wallet.
 */
class CheckoutRunner
{
    /** Waar zodra deze runner de checkout gebruikt; projecten kunnen er hun eigen meting op overslaan. */
    public static bool $running = false;

    public function component(): Component
    {
        try {
            $component = app('livewire')->new('checkout.checkout');
        } catch (Throwable) {
            $component = new Checkout();
        }

        if (! $component instanceof Checkout) {
            $component = new Checkout();
        }

        return $component;
    }

    /**
     * Zet de component klaar voor de wagen en (zover bekend) het wallet-adres.
     *
     * @param  array<string, string>  $fields  uitkomst van WalletContact::normalize()
     */
    public function prime(Checkout $component, array $fields): ?string
    {
        cartHelper()->initialize($component->cartType);
        cartHelper()->setCartType($component->cartType);

        $component->accountRequired = (int) Customsetting::get('checkout_account', default: 2);
        $component->firstAndLastnameRequired = (int) Customsetting::get('checkout_form_name', default: 0);
        $component->companyRequired = (int) Customsetting::get('checkout_form_company_name', default: 2);
        $component->phoneNumberRequired = (int) Customsetting::get('checkout_form_phone_number_delivery_address', default: 0);
        $component->useDeliveryAddressAsInvoiceAddress = (int) (Customsetting::get('checkout_delivery_address_standard_invoice_address', default: 1) ?: 0);
        $component->invoiceAddress = ! Customsetting::get('checkout_delivery_address_standard_invoice_address');
        $component->isCompany = false;
        $component->countryList = Countries::getAllSelectedCountries();
        $component->customFields = $this->call($component, 'checkoutCustomFields', []);
        $component->customFieldRules = $this->call($component, 'checkoutCustomFieldRules', []);

        // Account verplicht: express kan geen account aanmaken.
        if ($component->accountRequired == 1 && auth()->guest()) {
            return 'account_required';
        }

        $country = $this->countryName($fields['countryCode'] ?? 'NL');
        // Een land zonder verzendzone krijgt geen verzendmethode; de checkout weigert de order dan zelf.
        if ($country === null) {
            return 'country_not_available';
        }

        $component->country = $country;
        $component->email = $fields['email'] ?? '';
        $component->firstName = $fields['firstName'] ?? '';
        $component->lastName = $fields['lastName'] ?? '';
        $component->phoneNumber = $fields['phoneNumber'] ?? '';
        $component->street = $fields['street'] ?? '';
        $component->houseNr = $fields['houseNr'] ?? '';
        $component->zipCode = $fields['zipCode'] ?? '';
        $component->city = $fields['city'] ?? '';
        $component->marketing = false;
        // De betaalknop staat naast de zin "Door te betalen ga je akkoord met de voorwaarden".
        $component->generalCondition = true;

        return null;
    }

    /**
     * Verzendopties en totalen voor het adres. Bedragen in centen.
     *
     * @param  array<string, string>  $fields
     * @return array{error?: string, shippingOptions?: array<int, array{id: string, label: string, amount: int}>, selectedShippingOption?: ?string, total?: int, shipping?: int, discount?: int, requiresShipping?: bool, walletAvailable?: bool}
     */
    public function quote(array $fields, string $wallet, ?string $shippingMethodId = null): array
    {
        return $this->run(function () use ($fields, $wallet, $shippingMethodId) {
            $component = $this->component();
            if ($error = $this->prime($component, $fields)) {
                return ['error' => $error];
            }

            if (! count($component->cartItems)) {
                return ['error' => 'cart_empty'];
            }

            $walletMethod = ExpressCheckout::paymentMethod($wallet);
            $component->paymentMethod = $walletMethod?->id;
            $component->fillPrices();

            $options = [];
            foreach ($this->shippingMethods($component) as $method) {
                $component->shippingMethod = $method['id'];
                $component->paymentMethod = $walletMethod?->id;
                $component->fillPrices();

                $options[] = [
                    'id' => (string) $method['id'],
                    'label' => $method['label'],
                    'amount' => ExpressCheckout::cents((float) $component->shippingCosts),
                    'total' => ExpressCheckout::cents((float) $component->amountToPay),
                ];
            }

            if ($component->requiresShipping && $options === []) {
                return ['error' => 'no_shipping'];
            }

            $selected = $this->chooseShipping($options, $shippingMethodId);
            if ($selected !== null) {
                $component->shippingMethod = $selected;
            }
            $component->paymentMethod = $walletMethod?->id;
            $component->fillPrices();

            return [
                'shippingOptions' => $options,
                'selectedShippingOption' => $selected,
                'requiresShipping' => (bool) $component->requiresShipping,
                'walletAvailable' => $walletMethod !== null && (string) $component->paymentMethod === (string) $walletMethod->id,
                'subtotal' => ExpressCheckout::cents((float) $component->subtotal),
                'discount' => ExpressCheckout::cents((float) $component->discount),
                'shipping' => ExpressCheckout::cents((float) $component->shippingCosts),
                'total' => ExpressCheckout::cents((float) $component->amountToPay),
            ];
        });
    }

    /**
     * Maakt de order via submit() van de checkout en start (of koppelt) de
     * Pay.-betaling. Geeft redirectUrl bij succes, anders error + message.
     *
     * @param  array<string, string>  $fields
     * @param  int|null  $expectedTotal  bedrag in centen dat de klant in het wallet-blad zag; afwijking = geen order
     * @return array{redirectUrl?: string, error?: string, message?: string, total?: int}
     */
    public function place(array $fields, string $wallet, ?string $shippingMethodId, ?int $expectedTotal = null): array
    {
        return $this->run(function () use ($fields, $wallet, $shippingMethodId, $expectedTotal) {
            $walletMethod = ExpressCheckout::paymentMethod($wallet);
            if (! $walletMethod) {
                return ['error' => 'wallet_unavailable'];
            }

            $component = $this->component();
            if ($error = $this->prime($component, $fields)) {
                return ['error' => $error];
            }

            if (! count($component->cartItems)) {
                return ['error' => 'cart_empty'];
            }

            $component->paymentMethod = $walletMethod->id;
            $component->fillPrices();

            $options = array_map(fn ($method) => ['id' => (string) $method['id']], $this->shippingMethods($component));
            $component->shippingMethod = $this->chooseShipping($options, $shippingMethodId);
            $component->paymentMethod = $walletMethod->id;
            $component->fillPrices();

            // De checkout kiest zelf een andere methode als de wallet hier niet mag (land, bedrag, cadeaubon).
            if ((string) $component->paymentMethod !== (string) $walletMethod->id) {
                return ['error' => 'wallet_unavailable'];
            }

            $total = ExpressCheckout::cents((float) $component->amountToPay);
            if ($expectedTotal !== null && $expectedTotal !== $total) {
                return ['error' => 'amount_changed', 'total' => $total];
            }

            $result = $component->submit();

            if ($result instanceof RedirectResponse) {
                return ['redirectUrl' => $result->getTargetUrl(), 'total' => $total];
            }

            return ['error' => 'checkout_refused', 'message' => $this->alert($component)];
        });
    }

    /**
     * @return array<int, array{id: mixed, label: string}>
     */
    private function shippingMethods(Checkout $component): array
    {
        $methods = $component->shippingMethods ?? [];
        $methods = $methods instanceof Collection ? $methods->values()->all() : array_values((array) $methods);

        $result = [];
        foreach ($methods as $method) {
            $id = is_array($method) || $method instanceof \ArrayAccess ? ($method['id'] ?? null) : ($method->id ?? null);
            if ($id === null) {
                continue;
            }
            $name = is_object($method) ? ($method->name ?? '') : ($method['name'] ?? '');
            $result[] = ['id' => $id, 'label' => (string) $name];
        }

        return $result;
    }

    /** @param array<int, array{id: string}> $options */
    private function chooseShipping(array $options, ?string $wanted): ?string
    {
        $ids = array_map(fn ($option) => (string) $option['id'], $options);

        if ($wanted !== null && in_array($wanted, $ids, true)) {
            return $wanted;
        }

        return $ids[0] ?? null;
    }

    /** De laatste foutmelding die de checkout via showAlert of een notificatie gaf. */
    private function alert(Checkout $component): ?string
    {
        $message = null;

        try {
            foreach ((array) \Livewire\store($component)->get('dispatched', []) as $event) {
                $data = method_exists($event, 'serialize') ? $event->serialize() : [];
                if (($data['name'] ?? null) === 'showAlert') {
                    $message = (string) ($data['params'][1] ?? $message);
                }
            }
        } catch (Throwable) {
        }

        foreach ((array) session('filament.notifications', []) as $notification) {
            $message ??= is_array($notification) ? ($notification['title'] ?? null) : null;
        }

        return $message;
    }

    private function countryName(string $code): ?string
    {
        $code = strtoupper(trim($code));
        foreach (Countries::getCountries() as $country) {
            if (strtoupper((string) ($country['alpha2Code'] ?? '')) === $code) {
                return $country['nativeName'] ?? $country['name'] ?? null;
            }
        }

        return null;
    }

    private function call(Checkout $component, string $method, mixed $default): mixed
    {
        if (! method_exists($component, $method)) {
            return $default;
        }

        return Closure::bind(fn () => $this->{$method}(), $component, $component::class)();
    }

    private function run(Closure $callback): array
    {
        $previous = self::$running;
        self::$running = true;

        try {
            return $callback();
        } finally {
            self::$running = $previous;
            // Meldingen van de checkout horen bij dit JSON-antwoord, niet bij de volgende pagina.
            session()->forget('filament.notifications');
        }
    }
}
