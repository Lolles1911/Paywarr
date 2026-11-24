<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Classes\FilamentInput;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Settings: everything for the whole shop, in tabs. The Wizarr
 * server in Paymenter keeps only how to reach Wizarr or Silo.
 */
class PaywarrSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-settings-3-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 300;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Paywarr settings';

    protected static ?string $slug = 'paywarr-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    private const ICONS = ['Shop' => 'ri-store-2-line', 'Reminders' => 'ri-alarm-line', 'Loyalty' => 'ri-heart-line', 'Discord' => 'ri-discord-line',
        'Connections' => 'ri-plug-line', 'Alerts and clean-up' => 'ri-notification-3-line'];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.servers.update') || (bool) Auth::user()?->hasPermission('admin.settings.update');
    }

    /** The fields in Paymenter's format, by name. */
    private static function fields(): array
    {
        $byName = [];
        foreach ((new Wizarr([]))->allConfigFields() as $field) {
            $byName[$field['name']] = $field;
        }

        return $byName;
    }

    public function mount(): void
    {
        Wizarr::migrateSettings();
        $values = Wizarr::globalSettings() ?? [];
        $fill = [];
        foreach (self::fields() as $name => $field) {
            if (!in_array($name, Wizarr::globalKeys(), true)) {
                continue;
            }
            $value = $values[$name] ?? ($field['default'] ?? null);
            if (!empty($field['multiple']) && is_string($value)) {
                $value = json_decode($value, true) ?: array_filter(explode(',', $value));
            }
            if (($field['type'] ?? '') === 'checkbox') {
                $value = in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
            }
            $fill[$name] = $value;
        }
        $this->form->fill($fill);
    }

    public function getSubheading(): ?string
    {
        return 'Settings for the whole shop. How Paymenter reaches Wizarr or Silo (address and API key) stays on the Wizarr server under Servers.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    public function form(Schema $schema): Schema
    {
        $fields = self::fields();
        $tabs = [];
        foreach (Wizarr::SETTING_TABS as $label => $names) {
            $components = [];
            foreach ($names as $name) {
                if (isset($fields[$name])) {
                    try {
                        $components[] = FilamentInput::convert($fields[$name]);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }
            $tabs[] = Tab::make($label)->icon(self::ICONS[$label] ?? null)->schema($components);
        }

        return $schema->statePath('data')->components([Tabs::make('settings')->tabs($tabs)->persistTabInQueryString()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save')->icon('ri-save-line')->action(function () {
                $state = $this->form->getState();
                Wizarr::saveGlobalSettings(array_intersect_key($state, array_flip(Wizarr::globalKeys())), Wizarr::secretKeys());
                Cache::forget('wizarr:coming-on');
                Notification::make()->title('Saved')->success()->send();
            }),
        ];
    }
}
