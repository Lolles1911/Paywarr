<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Invoice;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Moved to paid: those moved from free access with "Move to paid",
 * whether they paid, and "Back to free" for a move made by mistake.
 */
class PaywarrPaid extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-bank-card-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Moved to paid';

    protected static ?string $title = 'Moved to paid';

    protected static ?string $slug = 'paywarr-paid';

    private ?array $rows = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    /** Only once someone has been moved. */
    public static function shouldRegisterNavigation(): bool
    {
        try {
            return Wizarr::storeGet('paid_moves', []) !== [] && static::canAccess();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getSubheading(): ?string
    {
        $rows = $this->rows();
        $count = fn ($status) => count(array_filter($rows, fn ($r) => $r['status'] === $status));
        $decided = $count('paid') + $count('unpaid');

        return count($rows) . ' moved from free access: ' . $count('paid') . ' paid, ' . $count('waiting') . ' waiting, ' . $count('unpaid') . ' not paid'
            . ($decided ? ' (' . round($count('paid') / $decided * 100) . '% of those due paid)' : '') . '.';
    }

    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $moves = Wizarr::storeGet('paid_moves', []);
        $services = Service::with(['product', 'user'])->whereIn('id', array_keys($moves) ?: [0])->get()->keyBy('id');
        $invoices = Invoice::whereIn('id', array_column($moves, 'invoice') ?: [0])->pluck('status', 'id')->all();
        $rows = [];
        foreach ($moves as $id => $move) {
            $service = $services[$id] ?? null;
            $rows['m' . $id] = [
                'service' => (int) $id, 'name' => $move['name'], 'email' => (string) ($service?->user?->email ?? ''),
                'product' => (string) ($service?->product?->name ?? '?'), 'price' => $service ? number_format((float) $service->price, 2) . ' ' . $service->currency_code : '',
                'invoice' => (int) $move['invoice'], 'at' => (int) $move['at'], 'due' => (int) $move['due'], 'discount' => (int) ($move['discount'] ?? 0),
                'reminded' => $move['reminded'] ?? null, 'new' => !empty($move['from']['new']),
                'status' => Wizarr::paidMoveStatus($move, $invoices[$move['invoice']] ?? null, $service?->status, time()),
            ];
        }
        uasort($rows, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $this->rows = $rows;
    }

    protected function getHeaderActions(): array
    {
        return [\Paymenter\Extensions\Servers\Wizarr\Admin\Csv::action('paywarr-moved-to-paid', fn () => [['Name', 'Email', 'Product', 'Price', 'Invoice', 'Moved', 'Due', 'Discount %', 'Status'],
            array_map(fn ($r) => [$r['name'], $r['email'], $r['product'], $r['price'], $r['invoice'], date('Y-m-d', $r['at']), date('Y-m-d', $r['due']), $r['discount'], $r['status']], $this->rows())])];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?array $filters) {
                $status = $filters['status']['value'] ?? null;

                return array_filter($this->rows(), fn ($row) => (!$status || $row['status'] === $status)
                    && (!$search || str_contains(strtolower($row['name'] . ' ' . $row['email'] . ' ' . $row['product']), strtolower($search))));
            })
            ->paginated([25, 50, 100])
            ->searchable()
            ->columns([
                TextColumn::make('name')->label('Who')->description(fn (array $record) => $record['email']),
                TextColumn::make('product')->label('Now on')->description(fn (array $record) => $record['price'] . ($record['discount'] ? ' · first invoice ' . $record['discount'] . '% off' : '')),
                TextColumn::make('at')->label('Moved')->formatStateUsing(fn ($state) => date('M j, Y', (int) $state))
                    ->description(fn (array $record) => $record['new'] ? 'New subscription' : 'Was a free subscription'),
                TextColumn::make('invoice')->label('Invoice')->formatStateUsing(fn ($state) => '#' . $state)
                    ->url(fn (array $record) => self::invoiceUrl($record['invoice']))
                    ->description(fn (array $record) => 'Due ' . date('M j', $record['due']) . ($record['reminded'] ? ' · reminded ' . date('M j', (int) $record['reminded']) : '')),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'paid' => 'Paid', 'waiting' => 'Waiting', 'unpaid' => 'Not paid', default => 'Cancelled' })
                    ->color(fn (string $state) => match ($state) { 'paid' => 'success', 'waiting' => 'warning', 'unpaid' => 'danger', default => 'gray' }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(['paid' => 'Paid', 'waiting' => 'Waiting', 'unpaid' => 'Not paid', 'cancelled' => 'Cancelled']),
            ])
            ->recordActions([
                Action::make('free')->label('Back to free')->icon('ri-arrow-go-back-line')->color('gray')->requiresConfirmation()
                    ->visible(fn (array $record) => (bool) Auth::user()?->hasPermission('admin.services.update') && $record['status'] !== 'paid')
                    ->modalDescription(fn (array $record) => 'Cancels invoice #' . $record['invoice'] . ' and makes the subscription free again'
                        . ($record['new'] ? ' (at 0, as it was made by the move).' : ' (its old product and renewal).') . ' A suspended one is opened again.')
                    ->action(function (array $record) {
                        try {
                            (new Wizarr([]))->backToFree($record['service']);
                            Notification::make()->title('Free again')->success()->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->title('Not undone')->body($e->getMessage())->danger()->send();
                        }
                        PaywarrInventory::forget();
                        $this->rows = null;
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('Nobody moved yet')
            ->emptyStateDescription('Those moved with "Move to paid" on Free access show here, with whether they paid.');
    }

    private static function invoiceUrl(int $id): ?string
    {
        try {
            return route('filament.admin.resources.invoices.edit', ['record' => $id]);
        } catch (Throwable $e) {
            return null;
        }
    }
}
