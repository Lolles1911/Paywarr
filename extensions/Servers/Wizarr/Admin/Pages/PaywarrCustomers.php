<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Exceptions\DisplayException;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Paywarr customers';

    protected static ?string $title = 'Paywarr customers';

    protected static ?string $slug = 'paywarr-customers';

    /** @var array<int, array> adminSummary() per subscription, for this request */
    private array $summaries = [];

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
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Service::query()
                ->whereHas('product.server', fn (Builder $query) => $query->where('extension', 'Wizarr'))
                ->with(['user.properties', 'product.server', 'product.settings', 'properties']))
            ->columns([
                TextColumn::make('user.email')
                    ->label('Customer')
                    ->formatStateUsing(fn (Service $record) => trim(($record->user->first_name ?? '') . ' ' . ($record->user->last_name ?? '')) ?: $record->user->email)
                    ->description(fn (Service $record) => $record->user->email . (($discord = $this->summary($record)['discord']) !== '' ? ' · Discord: ' . $discord : ''))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $user) => $user
                        ->where('email', 'like', '%' . $search . '%')
                        ->orWhere('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhereHas('properties', fn (Builder $property) => $property
                            ->whereIn('key', ['discord_username', 'discord_id'])
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
                        ->modalDescription('Forgets the customer\'s user on the media server and makes a new invitation, so they start over. The user itself stays on the media server; remove it there if it should go.')
                        ->schema([Toggle::make('mail')->label('Email the new link to the customer')->default(true)])
                        ->action(fn (Service $record, array $data) => $this->run($record, function (Wizarr $wizarr) use ($record, $data) {
                            $links = $wizarr->adminResetUser($record, (bool) $data['mail']);

                            return 'Reset. New link: ' . implode(', ', $links);
                        })),
                ])->label('Access')->tooltip('Access: invitation, new link, sync, reset')->icon('ri-key-2-line'),
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
            ]);
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
        } catch (DisplayException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->warning()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->send();
        }
        unset($this->summaries[$record->id]);
    }

    private function summary(Service $record): array
    {
        return $this->summaries[$record->id] ??= (new Wizarr([]))->adminSummary($record);
    }

    /** @return string[] one line per media server */
    private function accessText(Service $record): array
    {
        $summary = $this->summary($record);
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
