<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Price changes: a new price for a plan, at once for new
 * customers, and with notice for those on it.
 */
class PaywarrPrices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-price-tag-2-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 110;

    protected static ?string $navigationLabel = 'Price changes';

    protected static ?string $title = 'Price changes';

    protected static ?string $slug = 'paywarr-prices';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.products.view');
    }

    private static function canChange(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.products.update');
    }

    public function getSubheading(): ?string
    {
        return 'New customers pay the new price at once. Customers on the plan get a mail ("Price change" under Mail) and pay the new price from the date, at least ' . Wizarr::PRICE_NOTICE_DAYS . ' days away: their invoices made from then on. Discounts they have still apply.';
    }

    /** Plans of Paywarr products: [plan ID => "Product · 1 month"]. */
    private static function planOptions(): array
    {
        $options = [];
        foreach (Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with('plans')->orderBy('name')->get() as $product) {
            foreach ($product->plans as $plan) {
                if ($plan->type === 'recurring') {
                    $options[(string) $plan->id] = $product->name . ' · ' . $plan->billing_period . ' ' . $plan->billing_unit . ($plan->billing_period > 1 ? 's' : '');
                }
            }
        }

        return $options;
    }

    protected function getHeaderActions(): array
    {
        $currency = (string) config('settings.default_currency', 'DKK');

        return [
            Action::make('new')->label('New price')->icon('ri-add-line')->visible(fn () => self::canChange())
                ->schema([
                    Select::make('plan')->label('Plan')->options(fn () => self::planOptions())->required()->live()
                        ->helperText(function ($get) use ($currency) {
                            $plan = $get('plan') ? Plan::find($get('plan')) : null;
                            if (!$plan) {
                                return null;
                            }
                            $now = Wizarr::planPrice($plan, $currency);
                            $on = Service::where('plan_id', $plan->id)->whereIn('status', ['active', 'suspended'])->count();

                            return 'Now ' . ($now === null ? 'no price in ' . $currency : number_format($now, 2) . ' ' . $currency) . ' · ' . $on . ' subscription(s) on it get the mail.';
                        }),
                    TextInput::make('price')->label('New price (' . $currency . ')')->numeric()->minValue(0)->required(),
                    DatePicker::make('date')->label('From (for current customers)')->required()->native(false)
                        ->default(now()->addDays(Wizarr::PRICE_NOTICE_DAYS + 1)->toDateString())->minDate(now()->addDays(Wizarr::PRICE_NOTICE_DAYS)->toDateString()),
                ])
                ->modalDescription('The plan\'s price changes now, and the mail goes now.')
                ->modalSubmitActionLabel('Change the price and tell them')
                ->action(function (array $data) use ($currency) {
                    try {
                        $change = (new Wizarr([]))->planPriceChange(Plan::findOrFail((int) $data['plan']), $currency, (float) $data['price'], strtotime($data['date'] . ' 00:00:00'));
                        Notification::make()->title('Price changed')->body($change['told'] . ' customer(s) told. Their new price from ' . date('M j, Y', $change['date']) . '.')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Not changed')->body($e->getMessage())->danger()->send();
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
                $rows = Wizarr::priceChanges();
                uasort($rows, fn ($a, $b) => $b['created'] <=> $a['created']);

                return $rows;
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('plan')->label('Plan')->formatStateUsing(fn ($state) => self::planOptions()[(string) $state] ?? '#' . $state),
                TextColumn::make('new')->label('Price')->formatStateUsing(fn ($state, array $record) => number_format((float) $record['old'], 2) . ' → ' . number_format((float) $state, 2) . ' ' . $record['currency']),
                TextColumn::make('date')->label('From')->formatStateUsing(fn ($state) => date('M j, Y', (int) $state))->description(fn (array $record) => $record['told'] . ' told on ' . date('M j', $record['created'])),
                TextColumn::make('applied')->label('Status')->badge()
                    ->state(fn (array $record) => $record['cancelled'] ? 'cancelled' : ($record['applied'] ? 'applied' : 'planned'))
                    ->formatStateUsing(fn (string $state, array $record) => match ($state) { 'cancelled' => 'Cancelled', 'applied' => 'Applied (' . ($record['services'] ?? 0) . ')', default => 'Planned' })
                    ->color(fn (string $state) => match ($state) { 'cancelled' => 'gray', 'applied' => 'success', default => 'info' }),
            ])
            ->recordActions([
                Action::make('cancel')->label('Cancel')->icon('ri-close-line')->color('danger')->requiresConfirmation()
                    ->modalDescription('The plan goes back to the old price for new customers, and current customers keep theirs. They were told about the change already: send a message (Mail → Message customers) if needed.')
                    ->visible(fn (array $record) => self::canChange() && !$record['applied'] && !$record['cancelled'])
                    ->action(function (array $record) {
                        Wizarr::cancelPriceChange($record['id']);
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No price changes yet')
            ->emptyStateDescription('"New price" changes a plan\'s price, with notice for current customers.');
    }
}
