<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Streams and sharing: the last 30 days of subscriptions that
 * streamed more at once than their product allows (and what was stopped),
 * and Tracearr's own rule breaks (e.g. one account in two places), each
 * with its customer.
 */
class PaywarrSharing extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-share-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 106;

    protected static ?string $navigationLabel = 'Streams and sharing';

    protected static ?string $title = 'Streams and sharing';

    protected static ?string $slug = 'paywarr-sharing';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $recent = count(array_filter(self::rows(), fn ($row) => $row['at'] > time() - 7 * 86400));
        } catch (Throwable $e) {
            return null;
        }

        return $recent > 0 ? (string) $recent : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'In the last 7 days';
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

                return array_filter(self::rows(), fn ($row) => (!$kind || $row['kind'] === $kind)
                    && (!$search || str_contains(strtolower($row['customer'] . ' ' . $row['what'] . ' ' . $row['detail']), strtolower($search))));
            })
            ->paginated([25, 50, 100])
            ->searchable()
            ->columns([
                TextColumn::make('at')->label('When')->formatStateUsing(fn ($state) => date('M j, H:i', (int) $state)),
                TextColumn::make('customer')->label('Customer')
                    ->url(fn (array $record) => $record['user'] ? PaywarrCustomers::getUrl(['customer' => $record['user']]) : null)
                    ->description(fn (array $record) => $record['product']),
                TextColumn::make('kind')->label('Kind')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'limit' ? 'Stream limit' : 'Tracearr rule')
                    ->color(fn (string $state) => $state === 'limit' ? 'warning' : 'danger'),
                TextColumn::make('what')->label('What')->description(fn (array $record) => $record['detail'])->wrap(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Kind')->options(['limit' => 'Stream limit', 'rule' => 'Tracearr rule']),
            ])
            ->emptyStateHeading('Nothing in the last 30 days')
            ->emptyStateDescription('Set "Max streams at once" on products, and Tracearr on the Wizarr server. Rules such as sharing between places are made in Tracearr.');
    }

    /** Both kinds, newest first, kept 5 minutes. */
    public static function rows(): array
    {
        return Cache::remember('paywarr:admin:sharing', 300, function () {
            $services = Service::whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
                ->with(['product.server', 'product.settings', 'properties', 'user'])->get();
            $rows = [];
            $name = fn ($service) => $service ? (trim(($service->user->first_name ?? '') . ' ' . ($service->user->last_name ?? '')) ?: (string) ($service->user->email ?? '?')) : '';
            foreach ($services as $service) {
                foreach (json_decode((string) (ExtensionHelper::getServiceProperties($service)[Wizarr::PROP_STREAMS_OVER] ?? '[]'), true) ?: [] as $i => $event) {
                    if ((int) $event['at'] > time() - 30 * 86400) {
                        $rows['l' . $service->id . '-' . $i] = ['at' => (int) $event['at'], 'kind' => 'limit', 'user' => $service->user_id, 'customer' => $name($service),
                            'product' => (string) ($service->product->name ?? '') . ' #' . $service->id,
                            'what' => $event['streams'] . ' streams at once (max ' . $event['max'] . ')',
                            'detail' => ($event['stopped'] ? 'Stopped: ' : 'Over: ') . (implode('; ', $event['which'] ?? []) ?: '—')];
                    }
                }
            }
            $first = $services->first();
            $wizarr = $first ? (new Wizarr([]))->forService($first) : new Wizarr([]);
            foreach ($wizarr->sharingRows($services) as $i => $row) {
                $service = $row['service'];
                $rows['r' . $i] = ['at' => (int) $row['at'], 'kind' => 'rule', 'user' => $service?->user_id, 'customer' => $service ? $name($service) : $row['username'] . ' (no subscription)',
                    'product' => $service ? (string) ($service->product->name ?? '') . ' #' . $service->id : '',
                    'what' => $row['rule'] . ' (' . $row['severity'] . ')', 'detail' => $row['username'] . ' on ' . $row['server']];
            }
            uasort($rows, fn ($a, $b) => $b['at'] <=> $a['at']);

            return $rows;
        });
    }
}
