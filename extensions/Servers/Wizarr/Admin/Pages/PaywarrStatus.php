<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory;

/**
 * Paywarr → Server status: every Wizarr and Silo behind your Paymenter
 * servers, whether it answers, and its media servers with users and
 * libraries.
 */
class PaywarrStatus extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-pulse-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Server status';

    protected static ?string $title = 'Server status';

    protected static ?string $slug = 'paywarr-status';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.servers.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Check again')->icon('ri-refresh-line')->action(function () {
                PaywarrInventory::forget();
                $this->resetTable();
                $down = array_filter(PaywarrInventory::get(), fn ($b) => !$b['ok']);
                $down
                    ? Notification::make()->title(count($down) . ' not answering')->body(implode("\n", array_map(fn ($b) => PaywarrInventory::label($b) . ': ' . $b['error'], $down)))->danger()->send()
                    : Notification::make()->title('Everything answers')->success()->send();
            }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('backend')->label('Wizarr / Silo')->description(fn (array $record) => $record['paymenter']),
                TextColumn::make('name')->label('Media server')->description(fn (array $record) => $record['url'])->placeholder('—'),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (?string $state) => ucfirst((string) $state))->placeholder('—'),
                TextColumn::make('state')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) { 'Online' => 'success', 'Not verified' => 'warning', default => 'danger' }),
                TextColumn::make('users')->label('Users')->placeholder('—'),
                TextColumn::make('libraries')->label('Libraries')->placeholder('—'),
                TextColumn::make('ms')->label('Answer')->formatStateUsing(fn ($state) => $state === null ? '' : $state . ' ms')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon('ri-external-link-line')
                    ->url(fn (array $record) => $record['url'] ?: null, shouldOpenInNewTab: true)
                    ->visible(fn (array $record) => $record['url'] !== ''),
            ])
            ->emptyStateHeading('No Wizarr or Silo server in Paymenter yet')
            ->emptyStateDescription('Add one under Servers, with the extension Wizarr.');
    }

    private function rows(): array
    {
        $rows = [];
        foreach (PaywarrInventory::get() as $backend) {
            $common = ['backend' => PaywarrInventory::label($backend), 'paymenter' => 'Paymenter: ' . implode(', ', $backend['paymenter']), 'ms' => $backend['ms']];
            if (!$backend['ok']) {
                $rows[$backend['key']] = $common + ['name' => null, 'type' => $backend['kind'], 'url' => '', 'state' => 'Not answering: ' . mb_strimwidth($backend['error'], 0, 80, '…'), 'users' => null, 'libraries' => null];

                continue;
            }
            foreach ($backend['servers'] as $server) {
                $rows[$backend['key'] . '|' . $server['id']] = $common + [
                    'name' => $server['name'], 'type' => $server['type'], 'url' => $server['url'],
                    'state' => $server['verified'] ? 'Online' : 'Not verified',
                    'users' => $server['users'], 'libraries' => $server['libraries'],
                ];
            }
        }

        return $rows;
    }
}
