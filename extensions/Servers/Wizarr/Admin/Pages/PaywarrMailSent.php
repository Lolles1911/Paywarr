<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Mail → Sent: Paywarr's mails lately, per kind, and the last 300.
 * Every mail, Paymenter's too, is also under Paymenter's own Email logs.
 */
class PaywarrMailSent extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-check-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Mail';

    protected static ?int $navigationSort = 354;

    protected static ?string $navigationLabel = 'Sent';

    protected static ?string $title = 'Sent';

    protected static ?string $slug = 'paywarr-mail-sent';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'Paywarr\'s mails: how many per kind, and the last 300. Every mail (Paymenter\'s too, with errors) is also in Paymenter\'s Email logs.';
    }

    public function content(Schema $schema): Schema
    {
        $week = Wizarr::mailCountsSince('mail_counts', 7);
        $month = Wizarr::mailCountsSince('mail_counts', 30);
        $tiles = [
            TextEntry::make('week')->label('Last 7 days')->state((string) array_sum($week)),
            TextEntry::make('month')->label('Last 30 days')->state((string) array_sum($month)),
        ];
        $kinds = [];
        foreach (['essential', 'reminders', 'news', 'events', 'stats', 'shows'] as $kind) {
            $kinds[] = TextEntry::make('k' . $kind)->label(PaywarrMails::kindLabel($kind))->state((string) ($month[$kind] ?? 0));
        }

        return $schema->components([
            \Filament\Schemas\Components\Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Sent')->columns(2)->schema($tiles)->columnSpan(1),
                Section::make('Per kind, last 30 days')->columns(['default' => 2, 'lg' => 6])->schema($kinds)->columnSpan(['default' => 1, 'lg' => 2]),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $names = array_map(fn ($e) => $e['name'], Wizarr::mailCatalog());

        return $table
            ->records(fn () => Wizarr::storeGet('mail_log', []))
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('at')->label('When')->formatStateUsing(fn ($state) => date('M j, H:i', (int) $state)),
                TextColumn::make('to')->label('To')->url(fn (array $record) => !empty($record['user']) ? PaywarrCustomers::getUrl(['customer' => $record['user']]) : null),
                TextColumn::make('subject')->label('Subject')->wrap()->description(fn (array $record) => $names[$record['mail'] ?? ''] ?? (($record['mail'] ?? '') === '' ? 'Message or other' : null)),
                TextColumn::make('kind')->label('Kind')->badge()->formatStateUsing(fn (string $state) => PaywarrMails::kindLabel($state))->color(fn (string $state) => PaywarrMails::kindColour($state)),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon('ri-eye-line')->color('gray')
                    ->visible(fn (array $record) => !empty($record['log']))
                    ->modalHeading(fn (array $record) => $record['subject'])
                    ->modalContent(function (array $record) {
                        $row = class_exists(\App\Models\EmailLog::class) ? \App\Models\EmailLog::find((int) $record['log']) : null;

                        return $row ? PaywarrMails::frame((string) $row->body) : new HtmlString('<p>Not in the email log any more.</p>');
                    })
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalWidth('3xl'),
            ])
            ->emptyStateHeading('Nothing sent yet')
            ->emptyStateDescription('Paywarr\'s mails show here from this version on.');
    }
}
