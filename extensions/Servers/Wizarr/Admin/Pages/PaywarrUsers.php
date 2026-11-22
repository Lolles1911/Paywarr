<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Exceptions\DisplayException;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Users: every user on your media servers, with the subscription
 * it belongs to. Users without one (left over, made by hand, a friend from
 * before) can be linked to a subscription, switched off or deleted.
 */
class PaywarrUsers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-group-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 102;

    protected static ?string $navigationLabel = 'Media server users';

    protected static ?string $title = 'Media server users';

    protected static ?string $slug = 'paywarr-users';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, array $filters) => $this->rows($search, $filters))
            ->paginated(false)
            ->searchable()
            ->columns([
                TextColumn::make('username')->label('User')->description(fn (array $record) => $record['email']),
                TextColumn::make('server')->label('Media server')->description(fn (array $record) => $record['backend']),
                TextColumn::make('owner')->label('Subscription')
                    ->state(fn (array $record) => $record['owner'] ? $record['owner']['customer'] . ' · ' . $record['owner']['product'] . ' #' . $record['owner']['service'] : 'No subscription')
                    ->description(fn (array $record) => $record['owner'] ? ucfirst($record['owner']['status']) : null)
                    ->badge(fn (array $record) => !$record['owner'])
                    ->color(fn (array $record) => $record['owner'] ? null : 'warning'),
                TextColumn::make('state')->label('Status')
                    ->state(fn (array $record) => $record['disabled'] === null ? '' : ($record['disabled'] ? 'Disabled' : 'Enabled'))
                    ->badge()->color(fn (string $state) => $state === 'Disabled' ? 'gray' : 'success')->placeholder('—'),
                TextColumn::make('cleanup')->label('Clean-up')
                    ->state(fn (array $record) => $this->cleanupText($record))
                    ->badge()->color(fn (string $state) => str_starts_with($state, 'Kept') ? 'success' : (str_contains($state, 'deleted') ? 'danger' : 'warning'))
                    ->placeholder('—'),
                TextColumn::make('created')->label('Created')->formatStateUsing(fn (?string $state) => $state && ($ts = strtotime($state)) ? date('M j, Y', $ts) : '')->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('owned')->label('Subscription')->placeholder('All users')
                    ->trueLabel('With a subscription')->falseLabel('Without a subscription')->default(false),
                SelectFilter::make('server')->label('Media server')->options(fn () => $this->serverOptions()),
            ])
            ->recordActions([
                Action::make('link')->label('Link to a subscription')->icon('ri-link')
                    ->visible(fn (array $record) => !$record['owner'] && self::canChange())
                    ->modalDescription('The subscription gets this user instead of an invitation; an unused link of it is withdrawn.')
                    ->schema(fn (array $record) => [
                        Select::make('service')->label('Subscription')->required()->searchable()
                            ->options(fn () => $this->subscriptionOptions($record)),
                    ])
                    ->action(fn (array $record, array $data) => $this->run(function () use ($record, $data) {
                        $service = Service::with(['user', 'product.server.settings', 'properties'])->findOrFail((int) $data['service']);
                        $backend = $this->backend($record);

                        return 'Linked: ' . PaywarrInventory::wizarrFor($backend)->forService($service)->adminLinkUser($service, (int) $record['id'], PaywarrInventory::services());
                    })),
                Action::make('keep')->label(fn (array $record) => isset(Wizarr::cleanupKeep()[$record['backend_key'] . '|' . $record['id']]) ? 'Don\'t keep' : 'Keep')
                    ->icon('ri-shield-user-line')
                    ->visible(fn (array $record) => !$record['owner'] && self::canChange())
                    ->tooltip('Kept users are never switched off or deleted by the clean-up')
                    ->action(function (array $record) {
                        $key = $record['backend_key'] . '|' . $record['id'];
                        Wizarr::setCleanupKeep($key, !isset(Wizarr::cleanupKeep()[$key]));
                        $this->resetTable();
                    }),
                Action::make('disable')->label('Disable')->icon('ri-forbid-line')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['disabled'] !== true && self::canChange())
                    ->action(fn (array $record) => $this->run(fn () => PaywarrInventory::wizarrFor($this->backend($record))->adminUserAction((int) $record['id'], 'disable'))),
                Action::make('enable')->label('Enable')->icon('ri-checkbox-circle-line')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['disabled'] !== false && self::canChange())
                    ->action(fn (array $record) => $this->run(fn () => PaywarrInventory::wizarrFor($this->backend($record))->adminUserAction((int) $record['id'], 'enable'))),
                Action::make('delete')->label('Delete')->icon('ri-delete-bin-line')->color('danger')->requiresConfirmation()
                    ->modalDescription(fn (array $record) => 'Deletes ' . $record['username'] . ' on ' . $record['server'] . '. Watch history there is lost.'
                        . ($record['owner'] ? ' Careful: subscription #' . $record['owner']['service'] . ' uses this user.' : ''))
                    ->visible(fn () => self::canChange())
                    ->action(fn (array $record) => $this->run(fn () => PaywarrInventory::wizarrFor($this->backend($record))->adminUserAction((int) $record['id'], 'delete'))),
            ])
            ->emptyStateHeading('No users here')
            ->emptyStateDescription('With the filter "Without a subscription", every user on your media servers belongs to a subscription.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cleanupLog')->label('Clean-up log')->icon('ri-history-line')->color('gray')
                ->modalHeading('What the clean-up did')
                ->modalDescription('Users without a subscription are switched off after "Clean-up: switch off after (days)" and deleted after "Clean-up: delete after (days)" more, set on the Wizarr server. Expired invitations that belong to nobody are removed a week after they expired.')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->schema(fn () => [\Filament\Infolists\Components\TextEntry::make('log')->hiddenLabel()
                    ->state(array_map(fn ($e) => date('M j, Y H:i', (int) $e['at']) . ' · ' . $e['what'], array_slice(Wizarr::cleanupLog(), 0, 50)) ?: ['Nothing yet'])->listWithLineBreaks()]),
        ];
    }

    /** "Switched off Nov 3, deleted Nov 17" and the like, for a user without a subscription. */
    private function cleanupText(array $record): string
    {
        if ($record['owner']) {
            return '';
        }
        $key = $record['backend_key'] . '|' . $record['id'];
        if (isset(Wizarr::cleanupKeep()[$key])) {
            return 'Kept';
        }
        $row = Wizarr::cleanupState()[$key] ?? null;
        if ($row === null) {
            return '';
        }
        $settings = \App\Helpers\ExtensionHelper::settingsToArray(\App\Models\Server::with('settings')->find($this->backend($record)['server_id'])?->settings ?? []);
        $off = (int) ($settings['cleanup_disable_days'] ?? 0);
        $delete = (int) ($settings['cleanup_delete_days'] ?? 0);
        if ($row['disabled_at'] !== null) {
            return 'Switched off ' . date('M j', (int) $row['disabled_at']) . ($delete > 0 ? ', deleted ' . date('M j', (int) $row['disabled_at'] + $delete * 86400) : '');
        }

        return $off > 0 ? 'Switched off ' . date('M j', (int) $row['first_seen'] + $off * 86400) : '';
    }

    private static function canChange(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.update');
    }

    private function rows(?string $search, array $filters): array
    {
        $owned = $filters['owned']['value'] ?? null;
        $server = $filters['server']['value'] ?? null;
        $rows = [];
        foreach (PaywarrInventory::get() as $backend) {
            foreach ($backend['users'] ?? [] as $user) {
                if ($owned !== null && $owned !== '' && (bool) $owned !== ($user['owner'] !== null)) {
                    continue;
                }
                if ($server && $server !== $backend['key'] . '|' . $user['server']) {
                    continue;
                }
                $haystack = strtolower($user['username'] . ' ' . $user['email'] . ' ' . ($user['owner']['customer'] ?? '') . ' ' . ($user['owner']['email'] ?? ''));
                if ($search && !str_contains($haystack, strtolower($search))) {
                    continue;
                }
                $rows[$backend['key'] . '|' . $user['id']] = $user + ['backend_key' => $backend['key'], 'backend' => PaywarrInventory::label($backend)];
            }
        }

        return $rows;
    }

    private function serverOptions(): array
    {
        $options = [];
        foreach (PaywarrInventory::get() as $backend) {
            foreach ($backend['servers'] ?? [] as $server) {
                $options[$backend['key'] . '|' . $server['name']] = $server['name'] . ' (' . PaywarrInventory::label($backend) . ')';
            }
        }

        return $options;
    }

    /** Subscriptions on the same Wizarr or Silo as the user, without a user there yet. */
    private function subscriptionOptions(array $record): array
    {
        $backend = $this->backend($record);
        $options = [];
        foreach (PaywarrInventory::services() as $service) {
            if (!isset($backend['paymenter'][(int) ($service->product->server->id ?? 0)]) || in_array($service->status, ['cancelled'], true)) {
                continue;
            }
            $options[$service->id] = (trim(($service->user->first_name ?? '') . ' ' . ($service->user->last_name ?? '')) ?: $service->user->email)
                . ' · ' . ($service->product->name ?? '') . ' #' . $service->id . ' (' . $service->status . ')';
        }

        return $options;
    }

    private function backend(array $record): array
    {
        foreach (PaywarrInventory::get() as $backend) {
            if ($backend['key'] === $record['backend_key']) {
                return $backend;
            }
        }
        throw new DisplayException('That Wizarr or Silo is no longer set up.');
    }

    private function run(callable $fn): void
    {
        try {
            $message = (string) $fn();
            Notification::make()->title('Done')->body($message !== '' ? $message : null)->success()->send();
        } catch (DisplayException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->warning()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->send();
        }
        PaywarrInventory::forget();
        $this->resetTable();
    }
}
