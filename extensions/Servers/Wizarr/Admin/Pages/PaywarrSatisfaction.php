<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Satisfaction: the "How likely are you to recommend us?" answers
 * (NPS) with comments, and why customers cancelled (one-click answers and
 * Paymenter's own cancellation reasons).
 */
class PaywarrSatisfaction extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-emotion-happy-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Satisfaction';

    protected static ?string $title = 'Satisfaction';

    protected static ?string $slug = 'paywarr-satisfaction';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'Customers are asked after a month as paying customers and every half year after ("Satisfaction survey" on the Wizarr server); those who cancel are asked why. NPS = % who give 9-10 minus % who give 0-6.';
    }

    protected function getHeaderActions(): array
    {
        return [\Paymenter\Extensions\Servers\Wizarr\Admin\Csv::action('paywarr-satisfaction', function () {
            $rows = [];
            foreach (Wizarr::storeGet('nps', []) as $a) {
                $user = User::find((int) $a['user']);
                $rows[] = [date('Y-m-d', (int) $a['at']), $user?->email ?? '#' . $a['user'], $a['score'], $a['comment']];
            }

            return [['Date', 'Customer', 'Score', 'Comment'], $rows];
        })];
    }

    public function content(Schema $schema): Schema
    {
        $answers = Wizarr::storeGet('nps', []);
        $year = Wizarr::npsScore($answers, time() - 365 * 86400);
        $half = Wizarr::npsScore($answers, time() - 182 * 86400);
        $why = Wizarr::storeGet('cancel_reasons', []);
        $counts = array_count_values(array_column($why, 'reason'));
        arsort($counts);
        $paymenter = [];
        try {
            foreach (\App\Models\ServiceCancellation::with('service.product.server')->where('created_at', '>=', now()->subDays(182))->latest()->limit(30)->get() as $c) {
                if (($c->service?->product?->server?->extension ?? null) === 'Wizarr' && trim((string) $c->reason) !== '') {
                    $paymenter[] = date('M j', strtotime((string) $c->created_at)) . ' · ' . mb_substr((string) $c->reason, 0, 160);
                }
            }
        } catch (Throwable $e) {
            // Not available.
        }

        return $schema->components([
            Section::make()->columns(['default' => 2, 'lg' => 4])->schema([
                TextEntry::make('nps')->label('NPS, last 6 months')->state($half['score'] === null ? '—' : (string) $half['score'])
                    ->color($half['score'] === null ? null : ($half['score'] >= 30 ? 'success' : ($half['score'] >= 0 ? 'warning' : 'danger'))),
                TextEntry::make('npsYear')->label('NPS, last year')->state($year['score'] === null ? '—' : (string) $year['score']),
                TextEntry::make('split')->label('9-10 / 7-8 / 0-6')->state($half['promoters'] . ' / ' . $half['passives'] . ' / ' . $half['detractors']),
                TextEntry::make('count')->label('Answers, last 6 months')->state((string) $half['count']),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Why they cancelled')->description('One-click answers in the mail after cancelling')->schema([
                    TextEntry::make('why')->hiddenLabel()->state($counts ? array_map(fn ($r, $n) => Wizarr::cancelReasonLabel($r) . ': ' . $n, array_keys($counts), $counts) : ['No answers yet'])->listWithLineBreaks(),
                ]),
                Section::make('In their own words')->description('The reason written when cancelling in Paymenter')->schema([
                    TextEntry::make('own')->hiddenLabel()->state($paymenter ?: ['None in the last 6 months'])->listWithLineBreaks(),
                ]),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Answers')
            ->records(function () {
                $rows = Wizarr::storeGet('nps', []);
                uasort($rows, fn ($a, $b) => $b['at'] <=> $a['at']);

                return $rows;
            })
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('at')->label('When')->formatStateUsing(fn ($state) => date('M j, Y', (int) $state)),
                TextColumn::make('user')->label('Customer')->formatStateUsing(function ($state) {
                    $user = User::find((int) $state);

                    return $user ? (trim($user->first_name . ' ' . $user->last_name) ?: $user->email) : '#' . $state;
                })->url(fn (array $record) => PaywarrCustomers::getUrl(['customer' => $record['user']])),
                TextColumn::make('score')->label('Score')->badge()->color(fn ($state) => $state >= 9 ? 'success' : ($state >= 7 ? 'warning' : 'danger')),
                TextColumn::make('comment')->label('Comment')->wrap()->placeholder('—'),
            ])
            ->emptyStateHeading('No answers yet');
    }
}
