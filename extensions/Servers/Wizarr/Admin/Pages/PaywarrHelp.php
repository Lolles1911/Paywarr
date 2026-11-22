<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Help page: the guides and questions customers see under Help.
 * Paywarr's own texts until you save here; "Start over" brings them back.
 */
class PaywarrHelp extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-question-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 305;

    protected static ?string $navigationLabel = 'Help page';

    protected static ?string $title = 'Help page';

    protected static ?string $slug = 'paywarr-help';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.update');
    }

    public function mount(): void
    {
        $this->form->fill(Wizarr::helpContent());
    }

    public function getSubheading(): ?string
    {
        return 'What customers see under Help (/wizarr/help): a guide shows to customers on the media servers ticked (none ticked: everyone). An empty line in a text starts a new step.';
    }

    /** The form is shown on the page (Filament shows nothing by itself). */
    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    public function form(Schema $schema): Schema
    {
        $types = ['jellyfin' => 'Jellyfin', 'emby' => 'Emby', 'plex' => 'Plex', 'silo' => 'Silo'];

        return $schema->statePath('data')->components([
            Section::make('Introduction')->schema([Textarea::make('intro')->hiddenLabel()->rows(2)->placeholder('Optional: a few words on top of the page.')]),
            Section::make('Guides')->schema([
                Repeater::make('guides')->hiddenLabel()->reorderable()->collapsible()->itemLabel(fn (array $state) => $state['title'] ?? null)->schema([
                    TextInput::make('title')->label('Title')->required(),
                    CheckboxList::make('types')->label('For customers on')->options($types)->columns(4),
                    Textarea::make('text')->label('Steps')->rows(5)->required(),
                ]),
            ]),
            Section::make('Frequently asked questions')->schema([
                Repeater::make('faq')->hiddenLabel()->reorderable()->collapsible()->itemLabel(fn (array $state) => $state['question'] ?? null)->schema([
                    TextInput::make('question')->label('Question')->required(),
                    Textarea::make('answer')->label('Answer')->rows(3)->required(),
                ]),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label('Open the page')->icon('ri-external-link-line')->color('gray')->url(fn () => url('/wizarr/help'))->openUrlInNewTab(),
            Action::make('reset')->label('Start over')->icon('ri-refresh-line')->color('gray')->requiresConfirmation()
                ->modalDescription('Back to Paywarr\'s own guides and questions (in the customer\'s language). Your changes are lost.')
                ->action(function () {
                    Wizarr::storePut('help', []);
                    $this->form->fill(Wizarr::helpContent());
                    Notification::make()->title('Back to Paywarr\'s texts')->success()->send();
                }),
            Action::make('save')->label('Save')->icon('ri-save-line')->action(function () {
                $state = $this->form->getState();
                Wizarr::storePut('help', ['intro' => (string) ($state['intro'] ?? ''), 'guides' => array_values($state['guides'] ?? []), 'faq' => array_values($state['faq'] ?? [])]);
                Notification::make()->title('Saved')->success()->send();
            }),
        ];
    }
}
