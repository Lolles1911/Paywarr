<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Monthly reports: one per month, made on the 1st, kept two
 * years; "This month so far" is made when opened.
 */
class PaywarrReports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-file-chart-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 306;

    protected static ?string $navigationLabel = 'Monthly reports';

    protected static ?string $title = 'Monthly reports';

    protected static ?string $slug = 'paywarr-reports';

    /** "2026-11"; empty: this month so far. */
    #[Url]
    public string $month = '';

    private ?array $report = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getTitle(): string
    {
        $month = $this->month !== '' ? $this->month : date('Y-m');

        return 'Report for ' . date('F Y', strtotime($month . '-01')) . ($this->month === '' ? ' (so far)' : '');
    }

    protected function getHeaderActions(): array
    {
        $months = array_keys(Wizarr::storeGet('monthly_reports', []));
        $actions = [Action::make('now')->label('This month so far')->color($this->month === '' ? 'primary' : 'gray')->url(static::getUrl())];
        foreach (array_slice($months, 0, 6) as $month) {
            $actions[] = Action::make('m' . str_replace('-', '', $month))->label(date('M Y', strtotime($month . '-01')))
                ->color($this->month === $month ? 'primary' : 'gray')->url(static::getUrl(['month' => $month]));
        }
        $actions[] = \Paymenter\Extensions\Servers\Wizarr\Admin\Csv::action('paywarr-monthly-reports', function () {
            $rows = [];
            foreach (Wizarr::storeGet('monthly_reports', []) as $r) {
                $rows[] = [$r['month'], implode(' ', array_map(fn ($c, $a) => $a . ' ' . $c, array_keys($r['money']), $r['money'])), $r['subscriptions']['paying'], $r['subscriptions']['new'],
                    $r['subscriptions']['cancelled'], $r['subscriptions']['trials'], $r['subscriptions']['converted'], $r['subscriptions']['gifts'],
                    $r['care']['pauses'], $r['care']['winbacks'], $r['care']['referrals'], $r['care']['anniversaries'],
                    $r['watching']['plays'] ?? '', $r['watching']['hours'] ?? '', $r['watching']['viewers'] ?? '', $r['streams']['over'], $r['streams']['stopped']];
            }

            return [['Month', 'Paid', 'Paying', 'New', 'Cancelled', 'Trials', 'Trials converted', 'Gift cards', 'Pauses', 'Come-back codes', 'Friends', 'Anniversaries', 'Plays', 'Hours', 'Viewers', 'Over the stream limit', 'Streams stopped'], $rows];
        });
        $actions[] = Action::make('remake')->label('Make again')->icon('ri-refresh-line')->color('gray')
            ->visible(fn () => $this->month !== '')
            ->action(function () {
                try {
                    $wizarr = new Wizarr([]);
                    $wizarr->saveMonthlyReport($wizarr->monthlyReport($this->month));
                    Notification::make()->title('Made again')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Not made')->body($e->getMessage())->danger()->send();
                }
            });

        return $actions;
    }

    private function report(): array
    {
        if ($this->report !== null) {
            return $this->report;
        }
        if ($this->month !== '' && ($stored = Wizarr::storeGet('monthly_reports', [])[$this->month] ?? null)) {
            return $this->report = $stored;
        }

        return $this->report = (new Wizarr([]))->monthlyReport($this->month !== '' ? $this->month : date('Y-m'));
    }

    public function content(Schema $schema): Schema
    {
        try {
            $r = $this->report();
        } catch (Throwable $e) {
            report($e);

            return $schema->components([Section::make('The report could not be made')->description($e->getMessage())]);
        }
        $s = $r['subscriptions'];
        $money = $r['money'] ? implode(' · ', array_map(fn ($c, $a) => number_format($a, 2) . ' ' . $c, array_keys($r['money']), $r['money'])) : '0';
        $components = [
            Section::make()->columns(['default' => 2, 'lg' => 4])->schema([
                TextEntry::make('money')->label('Paid for subscriptions')->state($money),
                TextEntry::make('paying')->label('Paying customers' . ($r['partial'] ? ' now' : ' at the end'))->state((string) $s['paying']),
                TextEntry::make('new')->label('New subscriptions')->state((string) $s['new']),
                TextEntry::make('cancelled')->label('Cancelled')->state((string) $s['cancelled'])->color($s['cancelled'] > $s['new'] ? 'danger' : null),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Subscriptions')->columns(2)->schema([
                    TextEntry::make('trials')->label('Trials started')->state((string) $s['trials']),
                    TextEntry::make('converted')->label('Trials that became paying')->state((string) $s['converted']),
                    TextEntry::make('suspended')->label('Suspended now')->state((string) $s['suspended']),
                    TextEntry::make('gifts')->label('Gift cards sold')->state((string) $s['gifts']),
                    TextEntry::make('byProduct')->label('New per product')->state($r['new_by_product'] ? array_map(fn ($p, $n) => $p . ': ' . $n, array_keys($r['new_by_product']), $r['new_by_product']) : ['None'])->listWithLineBreaks()->columnSpanFull(),
                ]),
                Section::make('Keeping customers')->columns(2)->schema([
                    TextEntry::make('pauses')->label('Paused themselves')->state((string) $r['care']['pauses']),
                    TextEntry::make('winbacks')->label('Come-back codes sent')->state((string) $r['care']['winbacks']),
                    TextEntry::make('referrals')->label('Friends who joined')->state((string) $r['care']['referrals']),
                    TextEntry::make('anniversaries')->label('Anniversaries')->state((string) $r['care']['anniversaries']),
                ]),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Watching')->description($r['watching'] === null ? 'Needs Tracearr on the Wizarr server.' : null)->columns(3)->schema($r['watching'] === null ? [] : [
                    TextEntry::make('plays')->label('Plays')->state((string) $r['watching']['plays']),
                    TextEntry::make('hours')->label('Hours watched')->state((string) $r['watching']['hours']),
                    TextEntry::make('viewers')->label('Viewers')->state((string) $r['watching']['viewers']),
                    TextEntry::make('top')->label('Most watched')->state(array_map(fn ($t, $n) => $t . ' (' . $n . ')', array_keys($r['watching']['top']), $r['watching']['top']) ?: ['—'])->listWithLineBreaks()->columnSpanFull(),
                ]),
                Section::make('Streams and sharing')->columns(3)->schema([
                    TextEntry::make('over')->label('Over the stream limit')->state((string) $r['streams']['over']),
                    TextEntry::make('stopped')->label('Streams stopped')->state((string) $r['streams']['stopped']),
                    TextEntry::make('rules')->label('Tracearr rule breaks')->state($r['streams']['rule_breaks'] === null ? '—' : (string) $r['streams']['rule_breaks']),
                ]),
            ]),
        ];

        return $schema->components($components);
    }

    public function getSubheading(): ?string
    {
        $r = $this->report;

        return 'Made ' . date('M j, Y H:i', (int) ($r['made'] ?? time())) . '. A report for the month before is made on the 1st and kept for two years.';
    }
}
