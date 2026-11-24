<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Mail → Design: the colour, the logo and a line at the bottom of
 * every Paywarr mail, with a preview.
 */
class PaywarrMailDesign extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-palette-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Mail';

    protected static ?int $navigationSort = 355;

    protected static ?string $navigationLabel = 'Design';

    protected static ?string $title = 'Mail design';

    protected static ?string $slug = 'paywarr-mail-design';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.update');
    }

    public function mount(): void
    {
        $design = Wizarr::mailDesign();
        $this->form->fill(['colour' => Wizarr::mailAccent(), 'logo' => $design['logo'], 'footer' => $design['footer']]);
    }

    public function getSubheading(): ?string
    {
        return 'How every Paywarr mail looks. The logo is the one under Settings (the dark one if there is one). Save to see it in the preview.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Design')->schema([
                    ColorPicker::make('colour')->label('Colour of buttons and the line under the logo')->regex('/^#[0-9a-fA-F]{6}$/'),
                    Select::make('logo')->label('Top of the mail')->options(['paymenter' => 'The logo from Settings', 'name' => 'The site\'s name'])->selectablePlaceholder(false),
                    Textarea::make('footer')->label('Line at the bottom')->rows(3)->placeholder(config('settings.company_name') ?: config('app.name'))
                        ->helperText('E.g. your company name and address. Empty: the site\'s name.'),
                ]),
                Section::make('Preview')->schema([
                    Text::make(fn () => PaywarrMails::frame((new Wizarr([]))->mailExample('trial_ending')['html'] ?? '')),
                ]),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save')->icon('ri-save-line')->action(function () {
                $state = $this->form->getState();
                Wizarr::storePut('mail_design', ['colour' => (string) ($state['colour'] ?? ''), 'logo' => (string) ($state['logo'] ?? 'paymenter'), 'footer' => trim((string) ($state['footer'] ?? ''))]);
                Notification::make()->title('Saved')->success()->send();
            }),
        ];
    }
}
