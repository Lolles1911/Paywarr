<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory;

/**
 * Paywarr → Libraries per product: which media servers and libraries each
 * product gives, with the libraries editable here.
 */
class PaywarrLibraries extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-film-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 304;

    protected static ?string $navigationLabel = 'Libraries per product';

    protected static ?string $title = 'Libraries per product';

    protected static ?string $slug = 'paywarr-libraries';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.products.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Product::query()
                ->whereHas('server', fn (Builder $query) => $query->where('extension', 'Wizarr'))
                ->with(['server', 'settings', 'category']))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Product')->description(fn (Product $record) => $record->category->name ?? '')->searchable(),
                TextColumn::make('server.name')->label('Paymenter server'),
                TextColumn::make('media')->label('Media servers')->state(fn (Product $record) => $this->mediaServers($record))->listWithLineBreaks()->placeholder('None chosen'),
                TextColumn::make('libraries')->label('Libraries')->state(fn (Product $record) => $this->libraryNames($record))->listWithLineBreaks()->placeholder('The server\'s default'),
                TextColumn::make('details')->label('Product settings')->state(fn (Product $record) => $this->details($record))->listWithLineBreaks()->color('gray'),
            ])
            ->recordActions([
                Action::make('libraries')->label('Choose libraries')->icon('ri-film-line')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.products.update'))
                    ->modalDescription('Applies to new invitations. Customers who already have a user keep the libraries they have.')
                    ->fillForm(fn (Product $record) => ['library_ids' => array_map('strval', $this->ids($this->settings($record)['library_ids'] ?? null))])
                    ->schema(fn (Product $record) => [
                        CheckboxList::make('library_ids')->label('Libraries')->options($this->libraryOptions($record))->columns(2)
                            ->helperText('None chosen: each media server\'s default libraries.'),
                    ])
                    ->action(function (Product $record, array $data) {
                        $record->settings()->updateOrCreate(['key' => 'library_ids'], ['type' => 'string', 'value' => json_encode(array_values($data['library_ids'] ?? []))]);
                        Notification::make()->title('Libraries saved')->success()->send();
                    }),
                Action::make('edit')->label('Edit product')->icon('ri-edit-line')->color('gray')
                    ->url(fn (Product $record) => route('filament.admin.resources.products.edit', ['record' => $record])),
            ]);
    }

    private function settings(Product $record): array
    {
        return ExtensionHelper::settingsToArray($record->settings);
    }

    private function ids($value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $value = json_decode($value, true);
        }

        return array_values(array_filter(array_map('intval', is_array($value) ? $value : explode(',', (string) $value))));
    }

    private function backend(Product $record): ?array
    {
        return PaywarrInventory::backendFor((int) $record->server_id);
    }

    private function mediaServers(Product $record): array
    {
        $backend = $this->backend($record);
        if ($backend === null || !$backend['ok']) {
            return [$backend ? 'Not answering' : '?'];
        }
        if ($backend['kind'] === 'silo') {
            return array_column($backend['servers'], 'name');
        }
        $chosen = $this->ids($this->settings($record)['server_ids'] ?? null);

        return array_values(array_map(fn ($s) => $s['name'] . ' (' . ucfirst($s['type']) . ')', array_filter($backend['servers'], fn ($s) => in_array($s['id'], $chosen, true))));
    }

    private function libraryNames(Product $record): array
    {
        $backend = $this->backend($record);
        $chosen = $this->ids($this->settings($record)['library_ids'] ?? null);
        if (!$chosen) {
            return [];
        }
        $names = [];
        foreach ($backend['libraries'] ?? [] as $library) {
            if (in_array($library['id'], $chosen, true)) {
                $names[] = $library['name'] . ($library['server'] !== '' ? ' · ' . $library['server'] : '');
            }
        }

        return $names ?: ['Chosen libraries no longer exist'];
    }

    /** Libraries of the product's media servers, for the checkboxes. */
    private function libraryOptions(Product $record): array
    {
        $backend = $this->backend($record);
        $servers = $backend && $backend['kind'] === 'wizarr' ? $this->ids($this->settings($record)['server_ids'] ?? null) : null;
        $options = [];
        foreach ($backend['libraries'] ?? [] as $library) {
            if ($servers === null || !$servers || in_array($library['server_id'], $servers, true)) {
                $options[(string) $library['id']] = $library['name'] . ($library['server'] !== '' ? ' · ' . $library['server'] : '');
            }
        }

        return $options;
    }

    private function details(Product $record): array
    {
        $settings = $this->settings($record);
        $lines = [];
        if ((int) ($settings['trial_days'] ?? 0) > 0) {
            $lines[] = 'Trial: ' . (int) $settings['trial_days'] . ' days';
        }
        if (($settings['link_lifetime'] ?? '') !== '') {
            $lines[] = 'Link valid ' . $settings['link_lifetime'] . ' days';
        }
        if ((int) ($settings['access_days'] ?? 0) > 0) {
            $lines[] = 'Access ' . (int) $settings['access_days'] . ' days';
        }
        if (!empty($settings['require_discord']) && $settings['require_discord'] !== '0') {
            $lines[] = 'Discord members only';
        }
        if (!empty($settings['allow_downloads']) && $settings['allow_downloads'] !== '0') {
            $lines[] = 'Downloads allowed';
        }

        return $lines;
    }
}
