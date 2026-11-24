<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

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
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → At risk: paying customers who haven't watched for a while,
 * soonest renewal first.
 */
class PaywarrAtRisk extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-alarm-warning-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 108;

    protected static ?string $navigationLabel = 'At risk';

    protected static ?string $title = 'At risk';

    protected static ?string $slug = 'paywarr-at-risk';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        $stats = class_exists(\Paymenter\Extensions\Others\PaywarrStats\PaywarrStats::class);

        return 'Paying customers who haven\'t watched for a while (from Tracearr, last 60 days; Silo users aren\'t counted), soonest renewal first. '
            . ($stats ? 'The automatic mail is "Remind customers who watched nothing" in Paywarr Stats; it sends "Watched nothing for a while" under Mail → Automatic mails, with new titles.'
                : 'With Paywarr Stats, "Remind customers who watched nothing" mails them by itself.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters) {
                $min = (int) ($filters['idle']['value'] ?? 14) ?: 14;
                $rows = array_filter((new Wizarr([]))->atRiskNow(), fn ($r) => $r['idle'] >= $min);
                uasort($rows, fn ($a, $b) => [$a['renews'] ?? PHP_INT_MAX, -$a['idle']] <=> [$b['renews'] ?? PHP_INT_MAX, -$b['idle']]);

                return array_map(fn ($r) => ['id' => (int) $r['service']->id, 'user' => (int) $r['service']->user_id, 'email' => (string) ($r['service']->user->email ?? ''),
                    'name' => trim(($r['service']->user->first_name ?? '') . ' ' . ($r['service']->user->last_name ?? '')), 'product' => (string) ($r['service']->product->name ?? ''),
                    'price' => number_format((float) $r['service']->price, 2) . ' ' . $r['service']->currency_code, 'last' => $r['last'], 'idle' => $r['idle'], 'renews' => $r['renews'],
                    'reminded' => (int) ($r['service']->user?->properties->firstWhere('key', 'paywarr_stats_reminded')?->value ?? 0)], $rows);
            })
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('email')->label('Customer')->description(fn (array $record) => $record['name'] ?: null)
                    ->url(fn (array $record) => PaywarrCustomers::getUrl(['customer' => $record['user']])),
                TextColumn::make('product')->label('Subscription')->description(fn (array $record) => $record['price']),
                TextColumn::make('idle')->label('Not watched for')->badge()->color(fn ($state) => $state >= 30 ? 'danger' : 'warning')
                    ->formatStateUsing(fn ($state, array $record) => $state . ' days')->description(fn (array $record) => $record['last'] ? 'Last ' . date('M j', $record['last']) : 'Nothing watched yet'),
                TextColumn::make('renews')->label('Renews')->placeholder('—')->formatStateUsing(fn ($state) => $state ? date('M j, Y', (int) $state) : null)
                    ->color(fn ($state) => $state && $state < time() + 7 * 86400 ? 'danger' : null),
                TextColumn::make('reminded')->label('Reminded')->placeholder('—')->formatStateUsing(fn ($state) => $state ? date('M j', (int) $state) : null),
            ])
            ->filters([
                SelectFilter::make('idle')->label('Not watched for at least')->options(['7' => '7 days', '14' => '14 days', '30' => '30 days', '60' => '60 days'])->default('14'),
            ])
            ->recordActions([
                Action::make('remind')->label('Send a reminder')->icon('ri-mail-send-line')->requiresConfirmation()
                    ->modalDescription('Mails "Watched nothing for a while" now (with new titles), and a Discord message if they connected Discord.')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                    ->action(function (array $record) {
                        $service = Service::with(['product.server', 'user'])->find($record['id']);
                        $used = $service ? (new Wizarr([]))->remindInactiveMail($service, $record['idle']) : [];
                        if ($used) {
                            $service->user->properties()->updateOrCreate(['key' => 'paywarr_stats_reminded'], ['name' => 'Paywarr Stats: reminded (watched nothing)', 'value' => (string) time()]);
                        }
                        Notification::make()->title($used ? 'Sent (' . implode(', ', $used) . ')' : 'Not sent')->{$used ? 'success' : 'danger'}()->send();
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('Nobody at risk')
            ->emptyStateDescription('Every paying customer watched something lately, or Tracearr isn\'t set up on the Wizarr server.');
    }
}
