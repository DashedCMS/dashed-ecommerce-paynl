<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedEcommercePaynl\Controllers\ExpressCheckoutController;
use Dashed\DashedEcommercePaynl\Controllers\AppleDomainAssociationController;

Route::get('/.well-known/apple-developer-merchantid-domain-association', AppleDomainAssociationController::class)
    ->name('dashed.paynl.apple-domain-association');

Route::middleware(['web', 'throttle:60,1'])
    ->prefix('paynl/express')
    ->name('dashed.paynl.express.')
    ->group(function () {
        Route::post('/session', [ExpressCheckoutController::class, 'session'])->name('session');
        Route::post('/quote', [ExpressCheckoutController::class, 'quote'])->name('quote');
        Route::post('/order', [ExpressCheckoutController::class, 'order'])->name('order')->middleware('throttle:10,1');
        Route::post('/confirm', [ExpressCheckoutController::class, 'confirm'])->name('confirm');
    });
