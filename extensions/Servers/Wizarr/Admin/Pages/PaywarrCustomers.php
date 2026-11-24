<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Customers: every Paywarr subscription, searchable by name, email
 * and Discord name or ID, with what the customer has on the media server and
 * the actions to put it right: a new invitation, a new link for the
 * customer, sync, reset, and everything about trials.
 */
class PaywarrCustomers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-user-star-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-user-star-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 101;

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $title = 'Paywarr customers';

    protected static ?string $slug = 'paywarr-customers';

    /** @var array<int, array> adminSummary() per subscription, for this request */
    private array $summaries = [];

    /** The customer card: only this customer's subscriptions, with everything about them on top. */
    #[Url]
    public ?int $customer = null;

    public function getTitle(): string
    {
        $user = $this->customer ? User::find($this->customer) : null;

        return $user ? (trim($user->first_name . ' ' . $user->last_name) ?: $user->email) : 'Paywarr customers';
    }

    /** How many customers need a look: link used without a user, or left the Discord. */
    public static function getNavigationBadge(): ?string
    {
        return ($count = self::problemCount()) > 0 ? (string) $count : null;
    }

    /** Subscriptions whose link was used without a user, or whose customer left the Discord (kept 5 minutes). */
    public static function problemCount(): int
    {
        return (int) Cache::remember('paywarr:admin:problems', 300, fn () => Service::query()
            ->whereIn('status', ['active', 'suspended'])
            ->whereHas('properties', fn (Builder $p) => $p->whereIn('key', [Wizarr::PROP_LINK_FAILED, Wizarr::PROP_DISCORD_LEFT]))
            ->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Customers to look at: a link used without a user, or left the Discord';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('All customers')->icon('ri-arrow-left-line')->color('gray')
                ->visible(fn () => $this->customer !== null)->url(fn () => static::getUrl()),
            \Paymenter\Extensions\Servers\Wizarr\Admin\Csv::action('paywarr-customers', fn () => self::csvRows())->visible(fn () => $this->customer === null),
            Action::make('message')->label('Send a message')->icon('ri-send-plane-line')->color('gray')
                ->visible(fn () => $this->customer !== null && self::canChange())
                ->modalWidth('2xl')->schema(PaywarrMessages::messageFields(false))->modalSubmitActionLabel('Send')
                ->action(function (array $data) {
                    try {
                        Notification::make()->title('Sent')->body(PaywarrMessages::send('user:' . $this->customer, $data))->success()->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->title('Not sent')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('reinviteAll')->label('Re-invite missing users')->icon('ri-user-received-line')->color('gray')
                ->visible(fn () => $this->customer === null && self::canChange())
                ->requiresConfirmation()
                ->modalHeading('Re-invite everyone whose user is gone')
                ->modalDescription('For a new media server, or users deleted there. Every active subscription whose user no longer exists on the media server gets a new invitation, mailed to the customer. Users that exist are left alone. If the media server got a new ID in Wizarr, choose it on the products first (Libraries per product).')
                ->action(function () {
                    $done = [];
                    $failed = [];
                    $services = Service::query()->where('status', 'active')
                        ->whereHas('product.server', fn (Builder $query) => $query->where('extension', 'Wizarr'))
                        ->with(['user', 'product.server', 'product.settings', 'properties'])->get();
                    foreach ($services as $service) {
                        try {
                            if ((new Wizarr([]))->forService($service)->adminReinvite($service, true)) {
                                Wizarr::logAdmin($service->user, $service->id, 'Re-invited: the user on the media server was gone', (string) Auth::user()?->email);
                                $done[] = '#' . $service->id;
                            }
                        } catch (Throwable $e) {
                            $failed[] = '#' . $service->id . ': ' . $e->getMessage();
                        }
                    }
                    PaywarrInventory::forget();
                    Cache::forget('paywarr:admin:problems');
                    $this->resetTable();
                    Notification::make()->title(count($done) . ' re-invited, ' . (count($services) - count($done) - count($failed)) . ' nothing to do')
                        ->body($failed ? implode("\n", array_slice($failed, 0, 8)) . (count($failed) > 8 ? "\n…" : '') : ($done ? implode(', ', $done) : null))
                        ->{$failed ? 'warning' : 'success'}()->persistent()->send();
                }),
            Action::make('renewExpired')->label('New links for expired ones')->icon('ri-mail-send-line')->color('gray')
                ->visible(fn () => $this->customer === null && self::canChange())
                ->requiresConfirmation()
                ->modalDescription('Every active subscription whose link expired before a user was made gets a new invitation, mailed to the customer.')
                ->action(function () {
                    $done = [];
                    foreach ($this->problemQuery(Service::query(), 'expired')->where('status', 'active')->with(['user', 'product.server', 'product.settings', 'properties'])->get() as $service) {
                        try {
                            (new Wizarr([]))->forService($service)->adminNewInvitation($service, true);
                            Wizarr::logAdmin($service->user, $service->id, 'New invitation (expired link, all at once)', (string) Auth::user()?->email);
                            $done[] = '#' . $service->id;
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }
                    PaywarrInventory::forget();
                    Notification::make()->title(count($done) . ' new link(s) sent')->body($done ? implode(', ', $done) : null)->success()->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    private static function canChange(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.update');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([...$this->card(), EmbeddedTable::make()]);
    }

    /** The customer card: who, Discord, note, requests, and what was done. */
    private function card(): array
    {
        $user = $this->customer ? User::with('properties')->find($this->customer) : null;
        if (!$user) {
            return [];
        }
        $props = $user->properties->pluck('value', 'key');
        $services = Service::where('user_id', $user->id)
            ->whereHas('product.server', fn (Builder $query) => $query->where('extension', 'Wizarr'))
            ->with(['product.server', 'product.settings', 'properties'])->get();
        $requests = [];
        try {
            $wizarr = new Wizarr([]);
            foreach ($wizarr->requestsSources($user, $services) as $source) {
                $quota = $wizarr->requestQuotaOf($source);
                if ($quota !== null) {
                    $requests[] = $source['label'] . ': ' . (!empty($quota['unlimited']) ? 'no limit' : implode(', ', array_map(fn ($part) => $part['used'] . ' of ' . $part['limit'] . ' ' . $part['label'], $quota['parts'])));
                }
            }
        } catch (Throwable $e) {
            $requests[] = 'Can\'t be read right now';
        }
        $log = array_map(fn ($entry) => date('M j, Y H:i', (int) $entry['at']) . ' · ' . ($entry['service'] ? '#' . $entry['service'] . ' · ' : '') . $entry['what'] . ($entry['by'] !== '' ? ' (' . $entry['by'] . ')' : ''),
            json_decode((string) ($props[Wizarr::PROP_LOG] ?? '[]'), true) ?: []);

        return [Section::make('Customer')->columns(4)->schema([
            TextEntry::make('email')->label('Email')->state($user->email),
            TextEntry::make('discord')->label('Discord')->state(($props['discord_username'] ?? '') !== '' ? $props['discord_username'] . ' (' . ($props['discord_id'] ?? '') . ')' : 'Not connected'),
            TextEntry::make('since')->label('Customer since')->state($user->created_at?->format('M j, Y') ?? '—'),
            TextEntry::make('subscriptions')->label('Paywarr subscriptions')->state($services->count() . ' (' . $services->where('status', 'active')->count() . ' active)'),
            TextEntry::make('requests')->label('Requests')->state($requests ?: ['None'])->listWithLineBreaks(),
            TextEntry::make('note')->label('Note')->state((string) ($props[Wizarr::PROP_NOTE] ?? '') ?: '—')->columnSpan(3),
            TextEntry::make('streams')->label('Streams and sharing (Tracearr)')->state($this->sharingLines($user, $services) ?: ['Nothing in the last 30 days'])->listWithLineBreaks()->columnSpanFull(),
            TextEntry::make('log')->label('What was done here')->state($log ?: ['Nothing yet'])->listWithLineBreaks()->columnSpanFull(),
        ])];
    }

    /** Times over the stream limit, and Tracearr's rule breaks, for this customer (last 30 days). */
    private function sharingLines(User $user, $services): array
    {
        $lines = [];
        foreach ($services as $service) {
            foreach (json_decode((string) (ExtensionHelper::getServiceProperties($service)[Wizarr::PROP_STREAMS_OVER] ?? '[]'), true) ?: [] as $event) {
                if ((int) $event['at'] > time() - 30 * 86400) {
                    $lines[(int) $event['at'] . '-' . count($lines)] = date('M j, H:i', (int) $event['at']) . ' · #' . $service->id . ' · ' . $event['streams'] . ' streams at once (max ' . $event['max'] . ')'
                        . (!empty($event['which']) ? ($event['stopped'] ? ', stopped: ' : ', over: ') . implode('; ', $event['which']) : '');
                }
            }
        }
        try {
            $wizarr = new Wizarr([]);
            $ext = $services->first() ? $wizarr->forService($services->first()) : $wizarr;
            foreach ($ext->sharingRows($services) as $row) {
                if ($row['service'] !== null && (int) $row['service']->user_id === (int) $user->id) {
                    $lines[(int) $row['at'] . '-' . count($lines)] = date('M j, H:i', (int) $row['at']) . ' · ' . $row['rule'] . ' (' . $row['severity'] . ') · ' . $row['username'] . ' on ' . $row['server'];
                }
            }
        } catch (Throwable $e) {
            $lines['0'] = 'Tracearr can\'t be read right now';
        }
        krsort($lines, SORT_NATURAL);

        return array_values(array_slice($lines, 0, 12));
    }

    /** Every Paywarr subscription as CSV rows. */
    public static function csvRows(): array
    {
        $rows = [];
        $wizarr = new Wizarr([]);
        foreach (PaywarrInventory::services() as $service) {
            $props = ExtensionHelper::getServiceProperties($service);
            $users = [];
            foreach ($props as $key => $value) {
                if ($key === 'wizarr_users' || str_starts_with((string) $key, 'wizarr_users:')) {
                    foreach (array_filter(explode(',', (string) $value)) as $entry) {
                        $users[] = rawurldecode(explode(':', $entry . '::')[1]);
                    }
                }
            }
            $rows[] = [$service->id, trim(($service->user->first_name ?? '') . ' ' . ($service->user->last_name ?? '')), $service->user->email ?? '',
                (string) ($service->user?->properties()->where('key', 'discord_username')->value('value') ?? ''), $service->product->name ?? '', $service->status,
                $service->price, $service->currency_code, $users, $props['trial_ends_at'] ?? '', (string) $service->created_at, (string) $service->expires_at];
        }

        return [['ID', 'Name', 'Email', 'Discord', 'Product', 'Status', 'Price', 'Currency', 'Media server users', 'Trial ends', 'Created', 'Renews'], $rows];
    }

    /** Subscriptions with a problem: failed, expired, new_link, discord_left or trial_over. */
    private function problemQuery(Builder $query, ?string $problem): Builder
    {
        return match ($problem) {
            'failed' => $query->whereHas('properties', fn (Builder $p) => $p->where('key', Wizarr::PROP_LINK_FAILED)),
            'expired' => $query
                ->whereHas('properties', fn (Builder $p) => $p->where('key', 'like', 'wizarr_invite_expires%')->where('value', '<', now()->utc()->format('Y-m-d\\TH:i:s')))
                ->whereDoesntHave('properties', fn (Builder $p) => $p->where('key', 'like', 'wizarr_users%')->where('value', '!=', '')),
            'new_link' => $query->whereHas('properties', fn (Builder $p) => $p->where('key', Wizarr::PROP_NEW_LINK)),
            'discord_left' => $query->whereHas('properties', fn (Builder $p) => $p->where('key', Wizarr::PROP_DISCORD_LEFT)),
            'paused' => $query->whereHas('properties', fn (Builder $p) => $p->where('key', Wizarr::PROP_PAUSED)),
            'trial_over' => $query->where('status', 'cancelled')
                ->whereHas('properties', fn (Builder $p) => $p->where('key', 'trial_ended_at'))
                ->whereDoesntHave('properties', fn (Builder $p) => $p->where('key', 'trial_converted_to')),
            default => $query,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Service::query()
                ->whereHas('product.server', fn (Builder $query) => $query->where('extension', 'Wizarr'))
                ->when($this->customer, fn (Builder $query) => $query->where('user_id', $this->customer))
                ->with(['user.properties', 'product.server', 'product.settings', 'properties']))
            ->columns([
                TextColumn::make('user.email')
                    ->label('Customer')
                    ->formatStateUsing(fn (Service $record) => trim(($record->user->first_name ?? '') . ' ' . ($record->user->last_name ?? '')) ?: $record->user->email)
                    ->url(fn (Service $record) => static::getUrl(['customer' => $record->user_id]))
                    ->color('primary')
                    ->description(fn (Service $record) => $record->user->email . (($discord = $this->summary($record)['discord']) !== '' ? ' · Discord: ' . $discord : '')
                        . (($note = $this->note($record)) !== '' ? ' · Note: ' . mb_strimwidth($note, 0, 60, '…') : ''))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $user) => $user
                        ->where('email', 'like', '%' . $search . '%')
                        ->orWhere('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhereHas('properties', fn (Builder $property) => $property
                            ->whereIn('key', ['discord_username', 'discord_id', Wizarr::PROP_NOTE])
                            ->where('value', 'like', '%' . $search . '%')))),
                TextColumn::make('product.name')
                    ->label('Subscription')
                    ->description(fn (Service $record) => '#' . $record->id . ' · ' . ($record->product->server->name ?? '')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('access')
                    ->label('Media server')
                    ->state(fn (Service $record) => $this->accessText($record))
                    ->listWithLineBreaks()
                    ->wrap(),
                TextColumn::make('trial')
                    ->label('Trial')
                    ->state(fn (Service $record) => $this->trialText($record))
                    ->listWithLineBreaks()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled']),
                SelectFilter::make('server')->label('Server')->relationship('product.server', 'name', fn (Builder $query) => $query->where('extension', 'Wizarr')),
                SelectFilter::make('problem')->label('Problems')
                    ->options([
                        'failed' => 'Link used, no user made',
                        'expired' => 'Link expired, no user',
                        'new_link' => 'May get a new link',
                        'discord_left' => 'Left the Discord',
                        'paused' => 'Paused',
                        'trial_over' => 'Trial ended, not bought',
                    ])
                    ->query(fn (Builder $query, array $data) => $this->problemQuery($query, $data['value'] ?? null)),
                TernaryFilter::make('no_user')
                    ->label('Without a user')
                    ->queries(
                        true: fn (Builder $query) => $query->whereDoesntHave('properties', fn (Builder $p) => $p->where('key', 'like', 'wizarr_users%')->where('value', '!=', '')),
                        false: fn (Builder $query) => $query->whereHas('properties', fn (Builder $p) => $p->where('key', 'like', 'wizarr_users%')->where('value', '!=', '')),
                    ),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ActionGroup::make([
                    $this->action('newInvitation', 'New invitation', 'ri-mail-send-line')
                        ->modalDescription('Makes a new invitation for every media server where the customer has no user yet. The old link stops working.')
                        ->schema([Toggle::make('mail')->label('Email the link to the customer')->default(true)])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $links = $wizarr->adminNewInvitation($record, (bool) $data['mail']);

                            return 'New link: ' . implode(', ', $links);
                        })),
                    $this->action('allowNewLink', 'Allow a new link', 'ri-link')
                        ->modalDescription('The customer gets "Get a new invitation link" on their subscription, once, even after using their invitation. Use it when creating the user failed, e.g. a Plex share.')
                        ->visible(fn (Service $record) => !$this->summary($record)['new_link'])
                        ->action(fn (Service $record) => $this->run($record, function (Wizarr $wizarr) use ($record) {
                            $wizarr->adminAllowNewLink($record);

                            return 'The customer can now get a new link on their subscription.';
                        })),
                    $this->action('withdrawNewLink', 'Withdraw the new link', 'ri-link-unlink')
                        ->visible(fn (Service $record) => $this->summary($record)['new_link'])
                        ->action(fn (Service $record) => $this->run($record, function (Wizarr $wizarr) use ($record) {
                            $wizarr->adminAllowNewLink($record, false);

                            return 'Withdrawn.';
                        })),
                    $this->action('sync', 'Sync now', 'ri-refresh-line', confirm: false)
                        ->action(fn (Service $record) => $this->run($record, function (Wizarr $wizarr) use ($record) {
                            $found = $wizarr->adminSync($record);

                            return $found ? 'Found: ' . implode(', ', $found) . '.' : 'No user on the media server yet.';
                        })),
                    $this->action('resetUser', 'Reset user', 'ri-user-unfollow-line')
                        ->color('danger')
                        ->modalDescription('Forgets the customer\'s user on the media server and makes a new invitation, so they start over.')
                        ->schema([
                            Toggle::make('mail')->label('Email the new link to the customer')->default(true),
                            Toggle::make('delete')->label('Also delete the user on the media server')->default(false)
                                ->helperText('Watch history there is lost. Off: the user stays there, without a subscription (see Media server users).'),
                        ])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $links = $wizarr->adminResetUser($record, (bool) $data['mail'], (bool) ($data['delete'] ?? false));

                            return 'Reset. New link: ' . implode(', ', $links);
                        })),
                    $this->action('passwordReset', 'Password reset link', 'ri-lock-password-line', confirm: false)
                        ->modalDescription('A link where the customer chooses a new password on Jellyfin, Emby or Silo. Plex users use their own Plex account.')
                        ->schema([Toggle::make('mail')->label('Email the link to the customer')->default(true)])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $links = $wizarr->adminPasswordReset($record, (bool) $data['mail']);

                            return 'Password link: ' . implode(', ', array_map(fn ($server, $url) => $server . ' ' . $url, array_keys($links), $links));
                        })),
                    $this->action('changeServers', 'Change media server', 'ri-swap-line', confirm: false)
                        ->visible(fn (Service $record) => ($b = PaywarrInventory::backendFor((int) ($record->product->server->id ?? 0))) !== null && $b['kind'] === 'wizarr' && $b['ok'])
                        ->modalDescription('E.g. from Jellyfin to Plex. New servers get an invitation (mailed); the user on servers taken away is removed, as at an upgrade.')
                        ->fillForm(fn (Service $record) => ['servers' => array_map('strval', $this->summary($record)['servers'])])
                        ->schema(fn (Service $record) => [
                            CheckboxList::make('servers')->label('Media servers')->required()
                                ->options(collect(PaywarrInventory::backendFor((int) $record->product->server->id)['servers'] ?? [])
                                    ->filter(fn ($s) => $s['verified'])->mapWithKeys(fn ($s) => [(string) $s['id'] => $s['name'] . ' (' . ucfirst($s['type']) . ')'])->all()),
                        ])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $wizarr->adminChangeServers($record, $data['servers']);

                            return 'Media servers changed.';
                        })),
                    $this->action('libraries', 'Libraries for this customer', 'ri-film-line', confirm: false)
                        ->modalDescription('Used for the customer\'s next invitation instead of the product\'s. Wizarr can\'t change the libraries of a user that exists; use "Reset user" or "New invitation" to apply them.')
                        ->fillForm(fn (Service $record) => ['libraries' => array_map('strval', $this->summary($record)['libraries'])])
                        ->schema(fn (Service $record) => [
                            CheckboxList::make('libraries')->label('Libraries')->columns(2)->helperText('None chosen: the product\'s libraries.')
                                ->options(collect(PaywarrInventory::backendFor((int) ($record->product->server->id ?? 0))['libraries'] ?? [])
                                    ->mapWithKeys(fn ($l) => [(string) $l['id'] => $l['name'] . ($l['server'] !== '' ? ' · ' . $l['server'] : '')])->all()),
                        ])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $wizarr->adminSetLibraries($record, $data['libraries'] ?? []);

                            return ($data['libraries'] ?? []) ? 'Own libraries saved for the next invitation.' : 'Back to the product\'s libraries.';
                        })),
                    $this->action('reinvite', 'Re-invite missing users', 'ri-user-received-line', confirm: false)
                        ->modalDescription('For a new media server, or a user deleted there: where the customer\'s user no longer exists, they get a new invitation. A user that exists is left alone.')
                        ->schema([Toggle::make('mail')->label('Email the link to the customer')->default(true)])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $links = $wizarr->adminReinvite($record, (bool) $data['mail']);

                            return $links ? 'Re-invited: ' . implode(', ', $links) : 'Nothing to do: the customer\'s user exists.';
                        })),
                    $this->action('linkUser', 'Link an existing user', 'ri-user-shared-line', confirm: false)
                        ->modalDescription('Gives the subscription a user that already exists on the media server (e.g. an account from before), instead of an invitation.')
                        ->schema(fn (Service $record) => [
                            Select::make('user')->label('User on the media server')->required()->searchable()->options($this->freeUsers($record)),
                        ])
                        ->action(fn (Service $record, array $data) => $this->run($record, fn (Wizarr $wizarr) => 'Linked: ' . $wizarr->adminLinkUser($record, (int) $data['user'], PaywarrInventory::services()))),
                    $this->action('note', 'Note', 'ri-sticky-note-line', confirm: false)
                        ->modalDescription('About the customer, for you only. Shown here and found by the search.')
                        ->fillForm(fn (Service $record) => ['note' => $this->note($record)])
                        ->schema([Textarea::make('note')->label('Note')->rows(4)->maxLength(1000)])
                        ->action(function (Service $record, array $data) {
                            $note = trim((string) ($data['note'] ?? ''));
                            $note === ''
                                ? $record->user->properties()->where('key', Wizarr::PROP_NOTE)->delete()
                                : $record->user->properties()->updateOrCreate(['key' => Wizarr::PROP_NOTE], ['name' => 'Paywarr note', 'value' => $note]);
                            Notification::make()->title('Note saved')->success()->send();
                        }),
                ])->label('Access')->tooltip('Access: invitation, new link, sync, reset')->icon('ri-key-2-line'),
                ActionGroup::make([
                    $this->action('pause', 'Pause', 'ri-pause-circle-line', confirm: false)
                        ->visible(fn (Service $record) => $record->status === 'active' && !$this->summary($record)['paused'])
                        ->modalDescription('The access is switched off as when unpaid, without telling the customer, and the paid time left is kept. Paymenter sends no invoice while paused. A card or PayPal subscription at the payment provider keeps charging: pause it there too. On Plex the share is removed, and the customer gets a new invitation when it resumes.')
                        ->schema([DateTimePicker::make('until')->label('Resume by itself on')->seconds(false)->helperText('Empty: until you resume it.')])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $wizarr->adminPause($record, $data['until'] ? strtotime($data['until']) : null);

                            return 'Paused' . ($data['until'] ? ' until ' . date('M j, Y H:i', strtotime($data['until'])) : '') . '.';
                        })),
                    $this->action('resume', 'Resume', 'ri-play-circle-line')
                        ->visible(fn (Service $record) => (bool) $this->summary($record)['paused'])
                        ->modalDescription('The access is switched on again, with the paid time that was left.')
                        ->action(fn (Service $record) => $this->run($record, function (Wizarr $wizarr) use ($record) {
                            $wizarr->adminResume($record);

                            return 'Resumed.';
                        })),
                ])->label('Subscription')->tooltip('Subscription: pause, resume')->icon('ri-pause-circle-line'),
                ActionGroup::make([
                    $this->action('setTrial', 'Set start and end', 'ri-calendar-event-line', confirm: false)
                        ->visible(fn (Service $record) => $this->summary($record)['trial'] !== null)
                        ->fillForm(fn (Service $record) => [
                            'start' => ($t = $this->summary($record)['trial'])['started'] ? date('Y-m-d H:i:s', $t['started']) : null,
                            'end' => $t['ends'] ? date('Y-m-d H:i:s', $t['ends']) : null,
                        ])
                        ->schema([
                            DateTimePicker::make('start')->label('Start')->seconds(false),
                            DateTimePicker::make('end')->label('End')->seconds(false)->required()->helperText('An end in the past ends the trial now; a later end reopens a trial that had ended.'),
                        ])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $result = $wizarr->adminSetTrial($record, $data['start'] ? strtotime($data['start']) : null, strtotime($data['end']));

                            return $this->trialResult($result);
                        })),
                    $this->action('extendTrial', 'Extend', 'ri-add-circle-line', confirm: false)
                        ->visible(fn (Service $record) => $this->summary($record)['trial'] !== null)
                        ->schema([TextInput::make('days')->label('Days')->integer()->minValue(1)->default(7)->required()])
                        ->action(fn (Service $record, array $data) => $this->run($record, fn (Wizarr $wizarr) => $this->trialResult($wizarr->adminExtendTrial($record, (int) $data['days'])))),
                    $this->action('restartTrial', 'Start over', 'ri-restart-line')
                        ->visible(fn (Service $record) => $this->summary($record)['trial'] !== null)
                        ->modalDescription('The trial starts again now, with its full number of days.')
                        ->action(fn (Service $record) => $this->run($record, fn (Wizarr $wizarr) => $this->trialResult($wizarr->adminRestartTrial($record)))),
                    $this->action('endTrial', 'End now', 'ri-stop-circle-line')
                        ->color('danger')
                        ->visible(fn (Service $record) => ($t = $this->summary($record)['trial']) !== null && !$t['ended'])
                        ->modalDescription('The user is disabled and the subscription closed, as at the normal end of the trial.')
                        ->action(fn (Service $record) => $this->run($record, fn (Wizarr $wizarr) => $this->trialResult($wizarr->adminEndTrial($record)))),
                    $this->action('allowNewTrial', 'May try again', 'ri-loop-left-line')
                        ->visible(fn (Service $record) => ($t = $this->summary($record)['trial']) !== null && !$t['forgiven'])
                        ->modalDescription('This trial no longer counts: the customer may order a trial on this server again. A "Per user limit" on the product in Paymenter still applies; "Give trial days" always works.')
                        ->action(fn (Service $record) => $this->run($record, function (Wizarr $wizarr) use ($record) {
                            $wizarr->adminAllowNewTrial($record);

                            return 'The customer may take a trial on this server again.';
                        })),
                    $this->action('giveTrialDays', 'Give trial days', 'ri-gift-line', confirm: false)
                        ->modalDescription(fn (Service $record) => 'Makes this subscription a trial: it ends after the days given, and closes as any trial.'
                            . ((float) $record->price > 0 ? ' Careful: this is a paid subscription, and it will be closed when the trial ends.' : ''))
                        ->schema([TextInput::make('days')->label('Days')->integer()->minValue(1)->default(7)->required()])
                        ->action(fn (Service $record, array $data) => $this->run($record, fn (Wizarr $wizarr) => $this->trialResult($wizarr->adminGiveTrialDays($record, (int) $data['days'])))),
                ])->label('Trial')->tooltip('Trial: dates, extend, start over, end, give days')->icon('ri-timer-line'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->bulk('bulkSync', 'Sync now', 'ri-refresh-line', fn (Wizarr $wizarr, Service $service) => $wizarr->adminSync($service)),
                    $this->bulk('bulkNewLink', 'Allow a new link', 'ri-link', fn (Wizarr $wizarr, Service $service) => $wizarr->adminAllowNewLink($service)),
                    $this->bulk('bulkInvite', 'New invitation (mailed)', 'ri-mail-send-line', fn (Wizarr $wizarr, Service $service) => $wizarr->adminNewInvitation($service, true)),
                    $this->bulk('bulkReinvite', 'Re-invite missing users (mailed)', 'ri-user-received-line', fn (Wizarr $wizarr, Service $service) => $wizarr->adminReinvite($service, true)),
                    $this->bulk('bulkDays', 'Give extra days', 'ri-calendar-check-line', fn (Wizarr $wizarr, Service $service, array $data) => $wizarr->adminGiveDays($service, (int) $data['days'], (bool) $data['tell']))
                        ->modalDescription('Adds the days to each subscription (a trial gets a longer trial). With "Tell them", the customer gets "Extra days" (Mail → Automatic mails).')
                        ->schema([TextInput::make('days')->label('Days')->integer()->minValue(1)->maxValue(366)->default(7)->required(), Toggle::make('tell')->label('Tell them')->default(true)]),
                    BulkAction::make('bulkMessage')->label('Send a message')->icon('ri-send-plane-line')->modalWidth('2xl')
                        ->schema(PaywarrMessages::messageFields(false))->modalSubmitActionLabel('Send')
                        ->action(function (Collection $records, array $data) {
                            $sent = [];
                            foreach ($records->pluck('user_id')->unique() as $userId) {
                                try {
                                    PaywarrMessages::send('user:' . $userId, $data);
                                    $sent[] = $userId;
                                } catch (Throwable $e) {
                                    report($e);
                                }
                            }
                            Notification::make()->title('Sent to ' . count($sent) . ' customer(s)')->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    $this->bulk('bulkServers', 'Change media server', 'ri-swap-line', fn (Wizarr $wizarr, Service $service, array $data) => $wizarr->adminChangeServers($service, $data['servers']))
                        ->modalDescription('Each selected subscription gets these media servers: new ones get an invitation (mailed), the user on servers taken away is removed.')
                        ->schema(fn () => [CheckboxList::make('servers')->label('Media servers')->required()->options(self::allMediaServers())]),
                ])->visible(fn () => self::canChange()),
            ]);
    }

    /** Verified media servers of every Wizarr, for "Change media server" on several. */
    private static function allMediaServers(): array
    {
        $options = [];
        foreach (PaywarrInventory::get() as $backend) {
            if ($backend['kind'] === 'wizarr' && $backend['ok']) {
                foreach ($backend['servers'] as $s) {
                    if ($s['verified']) {
                        $options[(string) $s['id']] = $s['name'] . ' (' . ucfirst($s['type']) . ')';
                    }
                }
            }
        }

        return $options;
    }

    /** The same action on every selected subscription; tells which worked. */
    private function bulk(string $name, string $label, string $icon, callable $fn): BulkAction
    {
        return BulkAction::make($name)->label($label)->icon($icon)->requiresConfirmation()
            ->action(function (Collection $records, array $data = []) use ($label, $fn) {
                $done = [];
                $failed = [];
                foreach ($records as $record) {
                    try {
                        $fn((new Wizarr([]))->forService($record), $record, $data);
                        Wizarr::logAdmin($record->user, $record->id, $label . ' (several at once)', (string) Auth::user()?->email);
                        $done[] = '#' . $record->id;
                    } catch (Throwable $e) {
                        $failed[] = '#' . $record->id . ': ' . $e->getMessage();
                    }
                }
                PaywarrInventory::forget();
                Cache::forget('paywarr:admin:problems');
                Notification::make()->title(count($done) . ' done' . ($failed ? ', ' . count($failed) . ' not' : ''))->body($failed ? implode("\n", $failed) : null)->{$failed ? 'warning' : 'success'}()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** An action that changes something: staff who may edit services only, confirmed first unless $confirm is false. */
    private function action(string $name, string $label, string $icon, bool $confirm = true): Action
    {
        $action = Action::make($name)->label($label)->icon($icon)->hidden(fn () => !self::canChange());

        return $confirm ? $action->requiresConfirmation() : $action->modalWidth('md');
    }

    /** Runs $fn with Paywarr set up for the subscription's server, and tells how it went. */
    private function run(Service $record, callable $fn): void
    {
        try {
            $message = $fn((new Wizarr([]))->forService($record));
            Notification::make()->title('Done')->body($message)->success()->send();
            Wizarr::logAdmin($record->user, $record->id, $message, (string) Auth::user()?->email);
        } catch (DisplayException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->warning()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->send();
        }
        unset($this->summaries[$record->id]);
        PaywarrInventory::forget();
        Cache::forget('paywarr:admin:problems');
    }

    private function note(Service $record): string
    {
        return (string) ($record->user->properties->firstWhere('key', Wizarr::PROP_NOTE)?->value ?? '');
    }

    /** Users on the subscription's media servers that no subscription has yet. */
    private function freeUsers(Service $record): array
    {
        $backend = PaywarrInventory::backendFor((int) ($record->product->server->id ?? 0));
        $options = [];
        foreach ($backend['users'] ?? [] as $user) {
            if ($user['owner'] === null) {
                $options[$user['id']] = $user['username'] . ($user['email'] !== '' ? ' (' . $user['email'] . ')' : '') . ' · ' . $user['server'];
            }
        }

        return $options;
    }

    private function summary(Service $record): array
    {
        return $this->summaries[$record->id] ??= (new Wizarr([]))->adminSummary($record);
    }

    /** @return string[] one line per media server */
    private function accessText(Service $record): array
    {
        $summary = $this->summary($record);
        if ($summary['gift']) {
            return ['Gift card: ' . $summary['gift']['code'] . ($summary['gift']['used'] ? ' (used)' : ' (not used yet)') . ($summary['gift']['sent_to'] !== '' ? ' · sent to ' . $summary['gift']['sent_to'] : '')];
        }
        $lines = [];
        foreach ($summary['grants'] as $grant) {
            $server = $grant['server'] !== '' ? $grant['server'] . ': ' : '';
            if ($grant['users']) {
                $lines[] = $server . 'user ' . implode(', ', $grant['users']);
            } elseif ($grant['invite_url'] !== '') {
                $expires = $grant['invite_expires'] !== '' && ($ts = strtotime($grant['invite_expires'])) ? ' (link until ' . date('M j, H:i', $ts) . ')' : '';
                $lines[] = $server . 'invited, no user yet' . $expires;
            } else {
                $lines[] = $server . 'nothing yet';
            }
        }
        if ($summary['new_link']) {
            $lines[] = 'May get a new link';
        }
        if ($summary['paused']) {
            $lines[] = 'Paused' . ($summary['paused']['until'] ? ' until ' . date('M j, Y', $summary['paused']['until']) : '');
        }
        if ($summary['libraries']) {
            $lines[] = 'Own libraries for the next invitation';
        }
        if ($summary['discord_left']) {
            $lines[] = 'Left the Discord ' . date('M j, H:i', $summary['discord_left']);
        }

        return $lines ?: ['nothing yet'];
    }

    /** @return string[] */
    private function trialText(Service $record): array
    {
        $trial = $this->summary($record)['trial'];
        if ($trial === null) {
            return [];
        }
        $lines = [$trial['days'] . ' days' . ($trial['given'] ? ' (given)' : '')];
        if ($trial['started']) {
            $lines[] = 'Started ' . date('M j, Y', $trial['started']);
        }
        if ($trial['converted']) {
            $lines[] = 'Bought';
        } elseif ($trial['ended']) {
            $lines[] = 'Ended ' . date('M j, Y', $trial['ended']);
        } elseif ($trial['ends']) {
            $lines[] = 'Ends ' . date('M j, Y H:i', $trial['ends']);
        } else {
            $lines[] = 'Starts when the user is created';
        }
        if ($trial['forgiven']) {
            $lines[] = 'May try again';
        }

        return $lines;
    }

    private function trialResult(string $result): string
    {
        return match ($result) {
            'ended' => 'The trial has ended; the user is disabled.',
            'reopened' => 'The trial is open again; the user is enabled.',
            'waiting' => 'Trial days given. The clock starts when the customer creates their user.',
            default => 'Trial saved.',
        };
    }
}
