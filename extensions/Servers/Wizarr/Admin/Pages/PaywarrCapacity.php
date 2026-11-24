<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Product;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Capacity: seats per media server. With a maximum, a full server
 * closes the sale of its products (their stock) and opens the waiting list.
 */
class PaywarrCapacity extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-group-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 307;

    protected static ?string $navigationLabel = 'Capacity';

    protected static ?string $title = 'Capacity';

    protected static ?string $slug = 'paywarr-capacity';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'Seats in use = users + unused invitations + unpaid orders. Give a server a maximum and Paywarr keeps the stock of its products at the free seats (checked every 5 minutes): full means "out of stock" in the shop, with a waiting list. Without a maximum nothing changes.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    private static function rows(): array
    {
        return (new Wizarr([]))->capacityRows(PaywarrInventory::get(),
            Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with(['settings', 'server'])->get(),
            Service::where('status', 'pending')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->get());
    }

    public function table(Table $table): Table
    {
        $waiting = array_count_values(array_map(fn ($e) => $e['product'], Wizarr::waitlist()));

        return $table
            ->records(fn () => self::rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Media server')->description(fn (array $record) => ucfirst($record['type']) . ' · ' . (parse_url($record['backend'], PHP_URL_HOST) ?: $record['backend'])),
                TextColumn::make('used')->label('In use')->description(fn (array $record) => $record['users'] . ' users, ' . $record['invitations'] . ' invitations, ' . $record['pending'] . ' unpaid'),
                TextColumn::make('max')->label('Maximum')->placeholder('No maximum'),
                TextColumn::make('free')->label('Free')->badge()->placeholder('—')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state === 0 ? 'danger' : ($state <= 3 ? 'warning' : 'success')))
                    ->formatStateUsing(fn ($state) => $state === 0 ? 'Full' : (string) $state),
                TextColumn::make('products')->label('Products')->wrap()
                    ->state(fn (array $record) => array_map(fn ($name, $id) => $name . (($waiting[$id] ?? 0) ? ' (' . $waiting[$id] . ' waiting)' : ''), $record['products'], array_keys($record['products'])))
                    ->placeholder('None'),
            ])
            ->recordActions([
                Action::make('max')->label('Set maximum')->icon('ri-pencil-line')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.products.update'))
                    ->fillForm(fn (array $record) => ['max' => $record['max']])
                    ->schema([TextInput::make('max')->label('Maximum users')->numeric()->minValue(0)->helperText('Empty: no maximum (the stock goes back to unlimited).')])
                    ->modalSubmitActionLabel('Save')
                    ->action(function (array $record, array $data) {
                        Wizarr::setCapacity($record['key'], ($data['max'] ?? '') === '' || $data['max'] === null ? null : (int) $data['max']);
                        $free = (new Wizarr([]))->runCapacity();
                        Notification::make()->title('Saved')->body($free ? 'Stock now: ' . implode(', ', array_map(fn ($id, $n) => (Product::find($id)?->name ?? '#' . $id) . ' ' . $n, array_keys($free), $free)) : null)->success()->send();
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No media servers')
            ->emptyStateDescription('Paywarr shows the media servers of your Wizarr and Silo here.');
    }
}
