<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Property;
use App\Models\Service;
use App\Models\User;
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
 * Paywarr → Invite a friend: who brought the most new customers with their
 * code, how many of those still pay, and what the friends have paid.
 */
class PaywarrReferrals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-trophy-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Events';

    protected static ?int $navigationSort = 205;

    protected static ?string $navigationLabel = 'Invite a friend';

    protected static ?string $title = 'Invite a friend: who brings the most';

    protected static ?string $slug = 'paywarr-referrals';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    protected function getHeaderActions(): array
    {
        return [\Paymenter\Extensions\Servers\Wizarr\Admin\Csv::action('paywarr-referrals', fn () => [['Name', 'Email', 'Code', 'Friends', 'Still paying', 'Paid by friends', 'Free months'],
            array_map(fn ($r) => [$r['name'], $r['email'], $r['code'], $r['friends'], $r['paying'], implode(' ', array_map(fn ($c, $a) => $a . ' ' . $c, array_keys($r['paid']), $r['paid'])), $r['months']], self::rows())])];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** One row per customer with a code: friends, still paying, paid by friends, months earned. */
    public static function rows(): array
    {
        $rows = [];
        $months = max(1, (int) ((new Wizarr([]))->anyServer('referral_reward_months')->config('referral_reward_months') ?: 1));
        foreach (Property::where('model_type', User::class)->where('key', Wizarr::PROP_REFERRAL_CODE)->get() as $property) {
            $user = User::find($property->model_id);
            if (!$user) {
                continue;
            }
            $friends = json_decode((string) ($user->properties()->where('key', Wizarr::PROP_REFERRALS)->value('value') ?? '[]'), true) ?: [];
            $services = $friends ? Service::whereIn('id', $friends)->get() : collect();
            $paid = [];
            try {
                foreach (\App\Models\InvoiceItem::with('invoice')->where('reference_type', Service::class)->whereIn('reference_id', $friends ?: [0])->get() as $item) {
                    if (($item->invoice->status ?? '') === 'paid') {
                        $currency = (string) ($item->invoice->currency_code ?? '');
                        $paid[$currency] = ($paid[$currency] ?? 0) + (float) $item->price * max(1, (int) $item->quantity);
                    }
                }
            } catch (Throwable $e) {
                // Without money then.
            }
            $rows[$user->id] = [
                'id' => $user->id, 'name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->email, 'email' => $user->email,
                'code' => (string) $property->value, 'friends' => count($friends), 'paying' => $services->where('status', 'active')->count(),
                'paid' => $paid, 'months' => count($friends) * $months,
            ];
        }
        uasort($rows, fn ($a, $b) => [$b['friends'], $b['paying']] <=> [$a['friends'], $a['paying']]);

        return $rows;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => self::rows())
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('name')->label('Customer')->description(fn (array $record) => $record['email'])
                    ->url(fn (array $record) => PaywarrCustomers::getUrl(['customer' => $record['id']])),
                TextColumn::make('code')->label('Code')->fontFamily('mono'),
                TextColumn::make('friends')->label('Friends who joined')->numeric(),
                TextColumn::make('paying')->label('Still paying')->numeric(),
                TextColumn::make('paid')->label('Paid by their friends')->state(fn (array $record) => $record['paid'] ? implode(' · ', array_map(fn ($c, $a) => number_format($a, 2) . ' ' . $c, array_keys($record['paid']), $record['paid'])) : '—'),
                TextColumn::make('months')->label('Free months given')->numeric(),
            ])
            ->emptyStateHeading('No codes yet')
            ->emptyStateDescription('Customers get a code under "Invite a friend" once "Invite a friend" is set on the Wizarr server.');
    }
}
