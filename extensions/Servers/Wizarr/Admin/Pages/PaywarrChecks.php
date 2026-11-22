<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Product;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Checks: what might be wrong in the setup, worst first, each with
 * where to fix it (Wizarr::adminHealth()).
 */
class PaywarrChecks extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shield-check-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 303;

    protected static ?string $navigationLabel = 'Checks';

    protected static ?string $title = 'Checks';

    protected static ?string $slug = 'paywarr-checks';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.servers.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Check again')->icon('ri-refresh-line')->action(function () {
                PaywarrInventory::forget();
                $this->resetTable();
            }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => self::rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('level')->label('')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'error' ? 'Fix' : 'Look')
                    ->color(fn (string $state) => $state === 'error' ? 'danger' : 'warning'),
                TextColumn::make('what')->label('Check')->weight('bold')->description(fn (array $record) => $record['detail'])->wrap(),
            ])
            ->recordActions([
                Action::make('fix')->label('Go there')->icon('ri-arrow-right-line')
                    ->url(fn (array $record) => self::link($record['where']))
                    ->visible(fn (array $record) => self::link($record['where']) !== null),
            ])
            ->emptyStateIcon('ri-shield-check-line')
            ->emptyStateHeading('Everything looks right')
            ->emptyStateDescription('Wizarr and Silo answer, and every product and server is set up.');
    }

    /** The checks, keyed for the table. */
    public static function rows(): array
    {
        $rows = (new Wizarr([]))->adminHealth(
            PaywarrInventory::get(),
            Server::where('extension', 'Wizarr')->with('settings')->get(),
            Product::whereHas('server', fn ($query) => $query->where('extension', 'Wizarr'))->with(['settings', 'configOptions.children'])->get(),
            PaywarrInventory::services(),
        );
        $keyed = [];
        foreach ($rows as $i => $row) {
            $keyed['check-' . $i] = $row;
        }

        return $keyed;
    }

    private static function link(string $where): ?string
    {
        [$kind, $id] = array_pad(explode(':', $where, 2), 2, '');

        return match ($kind) {
            'server' => route('filament.admin.resources.servers.edit', ['record' => $id]),
            'product' => route('filament.admin.resources.products.edit', ['record' => $id]),
            'option' => route('filament.admin.resources.config-options.edit', ['record' => $id]),
            'page' => url('admin/' . $id),
            default => null,
        };
    }
}
