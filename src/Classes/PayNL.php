<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Exception;
use PayNL\Sdk\Model\Amount;
use PayNL\Sdk\Model\Address;
use PayNL\Sdk\Model\Company;
use PayNL\Sdk\Model\Product;
use PayNL\Sdk\Model\Customer;
use PayNL\Sdk\Model\Products;
use PayNL\Sdk\Config\Config;
use PayNL\Sdk\Model\Pay\PayOrder;
use PayNL\Sdk\Model\Order as PayOrderModel;
use PayNL\Sdk\Http\GuzzleClientFactoryInterface;
use PayNL\Sdk\Model\Request\OrderAbortRequest;
use PayNL\Sdk\Model\Request\OrderCreateRequest;
use PayNL\Sdk\Model\Request\TerminalsBrowseRequest;
use PayNL\Sdk\Model\Request\ServiceGetConfigRequest;
use PayNL\Sdk\Model\Request\TransactionStatusRequest;
use Illuminate\Support\Carbon;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Classes\Countries;
use Dashed\DashedTranslations\Models\Translation;
use Dashed\DashedEcommerceCore\Models\PinTerminal;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Classes\ShoppingCart;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;
use Dashed\DashedEcommerceCore\Classes\PaymentMethods;
use Dashed\DashedEcommerceCore\Contracts\PaymentProviderContract;

class PayNL implements PaymentProviderContract
{
    /**
     * Lightweight health check for the IntegrationsDashboard. Only verifies
     * the two PayNL credentials are set — does NOT call the PayNL API on
     * every dashboard render.
     */
    public static function healthCheck(?string $siteId = null): \Dashed\DashedCore\Integrations\IntegrationHealth
    {
        $atHash = \Dashed\DashedCore\Models\Customsetting::get('paynl_at_hash', $siteId);
        $slCode = \Dashed\DashedCore\Models\Customsetting::get('paynl_sl_code', $siteId);

        if (empty($atHash) || empty($slCode)) {
            return \Dashed\DashedCore\Integrations\IntegrationHealth::misconfigured('AT hash of SL code ontbreekt');
        }

        return \Dashed\DashedCore\Integrations\IntegrationHealth::ok();
    }

    public const PSP = 'paynl';

    /** Betaalmethode-id van Pay. voor een pinbetaling op een terminal. */
    public const PIN_PAYMENT_METHOD_ID = 1927;

    /**
     * Statuscodes die Pay. als terugbetaling meldt (volledig, deels, en de
     * oudere -72). Alles anders onder nul is een afgebroken, verlopen of
     * geweigerde betaling.
     */
    public const REFUND_STATUS_CODES = [-81, -82, -72];

    /**
     * Alleen voor tests: een eigen Guzzle-client zodat de SDK geen echte
     * aanroepen naar Pay. doet.
     */
    public static ?GuzzleClientFactoryInterface $clientFactory = null;

    /**
     * De SDK van Multicore logt in met een AT-code of SL-code als
     * gebruikersnaam. Een losse API-token (wat het CMS altijd heeft bewaard)
     * werkt met de vaste gebruikersnaam "token", net als bij de oude SDK; een
     * beheerder die toch "AT-xxxx-xxxx:token" invult krijgt die AT-code.
     */
    public static function config(?string $siteId = null): Config
    {
        $siteId ??= Sites::getActive();

        $token = trim((string) Customsetting::get('paynl_at_hash', $siteId));
        $username = 'token';

        if (preg_match('/^(AT-\d{4}-\d{4}):(.+)$/', $token, $matches)) {
            $username = $matches[1];
            $token = $matches[2];
        }

        $config = (new Config())
            ->setUsername($username)
            ->setPassword($token)
            ->setCaching(false);

        if (self::$clientFactory) {
            $config->setGuzzleClientFactory(self::$clientFactory);
        }

        return $config;
    }

    public static function serviceId(?string $siteId = null): string
    {
        return trim((string) Customsetting::get('paynl_sl_code', $siteId ?? Sites::getActive()));
    }

    /**
     * Bestaat nog voor aanroepers van buiten dit pakket; de nieuwe SDK heeft
     * geen globale staat meer, elke aanroep krijgt zijn eigen config().
     */
    public static function initialize(?string $siteId = null): void
    {
    }

    public static function isConnected(?string $siteId = null): bool
    {
        $siteId ??= Sites::getActive();

        try {
            (new ServiceGetConfigRequest(self::serviceId($siteId)))->setConfig(self::config($siteId))->start();

            Customsetting::set('paynl_connection_error', null, $siteId);

            return true;
        } catch (\Throwable $e) {
            Customsetting::set('paynl_connection_error', $e->getMessage(), $siteId);

            return false;
        }
    }

