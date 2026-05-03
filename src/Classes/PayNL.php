<?php

namespace Dashed\DashedEcommercePaynl\Classes;

use Exception;
use Paynl\Instore;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Classes\Locales;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Classes\Countries;
use Dashed\DashedTranslations\Models\Translation;
use Dashed\DashedEcommerceCore\Models\PinTerminal;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Classes\ShoppingCart;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use Dashed\DashedEcommerceCore\Contracts\PaymentProviderContract;

class PayNL implements PaymentProviderContract
{
    public const PSP = 'paynl';

    public static function initialize(?string $siteId = null): void
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        \Paynl\Config::setApiToken(Customsetting::get('paynl_at_hash', $siteId));
        \Paynl\Config::setServiceId(Customsetting::get('paynl_sl_code', $siteId));
    }

    public static function isConnected(?string $siteId = null): bool
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        self::initialize($siteId);

        try {
            $paymentMethods = \Paynl\Paymentmethods::getList();

            Customsetting::set('paynl_connection_error', null, $siteId);

            return true;
        } catch (\Exception $e) {
            Customsetting::set('paynl_connection_error', $e->getMessage(), $siteId);

            return false;
        }
    }

    public static function syncPaymentMethods(?string $siteId = null): void
    {
        $site = Sites::get($siteId);

        self::initialize($siteId);

        if (! Customsetting::get('paynl_connected', $site['id'])) {
            return;
        }

        try {
            $allPaymentMethods = \Paynl\Paymentmethods::getList();
        } catch (Exception $exception) {
            $allPaymentMethods = [];
        }

        foreach ($allPaymentMethods as $allPaymentMethod) {
            if (! $paymentMethod = PaymentMethod::where('psp', self::PSP)->where('psp_id', $allPaymentMethod['id'])->where('site_id', $site['id'])->first()) {
                $paymentMethod = new PaymentMethod();
                $paymentMethod->site_id = $site['id'];
                $paymentMethod->available_from_amount = $allPaymentMethod['min_amount'] ?: 0;
                $paymentMethod->psp_id = $allPaymentMethod['id'];
                $paymentMethod->psp = self::PSP;
                foreach (Locales::getLocales() as $locale) {
                    $paymentMethod->setTranslation('name', $locale['id'], $allPaymentMethod['visibleName']);
                }
            }

            if (($allPaymentMethod['brand']['image'] ?? false) && ! $paymentMethod->image) {
                $paymentMethod->image = mediaHelper()->uploadFromPath('https://static.pay.nl/' . $allPaymentMethod['brand']['image'], 'paynl', true);
            }
            $paymentMethod->save();

            //            $image = file_get_contents('https://static.pay.nl/' . $allPaymentMethod['brand']['image']);
            //            if ($image) {
            //                $imagePath = '/dashed/payment-methods/paynl/' . $allPaymentMethod['id'] . '.png';
            //                Storage::disk('dashed')->put($imagePath, $image);

            //                $folder = MediaLibraryFolder::where('name', 'pay')->first();
            //                if (!$folder) {
            //                    $folder = new MediaLibraryFolder();
            //                    $folder->name = 'pay';
            //                    $folder->save();
            //                }
            //                $filamentMediaLibraryItem = new MediaLibraryItem();
            //                $filamentMediaLibraryItem->uploaded_by_user_id = null;
            //                $filamentMediaLibraryItem->folder_id = $folder->id;
            //                $filamentMediaLibraryItem->save();
            //
            //                $filamentMediaLibraryItem
            //                    ->addMediaFromDisk($imagePath, 'dashed')
            //                    ->toMediaCollection($filamentMediaLibraryItem->getMediaLibraryCollectionName());

            //                $paymentMethod->image = $filamentMediaLibraryItem->id;
            //            }

            //            $paymentMethod->save();
        }
    }

    public static function syncPinTerminals(?string $siteId = null): void
    {
        $site = Sites::get($siteId);

        self::initialize($siteId);

        if (! Customsetting::get('paynl_connected', $site['id'])) {
            return;
        }

        try {
            $allTerminals = Instore::getAllTerminals()->getList() ?? [];
        } catch (Exception $exception) {
            $allTerminals = [];
        }

        if (is_array($allTerminals)) {
            foreach ($allTerminals as $allTerminal) {
                if (! $pinTerminal = PinTerminal::where('psp', self::PSP)->where('pin_terminal_id', $allTerminal['id'])->where('site_id', $site['id'])->first()) {
                    $pinTerminal = new PinTerminal();
                    $pinTerminal->site_id = $site['id'];
                    $pinTerminal->pin_terminal_id = $allTerminal['id'];
                    $pinTerminal->psp = self::PSP;
                    foreach (Locales::getLocales() as $locale) {
                        $pinTerminal->setTranslation('name', $locale['id'], $allTerminal['name']);
                    }
                }
                $pinTerminal->attributes = [
                    'state' => $allTerminal['state'],
                    'ecrProtocol' => $allTerminal['ecrProtocol'],
                ];
                $pinTerminal->save();
            }
        }
    }

    public static function startTransaction(OrderPayment $orderPayment): array
    {
        $orderPayment->psp = self::PSP;
        $orderPayment->save();

        $siteId = Sites::getActive();

        self::initialize($siteId);

        $paynlProducts = [];
        foreach ($orderPayment->order->orderProducts as $orderProduct) {
            array_push(
                $paynlProducts,
                [
                    'id' => $orderProduct->sku,
                    'name' => $orderProduct->name,
                    'price' => $orderProduct->price,
                    'qty' => $orderProduct->quantity,
                    'vatPercentage' => $orderProduct->vat_rate,
                    'type' => \Paynl\Transaction::PRODUCT_TYPE_ARTICLE,
                ]
            );
        }

        $transactionData = [
            'amount' => number_format($orderPayment->amount, 2, '.', ''),
            'returnUrl' => url(ShoppingCart::getCompleteUrl()) . '?orderId=' . $orderPayment->order->hash . '&paymentId=' . $orderPayment->hash,
            'ipaddress' => request()->ip(),
            'paymentMethod' => $orderPayment->paymentMethod?->pinTerminal ? 1927 : $orderPayment->paymentMethod?->psp_id,
            'bank' => $orderPayment->paymentMethod?->pinTerminal?->pin_terminal_id ?? null,
//            'paymentMethod' => $orderPayment->paymentMethod->psp_id,
//            'paymentMethod' => $orderPayment->paymentMethod->pinTerminal->pin_terminal_id,
            'currency' => 'EUR',
            'testmode' => Customsetting::get('paynl_test_mode', $siteId, false) ? true : false,

            'exchangeUrl' => route('dashed.frontend.checkout.exchange'),
            'description' => Translation::get('order-by-store', 'orders', 'Order :orderId: by :storeName:', 'text', [
                'storeName' => Customsetting::get('site_name'),
                'orderId' => $orderPayment->order->id,
            ]),
            'orderNumber' => $orderPayment->order->id,
            'products' => $paynlProducts,
            'invoiceDate' => $orderPayment->order->created_at,
            'enduser' => [
                'firstName' => $orderPayment->order->first_name,
                'lastName' => $orderPayment->order->last_name,
                'phoneNumber' => $orderPayment->order->phone_number,
                'emailAddress' => $orderPayment->order->email,
                'initials' => $orderPayment->order->initials,
                'gender' => match (strtoupper((string) $orderPayment->order->gender)) {
                    'M', 'MALE' => 'M',
                    'F', 'FEMALE' => 'F',
                    default => null,
                },
                'dob' => $orderPayment->order->date_of_birth,
            ],
            'address' => [
                'streetName' => $orderPayment->order->street ?: '',
                'houseNumber' => $orderPayment->order->house_nr ?: '',
                'zipCode' => $orderPayment->order->zip_code ?: '',
                'city' => $orderPayment->order->city ?: '',
                'country' => Countries::getCountryIsoCode($orderPayment->order->country) ?? '',
            ],
            'invoiceAddress' => [
                'initials' => $orderPayment->order->initials,
                'lastName' => $orderPayment->order->last_name,
                'streetName' => $orderPayment->order->invoice_street ?: $orderPayment->order->street,
                'houseNumber' => $orderPayment->order->invoice_house_nr ?: $orderPayment->order->house_nr,
                'zipCode' => $orderPayment->order->invoice_zip_code ?: $orderPayment->order->zip_code,
                'city' => $orderPayment->order->invoice_city ?: $orderPayment->order->city,
                'country' => Countries::getCountryIsoCode($orderPayment->order->invoice_country ?: $orderPayment->order->country),
            ],
        ];

        $result = \Paynl\Transaction::start($transactionData);

        $orderPayment->psp_id = $result->getTransactionId();
        $orderPayment->attributes = [
            'terminal' => $result->getData()['terminal'] ?? [],
        ];
        $orderPayment->save();

        return [
            'transaction' => $result,
            'redirectUrl' => $result->getRedirectUrl(),
        ];
    }

    public static function cancelPinTerminalTransaction(OrderPayment $orderPayment): bool
    {
        try {
            if ($orderPayment->attributes['terminal']['cancelUrl']) {
                $response = Http::get($orderPayment->attributes['terminal']['cancelUrl'])->json();
            }

            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    public static function getOrderStatus(OrderPayment $orderPayment): string
    {
        $site = Sites::getActive();

        self::initialize($site);

        try {
            $payment = self::getTransaction($orderPayment);
            if (! $payment) {
                return 'pending';
            }
        } catch (Exception $exception) {
            return 'pending';
        }

        if ($payment->isPaid()) {
            return 'paid';
        } elseif ($payment->isRefunded(true)) {
            return 'refunded';
        } elseif ($payment->isCancelled()) {
            return 'cancelled';
        } else {
            return 'pending';
        }
    }

    public static function getPinTerminalOrderStatus(OrderPayment $orderPayment): string
    {
        try {
            $response = Http::get($orderPayment->attributes['terminal']['statusUrl'])->json();
            if ($response['cancelled'] || $response['error'] == 1) {
                return 'cancelled';
            } elseif ($response['approved']) {
                return 'paid';
            } elseif ($response['status'] == 'start') {
                return 'pending';
            } else {
                return 'pending';
            }
        } catch (Exception $exception) {
            return 'pending';
        }
    }

    public static function getTransaction(OrderPayment $orderPayment)
    {
        $site = Sites::getActive();

        self::initialize($site);

        try {
            $payment = \Paynl\Transaction::get($orderPayment->psp_id);

            return $payment;
        } catch (Exception $exception) {
            return null;
        }
    }
}
