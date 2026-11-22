<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
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
 * Paywarr → Challenges: e.g. "Watch 10 movies in December", with a prize
 * for everyone who makes it.
 */
class PaywarrChallenges extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-trophy-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Challenges';

    protected static ?string $title = 'Challenges';

    protected static ?string $slug = 'paywarr-challenges';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'Progress is counted hourly from Tracearr and Silo (a play counts after 2 minutes; a movie or episode once). Customers see theirs on /wizarr/challenge and the dashboard; the start and the leaderboard are posted in the announcements channel. At the end everyone who reached the goal gets the prize.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('New challenge')->icon('ri-add-line')->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                ->fillForm(fn () => ['metric' => 'movies', 'goal' => 10, 'starts' => now()->startOfDay()->addDay()->format('Y-m-d H:i'),
                    'ends' => now()->startOfDay()->addDays(31)->format('Y-m-d H:i'), 'month' => true, 'announce' => true])
                ->schema([
                    TextInput::make('title')->label('Title')->required()->placeholder('Movie December'),
                    Select::make('metric')->label('What counts')->options(Wizarr::CHALLENGE_METRICS)->required(),
                    TextInput::make('goal')->label('Goal')->numeric()->minValue(1)->required(),
                    DateTimePicker::make('starts')->label('Starts')->seconds(false)->required(),
                    DateTimePicker::make('ends')->label('Ends')->seconds(false)->required()->after('starts'),
                    Toggle::make('month')->label('Prize: a free month (the subscription\'s renewal moves a month)'),
                    TextInput::make('credit')->label('Prize: credit (in the customer\'s currency, 0: none)')->numeric()->minValue(0)->default(0),
                    Select::make('role')->label('Prize: a Discord role')->placeholder('None')->options(fn () => self::roles()),
                    Toggle::make('announce')->label('Post the start and the leaderboard in the announcements channel'),
                ])
                ->modalSubmitActionLabel('Create')
                ->action(function (array $data) {
                    try {
                        (new Wizarr([]))->createChallenge(['starts' => strtotime((string) $data['starts']), 'ends' => strtotime((string) $data['ends'])] + $data);
                        Notification::make()->title('Created')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Not created')->body($e->getMessage())->danger()->send();
                    }
                    $this->resetTable();
                }),
        ];
    }

    private static function roles(): array
    {
        try {
            $ext = (new Wizarr([]))->anyServer('discord_guild_id');

            return $ext->discordRoleOptions();
        } catch (Throwable $e) {
            return [];
        }
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
                $all = Wizarr::challenges();
                uasort($all, fn ($a, $b) => $b['starts'] <=> $a['starts']);
                foreach ($all as $c) {
                    $rows['c' . $c['id']] = $c + ['status' => Wizarr::challengeStatus($c), 'made' => count(array_filter($c['progress'], fn ($v) => $v >= $c['goal'])), 'joined' => count($c['progress'])];
                }

                return $rows;
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->label('Challenge')->description(fn (array $record) => (new Wizarr([]))->challengeGoalText($record)),
                TextColumn::make('made')->label('Made it')->description(fn (array $record) => $record['joined'] . ' watching · prize: ' . (new Wizarr([]))->prizeText($record)),
                TextColumn::make('progress')->label('Top')->state(fn (array $record) => self::top($record))->wrap(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'running' => 'Running', 'planned' => 'Planned', 'ending' => 'Ending', default => 'Done' })
                    ->color(fn (string $state) => match ($state) { 'running' => 'success', 'planned' => 'info', 'ending' => 'warning', default => 'gray' }),
            ])
            ->recordActions([
                Action::make('delete')->label('Delete')->icon('ri-delete-bin-line')->color('danger')->requiresConfirmation()
                    ->modalDescription('Removes the challenge. Prizes already given stay.')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                    ->action(function (array $record) {
                        (new Wizarr([]))->deleteChallenge($record['id']);
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No challenges yet')
            ->emptyStateDescription('Make one with "New challenge", e.g. 10 movies in December with a free month as the prize.');
    }

    private static function top(array $c): string
    {
        $top = array_slice($c['progress'], 0, 3, true);
        $users = User::whereIn('id', array_keys($top) ?: [0])->get()->keyBy('id');

        return implode(', ', array_map(fn ($id, $v) => (trim(($users[$id]->first_name ?? '') . ' ' . ($users[$id]->last_name ?? '')) ?: '#' . $id) . ' ' . $v, array_keys($top), $top)) ?: '–';
    }
}
