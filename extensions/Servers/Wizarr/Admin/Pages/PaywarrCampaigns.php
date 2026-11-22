<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
 * Paywarr → Campaigns: a percentage off for a while (e.g. Black Friday),
 * with a code for new customers and a cheaper renewal for existing ones.
 */
class PaywarrCampaigns extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-price-tag-3-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Events';

    protected static ?int $navigationSort = 201;

    protected static ?string $navigationLabel = 'Campaigns';

    protected static ?string $title = 'Campaigns';

    protected static ?string $slug = 'paywarr-campaigns';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    private static function canChange(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.update');
    }

    public function getSubheading(): ?string
    {
        return 'A percentage off for a while. New customers use the code (a Paymenter coupon, shown in a banner with a countdown); existing customers get it off their next renewal in the period. Announced by mail, Discord and the announcements channel when it starts.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('New campaign')->icon('ri-add-line')->visible(fn () => self::canChange())
                ->fillForm(fn () => ['percent' => 25, 'starts' => now()->startOfHour()->addHour()->format('Y-m-d H:i'), 'ends' => now()->startOfHour()->addDays(4)->format('Y-m-d H:i'),
                    'periods' => 1, 'new' => true, 'existing' => true, 'announce' => true])
                ->schema([
                    TextInput::make('title')->label('Title')->required()->placeholder('Black Friday'),
                    Textarea::make('text')->label('Text in the announcement')->rows(2)->placeholder('Optional: a few words before the offer.'),
                    TextInput::make('percent')->label('Discount (%)')->numeric()->minValue(1)->maxValue(100)->required(),
                    DateTimePicker::make('starts')->label('Starts')->seconds(false)->required(),
                    DateTimePicker::make('ends')->label('Ends')->seconds(false)->required()->after('starts'),
                    Toggle::make('new')->label('A code for new customers')->live(),
                    TextInput::make('code')->label('Code')->placeholder('Made from the title, e.g. BLACKFRIDAY25')->visible(fn ($get) => (bool) $get('new')),
                    TextInput::make('periods')->label('Periods the code is off for')->numeric()->minValue(1)->helperText('1: only the first period.')->visible(fn ($get) => (bool) $get('new')),
                    Toggle::make('existing')->label('Existing customers: their next renewal in the period is cheaper'),
                    Select::make('products')->label('Products')->multiple()->placeholder('Every subscription')->options(fn () => (new Wizarr([]))->campaignProducts()),
                    Toggle::make('announce')->label('Announce it when it starts (mail, Discord, announcements channel)'),
                ])
                ->modalSubmitActionLabel('Create')
                ->action(function (array $data) {
                    try {
                        $c = (new Wizarr([]))->createCampaign(['starts' => strtotime((string) $data['starts']), 'ends' => strtotime((string) $data['ends'])] + $data);
                        Notification::make()->title('Created')->body($c['code'] !== '' ? 'Code: ' . $c['code'] : null)->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Not created')->body($e->getMessage())->danger()->send();
                    }
                    $this->resetTable();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $rows = [];
                foreach (array_reverse(Wizarr::campaigns()) as $c) {
                    $rows['c' . $c['id']] = $c + Wizarr::campaignResults($c) + ['status' => Wizarr::campaignStatus($c)];
                }

                return $rows;
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->label('Campaign')->description(fn (array $record) => $record['percent'] . '% off' . ($record['code'] !== '' ? ' · code ' . $record['code'] : '') . ($record['existing'] ? ' · renewals too' : '')),
                TextColumn::make('starts')->label('When')->formatStateUsing(fn ($state, array $record) => date('M j, H:i', (int) $state) . ' – ' . date('M j, H:i', (int) $record['ends'])),
                TextColumn::make('orders')->label('New orders')->description(fn (array $record) => $record['renewed'] . ' renewals, ' . number_format($record['given'], 2) . ' off'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'running' => 'Running', 'planned' => 'Planned', default => 'Ended' })
                    ->color(fn (string $state) => match ($state) { 'running' => 'success', 'planned' => 'info', default => 'gray' }),
            ])
            ->recordActions([
                Action::make('stop')->label('End now')->icon('ri-stop-circle-line')->color('warning')->requiresConfirmation()
                    ->visible(fn (array $record) => self::canChange() && $record['status'] === 'running')
                    ->action(function (array $record) {
                        (new Wizarr([]))->stopCampaign($record['id']);
                        $this->resetTable();
                    }),
                Action::make('delete')->label('Delete')->icon('ri-delete-bin-line')->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => self::canChange() && $record['status'] !== 'running')
                    ->action(function (array $record) {
                        (new Wizarr([]))->deleteCampaign($record['id']);
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('No campaigns yet')
            ->emptyStateDescription('Make one with "New campaign", e.g. Black Friday with 25% off for four days.');
    }
}
