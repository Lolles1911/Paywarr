<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Server;
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
 * Paywarr → Service status: the notices customers see on top of the client
 * area and on /status. Outages are added and ended by themselves; your own
 * (planned maintenance, news) are made here.
 */
class PaywarrNotices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-broadcast-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Service status';

    protected static ?string $title = 'Service status';

    protected static ?string $slug = 'paywarr-notices';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $outages = count(array_filter(Wizarr::activeNotices(), fn ($n) => $n['kind'] === 'outage'));
        } catch (Throwable $e) {
            return null;
        }

        return $outages > 0 ? (string) $outages : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getSubheading(): ?string
    {
        return 'Shown on top of the client area to the customers it concerns, and on the public page /status. A media server that goes down gets a notice by itself (with Tracearr on the Wizarr server; a Silo when it doesn\'t answer), ended when it is back.';
    }

    private static function canChange(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.update');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('status')->label('Open /status')->icon('ri-external-link-line')->color('gray')->url(fn () => url('/status'))->openUrlInNewTab(),
            Action::make('new')->label('New notice')->icon('ri-add-line')->visible(fn () => self::canChange())->modalWidth('2xl')
                ->schema([
                    TextInput::make('title')->label('Title')->required()->maxLength(120)->placeholder('Planned maintenance Sunday night'),
                    Textarea::make('text')->label('Text')->required()->rows(4)->maxLength(1000),
                    Select::make('kind')->label('Kind')->required()->default('maintenance')->options(['maintenance' => 'Maintenance (orange)', 'outage' => 'Outage (red)', 'info' => 'Information (purple)']),
                    Select::make('servers')->label('For customers on')->multiple()->placeholder('Everyone')
                        ->options(fn () => Server::where('extension', 'Wizarr')->pluck('name', 'id')->all()),
                    DateTimePicker::make('starts')->label('From')->default(now())->required(),
                    DateTimePicker::make('ends')->label('Until (optional)')->helperText('Empty: until you end it.'),
                    Toggle::make('mail')->label('Mail it to those customers'),
                    Toggle::make('discord')->label('Post it in the Discord announcements channel')->default(true),
                ])
                ->action(function (array $data) {
                    try {
                        (new Wizarr([]))->addNotice(trim($data['title']), trim($data['text']), $data['kind'], (array) ($data['servers'] ?? []),
                            strtotime((string) $data['starts']) ?: time(), !empty($data['ends']) ? (strtotime((string) $data['ends']) ?: null) : null,
                            (bool) ($data['mail'] ?? false), (bool) ($data['discord'] ?? false), (string) (Auth::user()?->email ?? ''));
                        Notification::make()->title('Notice added')->success()->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->title('Not added')->body($e->getMessage())->danger()->send();
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
                foreach (Wizarr::notices() as $notice) {
                    $rows[$notice['id']] = $notice;
                }

                return $rows;
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->label('Notice')->description(fn (array $record) => mb_substr($record['text'], 0, 120))->wrap(),
                TextColumn::make('kind')->label('Kind')->badge()->formatStateUsing(fn (string $state, array $record) => ucfirst($state) . (!empty($record['auto']) ? ' (by itself)' : ''))
                    ->color(fn (string $state) => $state === 'outage' ? 'danger' : ($state === 'maintenance' ? 'warning' : 'info')),
                TextColumn::make('servers')->label('For')->state(fn (array $record) => $record['servers'] ? implode(', ', Server::whereIn('id', $record['servers'])->pluck('name')->all()) : ($record['types'] ? ucfirst(implode(', ', $record['types'])) . ' users' : 'Everyone')),
                TextColumn::make('starts')->label('When')->formatStateUsing(fn ($state, array $record) => date('M j, H:i', (int) $state) . ' – ' . (!empty($record['ends']) ? date('M j, H:i', (int) $record['ends']) : 'now')),
                TextColumn::make('state')->label('')->badge()
                    ->state(fn (array $record) => $record['starts'] > time() ? 'Planned' : (!empty($record['ends']) && $record['ends'] <= time() ? 'Ended' : 'Showing'))
                    ->color(fn (string $state) => $state === 'Showing' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('end')->label('End now')->icon('ri-stop-circle-line')->requiresConfirmation()
                    ->visible(fn (array $record) => self::canChange() && (empty($record['ends']) || $record['ends'] > time()))
                    ->action(function (array $record) {
                        (new Wizarr([]))->endNotice($record['id']);
                        $this->resetTable();
                    }),
                Action::make('delete')->label('Delete')->icon('ri-delete-bin-line')->color('danger')->requiresConfirmation()
                    ->visible(fn () => self::canChange())
                    ->action(function (array $record) {
                        (new Wizarr([]))->deleteNotice($record['id']);
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No notices')
            ->emptyStateDescription('Everything is running. "New notice" tells customers about planned maintenance.');
    }
}
