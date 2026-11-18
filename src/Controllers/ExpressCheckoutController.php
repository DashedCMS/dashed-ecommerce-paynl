<?php

namespace Dashed\DashedEcommercePaynl\Controllers;

use Throwable;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommercePaynl\Classes\PayNL;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Classes\ShoppingCart;
use PayNL\Sdk\Model\Request\TransactionStatusRequest;
use Dashed\DashedEcommercePaynl\ExpressCheckout\WalletContact;
use Dashed\DashedEcommercePaynl\ExpressCheckout\CheckoutRunner;
use Dashed\DashedEcommercePaynl\ExpressCheckout\ExpressCheckout;
use Dashed\DashedEcommercePaynl\ExpressCheckout\PayPartsSession;

/**
 * Endpoints van de express-checkout. Alles geeft 404 zolang de schakelaar
 * `paynl_express_checkout_enabled` uit staat.
 *
 *  POST session  start een Pay.Parts-sessie voor de wagen (sessionToken voor PayParts.js)
 *  POST quote    verzendopties en totaal voor een (deels bekend) wallet-adres
 *  POST order    order aanmaken met adres/e-mail uit de wallet en de betaling starten of koppelen
 *  POST confirm  Pay.-order-id uit de SDK aan de betaling hangen, na controle bij Pay.
 */
class ExpressCheckoutController extends Controller
{
    public function session(Request $request, CheckoutRunner $runner): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'wallet' => ['nullable', 'in:apple,google'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);
        $wallet = $data['wallet'] ?? 'apple';

        $quote = $runner->quote(['countryCode' => $data['country'] ?? 'NL'], $wallet);
        if (isset($quote['error'])) {
            return $this->error($quote['error']);
        }
        if (! ($quote['walletAvailable'] ?? false) || ($quote['total'] ?? 0) < 1) {
            return $this->error('wallet_unavailable');
        }

        $reference = 'EXP' . strtoupper(Str::random(12));
        $complete = url(ShoppingCart::getCompleteUrl());

        try {
            $session = PayPartsSession::start(
                PayPartsSession::payload(
                    amountCents: $quote['total'],
                    reference: $reference,
                    returnUrl: $complete,
                    exchangeUrl: route('dashed.frontend.checkout.exchange'),
                    description: (string) Customsetting::get('site_name'),
                    countryCode: $data['country'] ?? 'NL',
                ),
                PayNL::serviceId(),
                ExpressCheckout::partsSecret(),
                ExpressCheckout::environment(),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error('session_failed', 502);
        }

        $request->session()->put(ExpressCheckout::SESSION_KEY, [
            'session_id' => $session['sessionId'],
            'reference' => $reference,
            'amount' => $quote['total'],
            'wallet' => $wallet,
        ]);

        return response()->json([
            'sessionToken' => $session['sessionToken'],
            'apiUrl' => PayPartsSession::baseUrl(ExpressCheckout::environment()),
            'sdkUrl' => PayPartsSession::sdkUrl(ExpressCheckout::environment()),
            'amount' => $quote['total'],
            'currency' => 'EUR',
            'quote' => $quote,
        ]);
    }