    /**
     * Alle betaalmethodes van de service, plat. De SDK zelf vat een groep
     * (zoals "Credit- & Debitcards") samen tot één methode met het id van de
     * groep; dat id kent de checkout niet, dus lezen we de methodes eronder.
     *
     * @return array<int, array{id: int, name: string, image: string, min_amount: float}>
     */
    public static function paymentMethods(?string $siteId = null): array
    {
        $siteId ??= Sites::getActive();

        $response = (new ServiceGetConfigRequest(self::serviceId($siteId)))->setConfig(self::config($siteId))->start();

        $methods = [];
        foreach ($response->getCheckoutOptions() as $checkoutOption) {
            foreach ($checkoutOption->getPaymentMethods() as $method) {
                $methods[$method->getId()] = [
                    'id' => $method->getId(),
                    'name' => $method->getName(),
                    'image' => $method->getImage(),
                    // Multicore geeft bedragen in centen.
                    'min_amount' => round($method->getMinAmount() / 100, 2),
                ];
            }
        }

        return array_values($methods);
    }

    public static function syncPaymentMethods(?string $siteId = null): void
    {
        $site = Sites::get($siteId);

        if (! Customsetting::get('paynl_connected', $site['id'])) {
            return;
        }

        try {
            $allPaymentMethods = self::paymentMethods($site['id']);
        } catch (\Throwable $exception) {
            report($exception);
            $allPaymentMethods = [];
        }

        foreach ($allPaymentMethods as $allPaymentMethod) {
            $isNewlyCreated = false;

            if (! $paymentMethod = PaymentMethod::where('psp', self::PSP)->where('psp_id', $allPaymentMethod['id'])->where('site_id', $site['id'])->first()) {
                $paymentMethod = new PaymentMethod();
                $paymentMethod->site_id = $site['id'];
                $paymentMethod->available_from_amount = $allPaymentMethod['min_amount'] ?: 0;
                $paymentMethod->psp_id = $allPaymentMethod['id'];
                $paymentMethod->psp = self::PSP;
                $paymentMethod->active = false;
                foreach (Locales::getLocales() as $locale) {
                    $paymentMethod->setTranslation('name', $locale['id'], $allPaymentMethod['name']);
                }
                $isNewlyCreated = true;
            }

            if ($allPaymentMethod['image'] && ! $paymentMethod->image) {
                $paymentMethod->image = mediaHelper()->uploadFromPath('https://static.pay.nl/' . ltrim($allPaymentMethod['image'], '/'), 'paynl', true);
            }
            $paymentMethod->save();

            if ($isNewlyCreated) {
                PaymentMethods::notifyAdminsOfNewPaymentMethod($paymentMethod);
            }
        }
    }

    public static function syncPinTerminals(?string $siteId = null): void
    {
        $site = Sites::get($siteId);

        if (! Customsetting::get('paynl_connected', $site['id'])) {
            return;
        }

        try {
            $allTerminals = (new TerminalsBrowseRequest())->setConfig(self::config($site['id']))->start()->getTerminals();
        } catch (\Throwable $exception) {
            report($exception);
            $allTerminals = [];
        }

        foreach ($allTerminals as $terminal) {
            if (! $pinTerminal = PinTerminal::where('psp', self::PSP)->where('pin_terminal_id', $terminal->getCode())->where('site_id', $site['id'])->first()) {
                $pinTerminal = new PinTerminal();
                $pinTerminal->site_id = $site['id'];
                $pinTerminal->pin_terminal_id = $terminal->getCode();
                $pinTerminal->psp = self::PSP;
                foreach (Locales::getLocales() as $locale) {
                    $pinTerminal->setTranslation('name', $locale['id'], $terminal->getName());
                }
            }
            $pinTerminal->attributes = [
                'state' => $terminal->getStatus(),
                'ecrProtocol' => $terminal->getEcrProtocol(),
            ];
            $pinTerminal->save();
        }
    }

    public static function startTransaction(OrderPayment $orderPayment): array
    {
        $orderPayment->psp = self::PSP;
        $orderPayment->save();

        $siteId = Sites::getActive();

        $request = self::buildOrderCreateRequest($orderPayment, $siteId);

        // Bestaat pas vanaf ec-core v4.134.0; dit pakket vereist ec-core niet.
        if (method_exists($orderPayment, 'recordPspRequest')) {
            $orderPayment->recordPspRequest($request->getBodyParameters());
        }

        $payOrder = $request->setConfig(self::config($siteId))->start();

        // Het order-id (bijvoorbeeld 51234567890X1234) is wat de exchange en
        // het dashboard van Pay. gebruiken, niet de uuid uit getId().
        $orderPayment->psp_id = $payOrder->getOrderId();
        $orderPayment->attributes = [
            'terminal' => [],
        ];
        $orderPayment->save();

        return [
            'transaction' => $payOrder,
            'redirectUrl' => $payOrder->getPaymentUrl(),
        ];
    }

