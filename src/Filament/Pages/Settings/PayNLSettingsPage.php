<?php

namespace Dashed\DashedEcommercePaynl\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedEcommercePaynl\Classes\PayNL;
use Dashed\DashedCore\Traits\HasSettingsPermission;

class PayNLSettingsPage extends Page
{
    use \Dashed\DashedCore\Filament\Concerns\TranslatesPageLabels;
    use HasSettingsPermission;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'PayNL';

    protected string $view = 'dashed-core::settings.pages.default-settings';
    public array $data = [];

    public function mount(): void
    {
        $formData = [];
        $sites = Sites::getSites();
        foreach ($sites as $site) {
            $formData["paynl_at_hash_{$site['id']}"] = Customsetting::get('paynl_at_hash', $site['id']);
            $formData["paynl_sl_code_{$site['id']}"] = Customsetting::get('paynl_sl_code', $site['id']);
            $formData["paynl_test_mode_{$site['id']}"] = Customsetting::get('paynl_test_mode', $site['id'], false) ? true : false;
            $formData["paynl_connected_{$site['id']}"] = Customsetting::get('paynl_connected', $site['id']);
            $formData["paynl_express_checkout_enabled_{$site['id']}"] = (bool) Customsetting::get('paynl_express_checkout_enabled', $site['id'], false);
            $formData["paynl_parts_secret_{$site['id']}"] = Customsetting::get('paynl_parts_secret', $site['id']);
            $formData["paynl_parts_environment_{$site['id']}"] = Customsetting::get('paynl_parts_environment', $site['id'], 'production');
            $formData["paynl_apple_domain_association_{$site['id']}"] = Customsetting::get('paynl_apple_domain_association', $site['id']);
        }

        $this->form->fill($formData);
    }

    public function form(Schema $schema): Schema
    {
        $sites = Sites::getSites();
        $tabGroups = [];

        $tabs = [];
        foreach ($sites as $site) {
            $newSchema = [
                TextEntry::make("PayNL voor {$site['name']}")
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextEntry::make("PayNL is " . (! Customsetting::get('paynl_connected', $site['id'], 0) ? 'niet' : '') . ' geconnect')
                    ->state(Customsetting::get('paynl_connection_error', $site['id'], ''))
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextInput::make("paynl_at_hash_{$site['id']}")
                    ->label(__('PayNL AT hash'))
                    ->maxLength(255),
                TextInput::make("paynl_sl_code_{$site['id']}")
                    ->label(__('PayNL SL code'))
                    ->maxLength(255),
                Toggle::make("paynl_test_mode_{$site['id']}")
                    ->label(__('Testmodus activeren')),
                Toggle::make("paynl_express_checkout_enabled_{$site['id']}")
                    ->label(__('Express-checkout (Apple Pay in één stap vanaf de wagen)'))
                    ->helperText(__('Pas aanzetten als Pay. het domein heeft gewhitelist voor Pay.Parts en Apple Pay in de Sales Location actief is.')),
                TextInput::make("paynl_parts_secret_{$site['id']}")
                    ->label(__('Secret van de Sales Location (Pay.Parts)'))
                    ->password()
                    ->revealable()
                    ->maxLength(255),
                Select::make("paynl_parts_environment_{$site['id']}")
                    ->label(__('Pay.Parts-omgeving'))
                    ->options([
                        'production' => 'Productie (parts.pay.nl)',
                        'zero' => 'Zero, pre-productie (zero-parts.pay.nl)',
                    ])
                    ->default('production'),
                Textarea::make("paynl_apple_domain_association_{$site['id']}")
                    ->label(__('Apple Pay-domeinverificatiebestand'))
                    ->helperText(__('Inhoud van het bestand van Pay. Wordt getoond op /.well-known/apple-developer-merchantid-domain-association.'))
                    ->rows(3)
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
            ];

            $tabs[] = Tab::make($site['id'])
                ->label(ucfirst($site['name']))
                ->schema($newSchema)
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ]);
        }
        $tabGroups[] = Tabs::make('Sites')
            ->tabs($tabs);

        return $schema->schema($tabGroups)
            ->statePath('data');
    }

    public function submit()
    {
        $sites = Sites::getSites();

        foreach ($sites as $site) {
            Customsetting::set('paynl_at_hash', $this->form->getState()["paynl_at_hash_{$site['id']}"], $site['id']);
            Customsetting::set('paynl_sl_code', $this->form->getState()["paynl_sl_code_{$site['id']}"], $site['id']);
            Customsetting::set('paynl_test_mode', $this->form->getState()["paynl_test_mode_{$site['id']}"], $site['id']);
            Customsetting::set('paynl_express_checkout_enabled', (bool) ($this->form->getState()["paynl_express_checkout_enabled_{$site['id']}"] ?? false), $site['id']);
            Customsetting::set('paynl_parts_secret', $this->form->getState()["paynl_parts_secret_{$site['id']}"] ?? null, $site['id']);
            Customsetting::set('paynl_parts_environment', $this->form->getState()["paynl_parts_environment_{$site['id']}"] ?? 'production', $site['id']);
            Customsetting::set('paynl_apple_domain_association', $this->form->getState()["paynl_apple_domain_association_{$site['id']}"] ?? null, $site['id']);
            Customsetting::set('paynl_connected', PayNL::isConnected($site['id']), $site['id']);

            if (Customsetting::get('paynl_connected', $site['id'])) {
                PayNL::syncPaymentMethods($site['id']);
                PayNL::syncPinTerminals();
            }
        }

        Notification::make()
            ->title(__('De PayNL instellingen zijn opgeslagen'))
            ->success()
            ->send();

        return redirect(PayNLSettingsPage::getUrl());
    }
}
