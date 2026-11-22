<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Exceptions\DisplayException;
use Filament\Actions\Action;
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
use Throwable;

/**
 * Paywarr → Invitations: every invitation in Wizarr and Silo, with the
 * subscription it belongs to, and a clean-up of expired ones and of unused
 * ones that no subscription has.
 */
class PaywarrInvitations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-send-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 103;

    protected static ?string $navigationLabel = 'Invitations';

    protected static ?string $title = 'Invitations';

    protected static ?string $slug = 'paywarr-invitations';

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cleanUp')->label('Clean up')->icon('ri-brush-line')->color('gray')
                ->visible(fn () => self::canChange())
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Deletes ' . count($this->cleanable()) . ' invitation(s): expired ones, and unused ones that no subscription has. Used invitations and those of subscriptions are kept.')
                ->action(function () {
                    $done = 0;
                    $failed = [];
                    foreach ($this->cleanable() as $row) {
                        try {
                            PaywarrInventory::wizarrFor($this->backend($row))->adminDeleteInvitation((int) $row['id']);
                            $done++;
                        } catch (Throwable $e) {
                            $failed[] = $row['code'] . ': ' . $e->getMessage();
                        }
                    }
                    PaywarrInventory::forget();
                    $this->resetTable();
                    Notification::make()->title($done . ' deleted')->body($failed ? implode("\n", $failed) : null)->{$failed ? 'warning' : 'success'}()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, array $filters) => $this->rows($search, $filters))
            ->paginated(false)
            ->searchable()
            ->columns([
                TextColumn::make('code')->label('Code')->fontFamily('mono')->description(fn (array $record) => $record['backend']),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) { 'pending' => 'info', 'used' => 'success', default => 'gray' }),
                TextColumn::make('servers')->label('Media server')->state(fn (array $record) => implode(', ', $record['servers']))->placeholder('—'),
                TextColumn::make('expires')->label('Valid until')->formatStateUsing(fn (?string $state) => $state && ($ts = strtotime($state)) ? date('M j, Y H:i', $ts) : '')->placeholder('No end'),
                TextColumn::make('owner')->label('Subscription')
                    ->state(fn (array $record) => $record['owner'] ? $record['owner']['customer'] . ' · ' . $record['owner']['product'] . ' #' . $record['owner']['service'] : 'No subscription')
                    ->badge(fn (array $record) => !$record['owner'])
                    ->color(fn (array $record) => $record['owner'] ? null : 'warning'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Unused', 'used' => 'Used', 'expired' => 'Expired']),
                TernaryFilter::make('owned')->label('Subscription')->placeholder('All')->trueLabel('With a subscription')->falseLabel('Without a subscription'),
            ])
            ->recordActions([
                Action::make('copy')->label('Open link')->icon('ri-external-link-line')
                    ->url(fn (array $record) => $record['url'] ?: null, shouldOpenInNewTab: true)
                    ->visible(fn (array $record) => $record['status'] === 'pending' && $record['url'] !== ''),
                Action::make('delete')->label('Delete')->icon('ri-delete-bin-line')->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['status'] !== 'used' && self::canChange())
                    ->modalDescription(fn (array $record) => $record['owner'] ? 'Subscription #' . $record['owner']['service'] . ' uses this link. The customer can get a new one on their subscription.' : 'The link stops working.')
                    ->action(function (array $record) {
                        try {
                            PaywarrInventory::wizarrFor($this->backend($record))->adminDeleteInvitation((int) $record['id']);
                            Notification::make()->title('Deleted')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Not done')->body($e->getMessage())->danger()->send();
                        }
                        PaywarrInventory::forget();
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No invitations');
    }

    private function rows(?string $search, array $filters): array
    {
        $status = $filters['status']['value'] ?? null;
        $owned = $filters['owned']['value'] ?? null;
        $rows = [];
        foreach (PaywarrInventory::get() as $backend) {
            $public = $backend['kind'] === 'wizarr' && ($backend['invitations'] ?? []) ? $this->publicBase($backend) : '';
            foreach ($backend['invitations'] ?? [] as $invitation) {
                if ($status && $invitation['status'] !== $status) {
                    continue;
                }
                if ($owned !== null && $owned !== '' && (bool) $owned !== ($invitation['owner'] !== null)) {
                    continue;
                }
                $haystack = strtolower($invitation['code'] . ' ' . implode(' ', $invitation['servers']) . ' ' . ($invitation['owner']['customer'] ?? '') . ' ' . ($invitation['owner']['email'] ?? ''));
                if ($search && !str_contains($haystack, strtolower($search))) {
                    continue;
                }
                $rows[$backend['key'] . '|' . $invitation['id']] = $invitation + [
                    'backend_key' => $backend['key'],
                    'backend' => PaywarrInventory::label($backend),
                    'url' => $public !== '' && $invitation['code'] !== '' ? $public . '/j/' . $invitation['code'] : '',
                ];
            }
        }
        uasort($rows, fn ($a, $b) => [$a['status'] !== 'pending', $a['expires']] <=> [$b['status'] !== 'pending', $b['expires']]);

        return $rows;
    }

    /** Expired invitations, and unused ones that no subscription has. */
    private function cleanable(): array
    {
        return array_filter($this->rows(null, []), fn ($row) => $row['status'] === 'expired' || ($row['status'] === 'pending' && !$row['owner']));
    }

    /** Where customers open a Wizarr link: the server's "Public address", else Wizarr's own. */
    private function publicBase(array $backend): string
    {
        return rtrim((string) (PaywarrInventory::wizarrFor($backend)->config('public_url') ?: $backend['host']), '/');
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
}