    public static function buildOrderCreateRequest(OrderPayment $orderPayment, ?string $siteId = null): OrderCreateRequest
    {
        $siteId ??= Sites::getActive();
        $order = $orderPayment->order;
        $pinTerminal = $orderPayment->paymentMethod?->pinTerminal;

        $products = new Products();
        foreach ($order->orderProducts as $orderProduct) {
            $products->addProduct(
                (new Product())
                    ->setId((string) ($orderProduct->sku ?: $orderProduct->id))
                    ->setDescription((string) $orderProduct->name)
                    ->setType(Product::TYPE_ARTICLE)
                    ->setPrice(Amount::fromFloat((float) $orderProduct->price, 'EUR'))
                    ->setQuantity((float) $orderProduct->quantity)
                    ->setVatPercentage((float) $orderProduct->vat_rate)
            );
        }

        $address = self::addressData($order);

        $payOrder = (new PayOrderModel())
            ->setCountryCode($address['address']['country'] ?: ($address['invoiceAddress']['country'] ?: 'NL'))
            ->setInvoiceDate(Carbon::parse($order->created_at)->format('Y-m-d H:i:s'))
            ->setDeliveryAddress(self::address($address['address']))
            ->setInvoiceAddress(self::address($address['invoiceAddress']))
            ->setProducts($products);

        $customer = new Customer();
        self::when($order->first_name, fn ($v) => $customer->setFirstName($v));
        self::when($order->last_name, fn ($v) => $customer->setLastName($v));
        self::when(request()->ip(), fn ($v) => $customer->setIpAddress($v));
        self::when($order->phone_number, fn ($v) => $customer->setPhone($v));
        self::when($order->email, fn ($v) => $customer->setEmail($v));
        self::when(match (strtoupper((string) $order->gender)) {
            'M', 'MALE' => 'M',
            'F', 'FEMALE' => 'F',
            default => null,
        }, fn ($v) => $customer->setGender($v));

        // Pay. weigert een ongeldige geboortedatum (PAY-2008); alleen
        // meesturen als er een geldige datum uit komt.
        self::when(self::formatDateOfBirth($order->date_of_birth), fn ($v) => $customer->setBirthDate($v));

        if ($order->company_name) {
            $company = (new Company())->setName((string) $order->company_name);
            self::when($order->btw_id, fn ($v) => $company->setVat($v));
            self::when($address['invoiceAddress']['country'], fn ($v) => $company->setCountryCode($v));
            $customer->setCompany($company);
        }

        $request = (new OrderCreateRequest())
            ->setServiceId(self::serviceId($siteId))
            ->setAmount(round((float) $orderPayment->amount, 2))
            ->setCurrency('EUR')
            ->setReturnurl(url(ShoppingCart::getCompleteUrl()) . '?orderId=' . $order->hash . '&paymentId=' . $orderPayment->hash)
            ->setExchangeUrl(route('dashed.frontend.checkout.exchange'))
            ->setDescription(Translation::get('order-by-store', 'orders', 'Order :orderId: by :storeName:', 'text', [
                'storeName' => Customsetting::get('site_name'),
                'orderId' => $order->id,
            ]))
            ->setReference((string) $order->id)
            ->setTestmode((bool) Customsetting::get('paynl_test_mode', $siteId, false))
            ->setCustomer($customer)
            ->setOrder($payOrder);

        if ($pinTerminal) {
            $request->setPaymentMethodId(self::PIN_PAYMENT_METHOD_ID);
            $request->setTerminal((string) $pinTerminal->pin_terminal_id);
        } elseif ($orderPayment->paymentMethod?->psp_id) {
            $request->setPaymentMethodId((int) $orderPayment->paymentMethod->psp_id);
        }

        return $request;
    }

    /** @param array<string, mixed> $data */
    private static function address(array $data): Address
    {
        $address = new Address();
        self::when($data['streetName'] ?? null, fn ($v) => $address->setStreetName($v));
        self::when($data['houseNumber'] ?? null, fn ($v) => $address->setStreetNumber($v));
        self::when($data['zipCode'] ?? null, fn ($v) => $address->setZipCode($v));
        self::when($data['city'] ?? null, fn ($v) => $address->setCity($v));
        self::when($data['country'] ?? null, fn ($v) => $address->setCountryCode($v));

        return $address;
    }

