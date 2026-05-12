<?php

namespace Dashed\DashedEcommercePaynl;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedEcommercePaynl\Classes\PayNL;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommercePaynl\Commands\SyncPayNLPinTerminalsCommand;
use Dashed\DashedEcommercePaynl\Commands\SyncPayNLPaymentMethodsCommand;
use Dashed\DashedEcommercePaynl\Filament\Pages\Settings\PayNLSettingsPage;

class DashedEcommercePaynlServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-paynl';

    public function bootingPackage()
    {
        // Register the PayNL webhook event_id extractor so the
        // EnsureWebhookIdempotency middleware can deduplicate retries.
        app(\Dashed\DashedCore\Webhooks\WebhookEventIdResolver::class)->extend(
            'paynl',
            fn (\Illuminate\Http\Request $request) => (string) ($request->input('orderId') ?? $request->input('order_id') ?? ''),
        );

        cms()->registerIntegration([
            'slug' => 'paynl',
            'label' => 'PayNL',
            'icon' => 'heroicon-o-credit-card',
            'category' => 'payment',
            'settings_page' => \Dashed\DashedEcommercePaynl\Filament\Pages\Settings\PayNLSettingsPage::class,
            'health_check' => [\Dashed\DashedEcommercePaynl\Classes\PayNL::class, 'healthCheck'],
            'package' => 'dashed-ecommerce-paynl',
        ]);

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(SyncPayNLPaymentMethodsCommand::class)
                ->everyFifteenMinutes();
            $schedule->command(SyncPayNLPinTerminalsCommand::class)
                ->everyFifteenMinutes();
        });

        cms()->registerSettingsDocs(
            page: \Dashed\DashedEcommercePaynl\Filament\Pages\Settings\PayNLSettingsPage::class,
            title: 'PayNL instellingen',
            intro: 'Koppel je webshop aan PayNL om betalingen via iDEAL, creditcards en andere methodes te accepteren. PayNL is een Nederlandse betaaldienstverlener met een uitgebreid dashboard voor uitbetalingen en rapportages. Deze instellingen zijn per site, dus elke webshop kan een eigen PayNL aansluiting krijgen.',
            sections: [
                [
                    'heading' => 'Wat kun je hier instellen?',
                    'body' => 'Per site vul je de AT hash en SL code van je PayNL account in en kies je of je in testmodus werkt. Beide waarden zijn nodig om de koppeling te laten werken.',
                ],
                [
                    'heading' => 'Hoe zet je PayNL op?',
                    'body' => <<<MARKDOWN
1. Maak een account aan op [pay.nl](https://www.pay.nl) en doorloop het aanmeldproces.
2. Wacht tot je account is goedgekeurd door PayNL en je toegang hebt tot het merchant dashboard.
3. Log in op het merchant dashboard.
4. Ga naar de sectie voor API tokens en maak een nieuwe **AT hash** (API token) aan.
5. Zoek in datzelfde dashboard de **SL code** op (de Service Location identifier van je webshop).
6. Plak beide waarden hieronder en zet testmodus aan.
7. Doe een complete testbestelling om te controleren dat alles werkt.
8. Zet pas testmodus uit als de testbestelling volledig is afgerond.
MARKDOWN,
                ],
            ],
            fields: [
                'AT hash' => 'De AT hash, dit is het API token van je PayNL account. Je vindt deze in het PayNL merchant dashboard onder de API tokens sectie. Zonder geldig token komen er geen betalingen binnen.',
                'SL code' => 'De SL code, oftewel de Service Location identifier van de webshop. Deze waarde hoort bij de specifieke webshop binnen je PayNL account en vind je in hetzelfde dashboard als de AT hash.',
                'Testmodus' => 'Aan betekent dat betalingen plaatsvinden in de testomgeving van PayNL en er geen echt geld wordt afgeschreven. Pas uitzetten als je een testbestelling helemaal hebt doorlopen.',
            ],
            tips: [
                'Begin altijd met testmodus en een testbestelling voordat je live gaat.',
                'Heb je meerdere webshops onder een PayNL account? Let dan goed op dat je de juiste SL code per site invult.',
                'Klanten geen betalingen meer kunnen doen? Controleer eerst of de AT hash en SL code nog kloppen en niet zijn vervallen.',
            ],
        );
    }

    public function configurePackage(Package $package): void
    {
        cms()->registerSettingsPage(PayNLSettingsPage::class, 'PayNL', 'banknotes', 'Link PayNL aan je webshop');

        ecommerce()->builder(
            'paymentServiceProviders',
            array_merge(ecommerce()->builder('paymentServiceProviders'), [
                'paynl' => [
                    'name' => 'PayNL',
                    'class' => PayNL::class,
                ],
            ])
        );

        $package
            ->name('dashed-ecommerce-paynl')
            ->hasCommands([
                SyncPayNLPaymentMethodsCommand::class,
                SyncPayNLPinTerminalsCommand::class,
            ]);

        cms()->builder('plugins', [
            new DashedEcommercePaynlPlugin(),
        ]);
    }
}
