<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Free access: free subscriptions and users without a
 * subscription, when they last watched, and when their access ends if they
 * keep not watching. The rules are set here ("Rules").
 */
class PaywarrFree extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-gift-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 104;

    protected static ?string $navigationLabel = 'Free access';

    protected static ?string $title = 'Free access';

    protected static ?string $slug = 'paywarr-free';

    private ?array $rows = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    private static function canChange(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.update');
    }

    public function getSubheading(): ?string
    {
        $r = Wizarr::freeRules();

        return ($r['enabled'] ? 'On: ' : 'Off (only shown): ') . 'nothing watched for ' . $r['days'] . ' days → a warning; ' . $r['warn_days'] . ' days later the access goes '
            . '(Plex: the share is removed; Jellyfin, Emby, Silo: switched off, deleted ' . $r['delete_days'] . ' days later; a free subscription is cancelled). '
            . 'New users are left alone for ' . $r['grace_days'] . ' days. Checked daily.';
    }

    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $wizarr = new Wizarr([]);
        $services = PaywarrInventory::services();
        $members = $wizarr->freeMembers(PaywarrInventory::get(), $services);
        $rules = Wizarr::freeRules();
        try {
            $last = $wizarr->lastPlays($members, max(90, $rules['days'] + $rules['warn_days'] + 7));
        } catch (Throwable $e) {
            $last = [];
            Notification::make()->title('Tracearr can\'t be read')->body($e->getMessage())->warning()->send();
        }

        return $this->rows = $wizarr->freeRows($members, $last);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rules')->label('Rules')->icon('ri-settings-3-line')->visible(fn () => self::canChange())
                ->fillForm(fn () => Wizarr::freeRules())
                ->schema([
                    Toggle::make('enabled')->label('On: warn and remove automatically')->helperText('Off: the page only shows who would be warned and removed.'),
                    TextInput::make('days')->label('Nothing watched for (days)')->numeric()->minValue(1)->required(),
                    TextInput::make('warn_days')->label('Then warned, and removed after (days)')->numeric()->minValue(0)->required()->helperText('0: removed without a warning.'),
                    TextInput::make('grace_days')->label('New users are left alone for (days)')->numeric()->minValue(0)->required(),
                    TextInput::make('delete_days')->label('Switched-off users (Jellyfin, Emby, Silo) deleted after (days)')->numeric()->minValue(1)->required(),
                    Select::make('paid_product')->label('Move to paid: product')->placeholder('The same media server (found by itself)')
                        ->options(fn () => (new Wizarr([]))->paidProducts())->helperText('Chosen first under "Move to paid"; you can change it there.'),
                    TextInput::make('pay_days')->label('Move to paid: pay within (days)')->numeric()->minValue(1)->required(),
                    TextInput::make('paid_discount')->label('Move to paid: welcome discount on the first invoice (%)')->numeric()->minValue(0)->maxValue(100)->required(),
                    TextInput::make('pay_remind_days')->label('Move to paid: remind those who haven\'t paid (days before the deadline)')->numeric()->minValue(0)->required()->helperText('0: no reminder.'),
                ])
                ->modalSubmitActionLabel('Save')
                ->action(function (array $data) {
                    Wizarr::setFreeRules($data);
                    Notification::make()->title('Saved')->success()->send();
                }),
            Action::make('paid')->label('Move to paid')->icon('ri-bank-card-line')->color('warning')->visible(fn () => self::canChange())
                ->modalHeading('Move free users to paid')
                ->modalDescription(self::PAID_TEXT)
                ->modalSubmitActionLabel('Move')
                ->fillForm(fn () => ['kinds' => ['subscription', 'user'], 'statuses' => ['active', 'new'], 'products' => [], 'only' => []] + self::paidDefaults())
                ->schema(fn () => [
                    Select::make('only')->label('Only these users')->multiple()->searchable()->live()->placeholder('Everyone the filter below takes')
                        ->options(fn () => array_map(fn ($row) => $row['name'] . ($row['email'] !== '' && $row['email'] !== $row['name'] ? ' (' . $row['email'] . ')' : '') . ' · ' . $row['product'], $this->rows())),
                    CheckboxList::make('kinds')->label('Who')->options(['subscription' => 'Free subscriptions', 'user' => 'Users without a subscription'])->live()
                        ->required(fn (Get $get) => !$get('only'))->hidden(fn (Get $get) => (bool) $get('only')),
                    CheckboxList::make('statuses')->label('Status')->columns(3)->live()->required(fn (Get $get) => !$get('only'))->hidden(fn (Get $get) => (bool) $get('only'))
                        ->options(['active' => 'Watching', 'new' => 'New', 'idle' => 'To be warned', 'warned' => 'Warned', 'exempt' => 'Exempt', 'offered' => 'Offered paid']),
                    Select::make('products')->label('Now on')->multiple()->placeholder('Everything')->live()->hidden(fn (Get $get) => (bool) $get('only'))
                        ->options(fn () => array_combine($p = array_values(array_unique(array_column($this->rows(), 'product'))), $p) ?: []),
                    TextEntry::make('count')->label('Will be moved')
                        ->state(fn (Get $get) => self::names($this->paidRows($get('only') ?? [], ['kinds' => $get('kinds') ?? [], 'statuses' => $get('statuses') ?? [], 'products' => $get('products') ?? []]))),
                    ...self::paidFields(),
                ])
                ->action(fn (array $data) => $this->movePaid($this->paidRows($data['only'] ?? [], $data), $data)),
            Action::make('give')->label('Give free access')->icon('ri-gift-2-line')->visible(fn () => self::canChange())->modalSubmitActionLabel('Give')
                ->modalDescription('A free subscription for an existing customer, on a product with "Free access" (or a price of 0). They get the invitation by mail, as with any order.')
                ->schema([
                    Select::make('user')->label('Customer')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => User::where('email', 'like', '%' . $search . '%')->orWhere('first_name', 'like', '%' . $search . '%')->limit(20)->get()
                            ->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name . ' ' . $u->last_name) . ' (' . $u->email . ')'])->all())
                        ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? trim($u->first_name . ' ' . $u->last_name) . ' (' . $u->email . ')' : null),
                    Select::make('product')->label('Product')->required()->options(fn () => self::freeProducts()),
                ])
                ->action(function (array $data) {
                    try {
                        $service = (new Wizarr([]))->giveFreeAccess(User::findOrFail($data['user']), Product::findOrFail($data['product']));
                        Notification::make()->title('Given')->body('Subscription #' . $service->id . '; the invitation is on its way.')->success()->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->title('Not given')->body($e->getMessage())->danger()->send();
                    }
                    PaywarrInventory::forget();
                    $this->rows = null;
                    $this->resetTable();
                }),
            Action::make('log')->label('Log')->icon('ri-history-line')->color('gray')
                ->modalHeading('Warned and removed')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->schema(fn () => [\Filament\Infolists\Components\TextEntry::make('log')->hiddenLabel()
                    ->state(array_map(fn ($e) => date('M j, Y H:i', (int) $e['at']) . ' · ' . $e['what'], array_slice(Wizarr::freeLog(), 0, 100)) ?: ['Nothing yet'])->listWithLineBreaks()]),
            Action::make('refresh')->label('Refresh')->icon('ri-refresh-line')->color('gray')
                ->action(function () {
                    Cache::forget('paywarr:free:last:' . max(90, Wizarr::freeRules()['days'] + Wizarr::freeRules()['warn_days'] + 7));
                    PaywarrInventory::forget();
                    $this->rows = null;
                    $this->resetTable();
                }),
        ];
    }

    /** Products a free subscription can be given on. */
    public static function freeProducts(): array
    {
        $options = [];
        foreach (Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with('settings')->get() as $product) {
            $settings = ExtensionHelper::settingsToArray($product->settings);
            if (!empty($settings['free_access']) && !in_array($settings['free_access'], ['0', 0, false], true)) {
                $options[$product->id] = $product->name;
            }
        }

        if ($options) {
            return $options;
        }
        $wizarr = new Wizarr([]);
        foreach (Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with('settings')->get() as $product) {
            if ($wizarr->plainProduct(ExtensionHelper::settingsToArray($product->settings))) {
                $options[$product->id] = $product->name; // not a trial, gift card or top-up: those don't count as free
            }
        }

        return $options;
    }

    private const PAID_TEXT = 'Each gets a paid subscription and an invoice, and keeps their user. Unpaid by the deadline, the access stops (Paymenter suspends it, as with any unpaid invoice). Users without a subscription are put on one if their mail address has an account; otherwise they get a mail with the shop, and lose the access at the deadline.';

    /** The product and the days to pay in. */
    private static function paidFields(): array
    {
        return [
            Select::make('product')->label('Paid product')->placeholder('The same media server (found by itself)')->options(fn () => (new Wizarr([]))->paidProducts()),
            TextInput::make('days')->label('Pay within (days)')->numeric()->minValue(1)->required(),
            TextInput::make('discount')->label('Welcome discount on the first invoice (%)')->numeric()->minValue(0)->maxValue(100)->default(0)
                ->helperText('0: none. Only the first invoice; later ones are the full price.'),
        ];
    }

    private static function paidDefaults(): array
    {
        $rules = Wizarr::freeRules();

        return ['product' => $rules['paid_product'], 'days' => $rules['pay_days'], 'discount' => $rules['paid_discount']];
    }

    /** The users chosen by name ($only), or else those the filter takes. */
    /** "3 users: Anna, Bo, Carl", the first 20 names. */
    private static function names(array $rows): string
    {
        $names = array_column($rows, 'name');
        $n = count($names);

        return $n === 0 ? 'Nobody' : ($n === 1 ? '1 user: ' : $n . ' users: ') . implode(', ', array_slice($names, 0, 20)) . ($n > 20 ? ' and ' . ($n - 20) . ' more' : '');
    }

    private function paidRows(array $only, array $filter): array
    {
        $rows = $this->rows();

        return $only ? array_intersect_key($rows, array_flip($only)) : Wizarr::paidFilter($rows, $filter);
    }

    private function movePaid(iterable $rows, array $data): void
    {
        $wizarr = new Wizarr([]);
        $done = [];
        $failed = [];
        foreach ($rows as $row) {
            try {
                $done[] = $wizarr->moveToPaid($row, !empty($data['product']) ? (int) $data['product'] : null, (int) $data['days'], null, (int) ($data['discount'] ?? 0));
            } catch (Throwable $e) {
                report($e);
                $failed[] = $row['name'] . ': ' . $e->getMessage();
            }
        }
        Wizarr::logFreeAll($done);
        Notification::make()->title(count($done) . ' moved' . ($failed ? ', ' . count($failed) . ' not' : ''))
            ->body(implode("\n", array_slice(array_merge($failed, $done), 0, 10)) ?: 'Nobody was chosen.')
            ->{$failed ? 'warning' : 'success'}()->persistent((bool) $failed)->send();
        PaywarrInventory::forget();
        $this->rows = null;
        $this->resetTable();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?array $filters) {
                $kind = $filters['kind']['value'] ?? null;
                $status = $filters['status']['value'] ?? null;

                return array_filter($this->rows(), fn ($row) => (!$kind || $row['kind'] === $kind) && (!$status || $row['status'] === $status)
                    && (!$search || str_contains(strtolower($row['name'] . ' ' . $row['email'] . ' ' . $row['product']), strtolower($search))));
            })
            ->paginated([25, 50, 100])
            ->searchable()
            ->columns([
                TextColumn::make('name')->label('Who')->description(fn (array $record) => $record['email']),
                TextColumn::make('kind')->label('Free as')->badge()
                    ->formatStateUsing(fn (string $state, array $record) => $state === 'subscription' ? 'Subscription: ' . $record['product'] : 'No subscription: ' . $record['product'])
                    ->color(fn (string $state) => $state === 'subscription' ? 'info' : 'gray'),
                TextColumn::make('last')->label('Last watched')
                    ->formatStateUsing(fn ($state) => $state ? date('M j, Y', (int) $state) : '')
                    ->placeholder('Not in the last ' . max(90, Wizarr::freeRules()['days'] + Wizarr::freeRules()['warn_days'] + 7) . ' days')
                    ->description(fn (array $record) => $record['idle_days'] === 1 ? '1 day without watching' : $record['idle_days'] . ' days without watching'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active' => 'Watching', 'new' => 'New', 'exempt' => 'Exempt', 'idle' => 'To be warned', 'warned' => 'Warned', 'removed' => 'Switched off', 'offered' => 'Offered paid', default => $state,
                    })
                    ->color(fn (string $state) => match ($state) { 'active', 'new' => 'success', 'exempt', 'offered' => 'info', 'idle', 'warned' => 'warning', default => 'danger' })
                    ->description(fn (array $record) => match ($record['status']) {
                        'warned' => 'Warned ' . date('M j', (int) $record['warned_at']) . ', removed ' . date('M j', (int) $record['goes_at']),
                        'removed' => 'Deleted ' . date('M j', (int) $record['goes_at']),
                        'offered' => 'Goes ' . date('M j', (int) $record['goes_at']) . ' without a subscription',
                        'active' => 'Warned on ' . date('M j', (int) $record['goes_at'] - Wizarr::freeRules()['warn_days'] * 86400) . ' if nothing until then',
                        default => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Free as')->options(['subscription' => 'Free subscription', 'user' => 'No subscription']),
                SelectFilter::make('status')->label('Status')->options(['active' => 'Watching', 'idle' => 'To be warned', 'warned' => 'Warned', 'removed' => 'Switched off', 'new' => 'New', 'exempt' => 'Exempt', 'offered' => 'Offered paid']),
            ])
            ->recordActions([
                Action::make('exempt')->label(fn (array $record) => $record['status'] === 'exempt' ? 'Not exempt' : 'Exempt')->icon('ri-shield-user-line')
                    ->visible(fn () => self::canChange())
                    ->tooltip('Exempt users (family, your own) are never warned or removed')
                    ->action(function (array $record) {
                        Wizarr::setFreeExempt($record['key'], $record['status'] !== 'exempt');
                        $this->rows = null;
                        $this->resetTable();
                    }),
                Action::make('paid')->label('Move to paid')->icon('ri-bank-card-line')->color('warning')
                    ->visible(fn (array $record) => self::canChange() && $record['status'] !== 'removed')
                    ->modalHeading(fn (array $record) => 'Move ' . $record['name'] . ' to paid')
                    ->modalDescription(self::PAID_TEXT)->modalSubmitActionLabel('Move')
                    ->fillForm(fn () => self::paidDefaults())->schema(fn () => self::paidFields())
                    ->action(fn (array $record, array $data) => $this->movePaid([$record], $data)),
                Action::make('remove')->label('Remove')->icon('ri-user-unfollow-line')->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => self::canChange() && $record['status'] !== 'removed')
                    ->modalDescription(fn (array $record) => $record['kind'] === 'subscription' ? 'Cancels the free subscription and removes its users.' : 'Plex: removes the share. Jellyfin, Emby, Silo: switches the user off.')
                    ->action(function (array $record) {
                        try {
                            (new Wizarr([]))->freeAct('remove', $record);
                            Notification::make()->title('Removed')->success()->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->title('Not removed')->body($e->getMessage())->danger()->send();
                        }
                        PaywarrInventory::forget();
                        $this->rows = null;
                        $this->resetTable();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('paid')->label('Move to paid')->icon('ri-bank-card-line')->color('warning')->visible(fn () => self::canChange())
                    ->modalHeading('Move the chosen users to paid')->modalSubmitActionLabel('Move')
                    ->modalDescription(fn (Collection $records) => 'Will be moved: ' . self::names($records->all()) . "\n\n" . self::PAID_TEXT)
                    ->fillForm(fn () => self::paidDefaults())->schema(fn () => self::paidFields())
                    ->action(fn (Collection $records, array $data) => $this->movePaid($records->all(), $data))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->checkIfRecordIsSelectableUsing(fn (array $record) => $record['status'] !== 'removed')
            ->emptyStateHeading('No free access')
            ->emptyStateDescription('Free subscriptions (price 0, or a product with "Free access") and users on the media servers without a subscription show here.');
    }
}