    /** Roept $set alleen aan met een gevulde waarde, als string. */
    private static function when(mixed $value, callable $set): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $set(trim((string) $value));
    }

    /**
     * Aflever- en factuuradres voor de transactie. Het factuurland valt terug
     * op het afleverland als het niet te herkennen is: de SDK laat een lege
     * landcode weg, en achteraf betalen via Riverty weigert de betaling dan
     * met "Value is required" op customer.address.countryCode.
     */
    public static function addressData(Order $order): array
    {
        $countryCode = Countries::getCountryIsoCode($order->country);
        $invoiceCountryCode = Countries::getCountryIsoCode($order->invoice_country) ?: $countryCode;

        return [
            'address' => [
                'streetName' => $order->street ?: '',
                'houseNumber' => $order->house_nr ?: '',
                'zipCode' => $order->zip_code ?: '',
                'city' => $order->city ?: '',
                'country' => $countryCode ?? '',
            ],
            'invoiceAddress' => [
                'initials' => $order->initials,
                'lastName' => $order->last_name,
                'streetName' => $order->invoice_street ?: $order->street,
                'houseNumber' => $order->invoice_house_nr ?: $order->house_nr,
                'zipCode' => $order->invoice_zip_code ?: $order->zip_code,
                'city' => $order->invoice_city ?: $order->city,
                'country' => $invoiceCountryCode,
            ],
        ];
    }

    /**
     * PayNL wil de geboortedatum als DD-MM-YYYY string. In de database staat
     * date_of_birth als (ongecaste) Y-m-d string of leeg. Zet 'm om naar het
     * juiste formaat; geef null terug bij leeg/onparseerbaar zodat de dob-key
     * volledig weggelaten kan worden i.p.v. een ongeldige waarde mee te sturen.
     */
    public static function formatDateOfBirth($dateOfBirth): ?string
    {
        if (blank($dateOfBirth)) {
            return null;
        }

        try {
            $date = Carbon::parse($dateOfBirth);
        } catch (\Throwable $exception) {
            return null;
        }

        // Weiger onzinnige datums (bijv. MySQL '0000-00-00' -> jaar -1 of een
        // toekomstige datum) zodat we nooit een geldig-geformatteerde maar
        // inhoudelijk ongeldige dob naar PayNL sturen.
        if ($date->year < 1900 || $date->isFuture()) {
            return null;
        }

        return $date->format('d-m-Y');
    }

    public static function cancelPinTerminalTransaction(OrderPayment $orderPayment): bool
    {
        try {
            // Een pinbetaling uit de oude koppeling heeft een eigen afbreeklink.
            $cancelUrl = $orderPayment->attributes['terminal']['cancelUrl'] ?? null;
            if ($cancelUrl) {
                Http::get($cancelUrl);

                return true;
            }

            (new OrderAbortRequest($orderPayment->psp_id))->setConfig(self::config($orderPayment->order?->site_id))->start();

            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Vertaalt een statuscode van Pay. naar de vier standen die ec-core kent.
     * Zelfde volgorde als de oude koppeling: betaald, dan terugbetaald, dan
     * alles onder nul als geannuleerd, de rest wacht nog.
     */
    public static function statusFromCode(int $code): string
    {
        return match (true) {
            $code === 100 => 'paid',
            in_array($code, self::REFUND_STATUS_CODES, true) => 'refunded',
            $code < 0 => 'cancelled',
            default => 'pending',
        };
    }

    public static function getOrderStatus(OrderPayment $orderPayment): string
    {
        $payment = self::getTransaction($orderPayment);

        if (! $payment) {
            return 'pending';
        }

        PaynlTransactionState::store($orderPayment, ['status' => $payment->getStatus()]);

        return self::statusFromCode($payment->getStatusCode());
    }

    public static function getPinTerminalOrderStatus(OrderPayment $orderPayment): string
    {
        // Een pinbetaling uit de oude koppeling heeft een eigen statuslink.
        $statusUrl = $orderPayment->attributes['terminal']['statusUrl'] ?? null;
        if (! $statusUrl) {
            return self::getOrderStatus($orderPayment);
        }

        try {
            $response = Http::get($statusUrl)->json();
            if ($response['cancelled'] || $response['error'] == 1) {
                return 'cancelled';
            } elseif ($response['approved']) {
                return 'paid';
            }

            return 'pending';
        } catch (Exception $exception) {
            return 'pending';
        }
    }

    /**
     * Haalt de transactie op via het transactie-endpoint van Multicore. Dat
     * kent ook betalingen die nog op het oude platform zijn gestart (een
     * order-id dat met een 3 begint), dus lopende betalingen van voor de
     * overstap blijven gewoon te volgen.
     */
    public static function getTransaction(OrderPayment $orderPayment): ?PayOrder
    {
        if (blank($orderPayment->psp_id)) {
            return null;
        }

        try {
            return (new TransactionStatusRequest((string) $orderPayment->psp_id))
                ->setConfig(self::config($orderPayment->order?->site_id))
                ->start();
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
