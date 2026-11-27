<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Mail → Recipients: who is on the mail list, who unsubscribed from
 * what, and changing it for a customer (e.g. when they ask you to).
 */
class PaywarrMailRecipients extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-contacts-book-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Mail';

    protected static ?int $navigationSort = 352;

    protected static ?string $navigationLabel = 'Recipients';

    protected static ?string $title = 'Recipients';

    protected static ?string $slug = 'paywarr-mail-recipients';

    private const TRUE = ['1', 'true', 'on', 'yes'];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.users.view') || (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'News and offers, movie nights and challenges go only to those on the mail list. Reminders and stats go to everyone who didn\'t unsubscribe. Mails about access and payment always go.';
    }

    /** [kind => on] for a user, from their loaded properties. */
    public static function choices(User $user): array
    {
        $value = fn (string $key) => $user->properties->firstWhere('key', $key)?->value;
        $list = in_array(strtolower(trim((string) $value(Wizarr::PROP_MAIL_LIST))), self::TRUE, true);
        $off = Wizarr::mailOff($user, $value);

        return ['news' => $list, 'events' => $list && !in_array('events', $off, true), 'reminders' => !in_array('reminders', $off, true), 'stats' => !in_array('stats', $off, true),
            'shows' => !in_array('shows', $off, true)];
    }

    public function content(Schema $schema): Schema
    {
        $onList = \App\Models\Property::where('model_type', User::class)->where('key', Wizarr::PROP_MAIL_LIST)->whereIn('value', self::TRUE)->count();
        $off = array_fill_keys(['events', 'reminders', 'stats', 'shows'], 0);
        foreach (\App\Models\Property::where('model_type', User::class)->where('key', Wizarr::PROP_MAIL_OFF)->pluck('value') as $value) {
            foreach (array_filter(explode(',', (string) $value)) as $kind) {
                if (isset($off[$kind])) {
                    $off[$kind]++;
                }
            }
        }
        $unsubs = Wizarr::mailCountsSince('mail_unsubs', 30);

        return $schema->components([
            Section::make()->columns(['default' => 2, 'lg' => 6])->schema([
                TextEntry::make('list')->label('On the mail list')->state($onList . ' of ' . User::count()),
                TextEntry::make('events')->label('Movie nights off')->state((string) $off['events']),
                TextEntry::make('reminders')->label('Reminders off')->state((string) $off['reminders']),
                TextEntry::make('stats')->label('Stats and surveys off')->state((string) $off['stats']),
                TextEntry::make('shows')->label('Their shows off')->state((string) $off['shows']),
                TextEntry::make('unsubs')->label('Unsubscribed, 30 days')->state((string) array_sum($unsubs))
                    ->helperText($unsubs ? implode(', ', array_map(fn ($k, $n) => PaywarrMails::kindLabel($k) . ' ' . $n, array_keys($unsubs), $unsubs)) : null),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $labels = ['news' => 'News and offers', 'events' => 'Movie nights and challenges', 'reminders' => 'Reminders', 'stats' => 'Stats and surveys', 'shows' => 'News from their shows'];
        $short = ['news' => 'News', 'events' => 'Movie nights', 'reminders' => 'Reminders', 'stats' => 'Stats', 'shows' => 'Their shows'];

        return $table
            ->query(User::query()->with('properties'))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('email')->label('Customer')->searchable(['email', 'first_name', 'last_name'])
                    ->description(fn (User $record) => trim($record->first_name . ' ' . $record->last_name) ?: null)
                    ->url(fn (User $record) => PaywarrCustomers::getUrl(['customer' => $record->id])),
                IconColumn::make('list')->label('Mail list')->boolean()->state(fn (User $record) => self::choices($record)['news']),
                TextColumn::make('gets')->label('Gets')->badge()->color('success')
                    ->state(fn (User $record) => array_values(array_intersect_key($short, array_filter(self::choices($record)))))->wrap(),
                TextColumn::make('not')->label('Not')->badge()->color('gray')->placeholder('—')
                    ->state(fn (User $record) => array_values(array_intersect_key($short, array_filter(self::choices($record), fn ($on) => !$on))))->wrap(),
            ])
            ->filters([
                SelectFilter::make('list')->label('Mail list')->options(['on' => 'On the list', 'off' => 'Not on the list'])
                    ->query(function ($query, array $data) {
                        $on = fn ($q) => $q->where('key', Wizarr::PROP_MAIL_LIST)->whereIn('value', self::TRUE);

                        return match ($data['value'] ?? null) {
                            'on' => $query->whereHas('properties', $on),
                            'off' => $query->whereDoesntHave('properties', $on),
                            default => $query,
                        };
                    }),
                SelectFilter::make('off')->label('Unsubscribed from')->options(['events' => 'Movie nights and challenges', 'reminders' => 'Reminders', 'stats' => 'Stats and surveys', 'shows' => 'News from their shows'])
                    ->query(fn ($query, array $data) => ($data['value'] ?? null)
                        ? $query->whereHas('properties', fn ($q) => $q->where('key', Wizarr::PROP_MAIL_OFF)->where('value', 'like', '%' . $data['value'] . '%'))
                        : $query),
            ])
            ->recordActions([
                Action::make('change')->label('Change')->icon('ri-pencil-line')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.users.update'))
                    ->modalHeading(fn (User $record) => 'Mails to ' . $record->email)
                    ->modalDescription('Only when the customer asked you. Mails about access and payment always go.')
                    ->fillForm(fn (User $record) => ['kinds' => array_keys(array_filter(self::choices($record)))])
                    ->modalSubmitActionLabel('Save')
                    ->schema([CheckboxList::make('kinds')->hiddenLabel()->options($labels)->helperText('Movie nights and challenges go only to the mail list, so ticking them also gives news and offers.')])
                    ->action(function (User $record, array $data) {
                        $kinds = (array) ($data['kinds'] ?? []);
                        // News first: switching events on puts them (back) on the list.
                        foreach (['news', 'events', 'reminders', 'stats', 'shows'] as $kind) {
                            Wizarr::setMailKind($record, $kind, in_array($kind, $kinds, true));
                        }
                        Wizarr::logAdmin($record, null, 'Mail choices changed: ' . (implode(', ', $kinds) ?: 'none'), (string) (Auth::user()?->email ?? 'admin'));
                        Notification::make()->title('Saved')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No customers');
    }
}
