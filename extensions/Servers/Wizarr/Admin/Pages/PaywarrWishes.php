<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Most wanted: requested titles not on the server yet, by how many
 * customers want them (a request counts, "I want it too" adds one).
 */
class PaywarrWishes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-thumb-up-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 109;

    protected static ?string $navigationLabel = 'Most wanted';

    protected static ?string $title = 'Most wanted';

    protected static ?string $slug = 'paywarr-most-wanted';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'Titles customers requested (in Seerr) that are not on the server yet, most wanted first. A request counts as one; others add one with "I want it too" on Requests. A title leaves the list when Requests sees it is available.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => array_map(fn ($w) => $w + ['votes' => count($w['voters']), 'last' => max($w['voters'] ?: [$w['at']])], Wizarr::mostWanted(500)))
            ->paginated([25, 50, 100])
            ->columns([
                ImageColumn::make('poster')->label('')->height(60)->extraImgAttributes(['referrerpolicy' => 'no-referrer']),
                TextColumn::make('title')->label('Title')->description(fn (array $record) => trim(($record['type'] === 'tv' ? 'Show' : 'Movie') . ($record['year'] ? ' · ' . $record['year'] : ''))),
                TextColumn::make('votes')->label('Want it')->badge()->color(fn ($state) => $state >= 5 ? 'success' : ($state >= 2 ? 'info' : 'gray')),
                TextColumn::make('at')->label('First asked')->formatStateUsing(fn ($state) => date('M j, Y', (int) $state)),
                TextColumn::make('last')->label('Last vote')->formatStateUsing(fn ($state) => date('M j, Y', (int) $state)),
            ])
            ->recordActions([
                Action::make('tmdb')->label('TMDB')->icon('ri-external-link-line')->color('gray')
                    ->url(fn (array $record) => 'https://www.themoviedb.org/' . ($record['type'] === 'tv' ? 'tv' : 'movie') . '/' . (int) $record['tmdb'])->openUrlInNewTab(),
                Action::make('done')->label('Added')->icon('ri-check-line')->requiresConfirmation()
                    ->modalDescription('Takes it off the list (it is on the server now, or won\'t be).')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                    ->action(function (array $record) {
                        Wizarr::wishesAvailable([Wizarr::wishKey($record['type'], (int) $record['tmdb'])]);
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No wishes yet')
            ->emptyStateDescription('Requests made on Requests, and "I want it too", show here.');
    }
}
