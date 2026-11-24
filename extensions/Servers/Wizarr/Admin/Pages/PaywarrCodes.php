<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Coupon;
use App\Models\InvoiceItem;
use App\Models\Property;
use App\Models\Service;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Discount codes: every code Paywarr made (gift cards, come-back,
 * anniversaries, invite a friend, campaigns), how often it was used, and what
 * the subscriptions that used it have paid since.
 */
class PaywarrCodes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-coupon-3-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Events';

    protected static ?int $navigationSort = 207;

    protected static ?string $navigationLabel = 'Discount codes';

    protected static ?string $title = 'Discount codes';

    protected static ?string $slug = 'paywarr-codes';

    public const KINDS = ['gift' => 'Gift card', 'back' => 'Come back', 'thanks' => 'Anniversary', 'friend' => 'Invite a friend', 'campaign' => 'Campaign', 'other' => 'Other'];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'Codes Paywarr made, and your own from Paymenter (Other). "Paid since" is everything the subscriptions that used the code have paid, the first invoice and every renewal.';
    }

    /** One row per code, newest first (the last 1000). */
    public static function rows(): array
    {
        $friend = array_flip(array_map('intval', Property::where('model_type', User::class)->where('key', Wizarr::PROP_REFERRAL_COUPON)->pluck('value')->all()));
        $campaigns = array_flip(array_map(fn ($c) => strtoupper((string) $c['code']), array_filter(Wizarr::campaigns(), fn ($c) => ($c['code'] ?? '') !== '')));
        $coupons = Coupon::orderByDesc('id')->limit(1000)->get();
        $services = Service::whereIn('coupon_id', $coupons->pluck('id'))->get(['id', 'coupon_id', 'currency_code']);
        $paid = [];
        $byService = $services->pluck('coupon_id', 'id')->all();
        foreach (InvoiceItem::where('reference_type', Service::class)->whereIn('reference_id', array_keys($byService))->whereHas('invoice', fn ($q) => $q->where('status', 'paid'))
            ->get(['reference_id', 'price', 'quantity']) as $item) {
            $coupon = $byService[$item->reference_id] ?? null;
            $paid[$coupon] = ($paid[$coupon] ?? 0) + (float) $item->price * max(1, (int) $item->quantity);
        }
        $uses = $services->countBy('coupon_id')->all();
        $currency = (string) ($services->first()->currency_code ?? config('settings.default_currency', ''));
        $rows = [];
        foreach ($coupons as $c) {
            $expired = $c->expires_at && strtotime((string) $c->expires_at) < time();
            $usedUp = $c->max_uses && ($uses[$c->id] ?? 0) >= (int) $c->max_uses;
            $rows['c' . $c->id] = ['id' => (int) $c->id, 'code' => (string) $c->code, 'kind' => Wizarr::couponKind((string) $c->code, (int) $c->id, $friend, $campaigns),
                'value' => $c->type === 'percentage' ? rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.') . '%' : number_format((float) $c->value, 2) . ' ' . $currency,
                'uses' => (int) ($uses[$c->id] ?? 0), 'max' => $c->max_uses ? (int) $c->max_uses : null, 'paid' => round($paid[$c->id] ?? 0, 2), 'currency' => $currency,
                'expires' => $c->expires_at ? strtotime((string) $c->expires_at) : null, 'state' => $usedUp ? 'used' : ($expired ? 'expired' : 'open'), 'created' => strtotime((string) $c->created_at) ?: 0];
        }

        return $rows;
    }

    public function content(Schema $schema): Schema
    {
        $rows = self::rows();
        $tiles = [];
        foreach (self::KINDS as $kind => $label) {
            $of = array_filter($rows, fn ($r) => $r['kind'] === $kind);
            if ($of) {
                $first = reset($of);
                $tiles[] = TextEntry::make('k' . $kind)->label($label)->state(array_sum(array_column($of, 'uses')) . ' used of ' . count($of))
                    ->helperText(number_format(array_sum(array_column($of, 'paid')), 2) . ' ' . $first['currency'] . ' paid since');
            }
        }

        return $schema->components([
            Section::make()->columns(['default' => 2, 'lg' => max(1, min(6, count($tiles)))])->schema($tiles ?: [TextEntry::make('none')->hiddenLabel()->state('No codes yet.')]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters) {
                $kind = $filters['kind']['value'] ?? null;
                $rows = self::rows();

                return $kind ? array_filter($rows, fn ($r) => $r['kind'] === $kind) : $rows;
            })
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('code')->label('Code')->copyable()->fontFamily('mono')->description(fn (array $record) => $record['value'] . ' off'),
                TextColumn::make('kind')->label('From')->badge()->formatStateUsing(fn (string $state) => self::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'gift' => 'success', 'back' => 'warning', 'thanks' => 'info', 'friend' => 'primary', 'campaign' => 'danger', default => 'gray' }),
                TextColumn::make('uses')->label('Used')->formatStateUsing(fn ($state, array $record) => $state . ($record['max'] ? ' of ' . $record['max'] : '')),
                TextColumn::make('paid')->label('Paid since')->formatStateUsing(fn ($state, array $record) => number_format((float) $state, 2) . ' ' . $record['currency']),
                TextColumn::make('state')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'used' => 'Used up', 'expired' => 'Expired', default => 'Open' })
                    ->color(fn (string $state) => match ($state) { 'used' => 'success', 'expired' => 'gray', default => 'info' })
                    ->description(fn (array $record) => $record['expires'] ? ($record['state'] === 'expired' ? 'Ended ' : 'Until ') . date('M j, Y', $record['expires']) : null),
            ])
            ->filters([SelectFilter::make('kind')->label('From')->options(self::KINDS)])
            ->emptyStateHeading('No codes yet');
    }
}