    public function quote(Request $request, CheckoutRunner $runner): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'wallet' => ['required', 'in:apple,google'],
            'contact' => ['required', 'array'],
            'shippingOptionId' => ['nullable'],
        ]);

        $quote = $runner->quote(
            WalletContact::normalize($data['contact']),
            $data['wallet'],
            isset($data['shippingOptionId']) ? (string) $data['shippingOptionId'] : null,
        );
        if (isset($quote['error'])) {
            return $this->error($quote['error']);
        }

        $parts = $request->session()->get(ExpressCheckout::SESSION_KEY);
        $quote['sessionAmount'] = $parts['amount'] ?? null;
        $quote['amountMatchesSession'] = $parts === null || (int) $parts['amount'] === (int) $quote['total'];

        return response()->json($quote);
    }

    public function order(Request $request, CheckoutRunner $runner): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'wallet' => ['required', 'in:apple,google'],
            'contact' => ['required', 'array'],
            'shippingOptionId' => ['nullable'],
            'total' => ['nullable', 'integer', 'min:1'],
        ]);

        $parts = $request->session()->get(ExpressCheckout::SESSION_KEY);

        // Binnen een Pay.Parts-sessie ligt het bedrag vast bij de start
        // (updateSession bestaat bij Pay. nog alleen op Zero).
        $expected = $parts ? (int) $parts['amount'] : ($data['total'] ?? null);

        ExpressCheckout::$partsLink = $parts ? ['session_id' => (string) $parts['session_id'], 'reference' => (string) $parts['reference']] : null;
        ExpressCheckout::$linkedPaymentId = null;

        try {
            $result = $runner->place(
                WalletContact::normalize($data['contact']),
                $data['wallet'],
                isset($data['shippingOptionId']) ? (string) $data['shippingOptionId'] : null,
                $expected,
            );
            $linkedPaymentId = ExpressCheckout::$linkedPaymentId;
        } catch (Throwable $e) {
            report($e);

            return $this->error('payment_start_failed', 502);
        } finally {
            ExpressCheckout::$partsLink = null;
            ExpressCheckout::$linkedPaymentId = null;
        }

        if (isset($result['error'])) {
            return $this->error($result['error'], $result['error'] === 'amount_changed' ? 409 : 422, [
                'message' => $result['message'] ?? null,
                'total' => $result['total'] ?? null,
            ]);
        }

        if ($parts && $linkedPaymentId) {
            $request->session()->put(ExpressCheckout::SESSION_KEY . '.payment_id', $linkedPaymentId);

            return response()->json(['mode' => 'payparts', 'redirectUrl' => $result['redirectUrl']]);
        }

        $request->session()->forget(ExpressCheckout::SESSION_KEY);

        return response()->json(['mode' => 'redirect', 'redirectUrl' => $result['redirectUrl']]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'payOrderId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $parts = $request->session()->get(ExpressCheckout::SESSION_KEY);
        $payment = isset($parts['payment_id']) ? OrderPayment::find($parts['payment_id']) : null;

        if (! $payment || ($payment->attributes['payparts']['reference'] ?? null) !== ($parts['reference'] ?? false)) {
            return $this->error('payment_not_found', 404);
        }

        if (blank($payment->psp_id)) {
            try {
                $transaction = (new TransactionStatusRequest($data['payOrderId']))
                    ->setConfig(PayNL::config($payment->order?->site_id))
                    ->start();
            } catch (Throwable $e) {
                report($e);

                return $this->error('transaction_not_found', 422);
            }

            // Alleen een transactie uit onze eigen sessie, voor precies dit bedrag.
            // PayOrder::getAmount() geeft euro's (float), niet de centen uit de API.
            $amountValue = ExpressCheckout::cents((float) $transaction->getAmount());

            if ($transaction->getReference() !== $parts['reference'] || $amountValue !== ExpressCheckout::cents((float) $payment->amount)) {
                return $this->error('transaction_mismatch', 422);
            }

            $payment->psp_id = $transaction->getOrderId() ?: $data['payOrderId'];
            $payment->save();
        } elseif ($payment->psp_id !== $data['payOrderId']) {
            return $this->error('transaction_mismatch', 422);
        }

        $request->session()->forget(ExpressCheckout::SESSION_KEY);

        return response()->json([
            'redirectUrl' => url(ShoppingCart::getCompleteUrl()) . '?orderId=' . $payment->order->hash . '&paymentId=' . $payment->hash,
        ]);
    }

    private function guard(): void
    {
        abort_unless(ExpressCheckout::enabled(), 404);
    }

    private function error(string $code, int $status = 422, array $extra = []): JsonResponse
    {
        return response()->json(array_filter(['error' => $code] + $extra, fn ($value) => $value !== null), $status);
    }
}
