<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Movie nights: plan a title at a time; customers are invited,
 * say they're coming, and are reminded an hour before.
 */
class PaywarrParties extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-movie-2-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Movie nights';

    protected static ?string $title = 'Movie nights';

    protected static ?string $slug = 'paywarr-movie-nights';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'Customers are invited by mail and Discord (and a post with a thread in the announcements channel), press "I\'m coming" on /wizarr/movie-night, and are reminded an hour before. Everyone presses play at the same time on their own server.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('Plan a movie night')->icon('ri-add-line')->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                ->fillForm(fn () => ['at' => now()->addDays(3)->setTime(20, 0)->format('Y-m-d H:i'), 'announce' => true])
                ->schema([
                    TextInput::make('title')->label('Title')->required()->placeholder('The Matrix'),
                    DateTimePicker::make('at')->label('Starts')->seconds(false)->required()->after('now'),
                    Textarea::make('text')->label('A few words')->rows(2),
                    TextInput::make('link')->label('Link to the title (optional)')->url()->placeholder('E.g. the movie on your Jellyfin'),
                    Select::make('products')->label('For')->multiple()->placeholder('Every customer')->options(fn () => (new Wizarr([]))->campaignProducts()),
                    Toggle::make('announce')->label('Invite now (mail, Discord, announcements channel with a thread)'),
                ])
                ->modalSubmitActionLabel('Plan')
                ->action(function (array $data) {
                    try {
                        $party = (new Wizarr([]))->createWatchParty(['at' => strtotime((string) $data['at'])] + $data);
                        Notification::make()->title('Planned')->body($party['invited'] ? $party['invited'] . ' invited' . ($party['thread'] !== '' ? ', thread made on Discord' : '') : null)->success()->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->title('Not planned')->body($e->getMessage())->danger()->send();
                    }
                    $this->resetTable();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $rows = [];
                $parties = Wizarr::watchParties();
                uasort($parties, fn ($a, $b) => $b['at'] <=> $a['at']);
                foreach ($parties as $party) {
                    $rows['p' . $party['id']] = $party + ['count' => count($party['going'])];
                }

                return $rows;
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->label('Movie night')->description(fn (array $record) => $record['text']),
                TextColumn::make('at')->label('When')->formatStateUsing(fn ($state) => date('D M j, H:i', (int) $state))
                    ->description(fn (array $record) => $record['at'] < time() ? 'Done' : ($record['reminded'] ? 'Reminded' : 'Reminder an hour before')),
                TextColumn::make('count')->label('Coming')
                    ->description(fn (array $record) => implode(', ', array_slice(array_map(fn ($u) => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                        User::whereIn('id', array_keys($record['going']) ?: [0])->limit(8)->get()->all()), 0, 8))),
                TextColumn::make('invited')->label('Invited'),
            ])
            ->recordActions([
                Action::make('delete')->label('Delete')->icon('ri-delete-bin-line')->color('danger')->requiresConfirmation()
                    ->modalDescription('Removes it from the page. Those invited are not told; write in the Discord thread if it is cancelled.')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                    ->action(function (array $record) {
                        (new Wizarr([]))->deleteWatchParty($record['id']);
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No movie nights yet')
            ->emptyStateDescription('Plan one with "Plan a movie night".');
    }
}
