<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Product;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 * Paywarr → Message customers: a message to everyone active, or those on a
 * server, product or status, by mail, Discord and the bell; and the
 * messages sent before.
 */
class PaywarrMessages extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-megaphone-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Events';

    protected static ?int $navigationSort = 204;

    protected static ?string $navigationLabel = 'Message customers';

    protected static ?string $title = 'Message customers';

    protected static ?string $slug = 'paywarr-messages';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    /** The form, also used for one customer from their card. */
    public static function messageFields(bool $chooseRecipients = true): array
    {
        $fields = [];
        if ($chooseRecipients) {
            $fields[] = Select::make('to')->label('To')->required()->default('all')->options(fn () => self::recipientOptions())->searchable();
        }

        return array_merge($fields, [
            TextInput::make('subject')->label('Subject')->required()->maxLength(120),
            Textarea::make('text')->label('Message')->required()->rows(6)->maxLength(3000)->helperText('Plain text; an empty line starts a new paragraph.'),
            TextInput::make('button_label')->label('Button text (optional)')->maxLength(40),
            TextInput::make('button_url')->label('Button link (optional)')->url()->maxLength(500),
            CheckboxList::make('channels')->label('Send as')->required()->default(['mail', 'discord', 'app'])->options([
                'mail' => 'Mail', 'discord' => 'Private Discord message (customers who connected Discord)', 'app' => 'Under the bell in Paymenter', 'channel' => 'A post in the Discord announcements channel',
            ]),
        ]);
    }

    public static function recipientOptions(): array
    {
        $options = ['Everyone' => ['all' => 'All active customers'], 'By status' => [
            'status:active' => 'Paying (active, not on trial)', 'status:trial' => 'On a trial', 'status:suspended' => 'Suspended (unpaid or paused)', 'status:cancelled' => 'Cancelled, with nothing running',
        ]];
        try {
            foreach (Server::where('extension', 'Wizarr')->get() as $server) {
                $options['By server']['server:' . $server->id] = $server->name;
            }
            foreach (Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->orderBy('name')->get() as $product) {
                $options['By product']['product:' . $product->id] = $product->name;
            }
        } catch (Throwable $e) {
            // Without them then.
        }
        $options['Only the channel'] = ['channel' => 'Nobody directly: only the Discord channel post'];

        return $options;
    }

    /** Sends; returns the notification text. */
    public static function send(string $to, array $data): string
    {
        $button = trim((string) ($data['button_label'] ?? '')) !== '' && trim((string) ($data['button_url'] ?? '')) !== '' ? [trim($data['button_label']), trim($data['button_url'])] : null;
        $result = (new Wizarr([]))->broadcast($to, trim((string) $data['subject']), trim((string) $data['text']), $button, (array) ($data['channels'] ?? []), (string) (Auth::user()?->email ?? ''));

        return $result['recipients'] . ' customer(s): ' . $result['mail'] . ' mail(s), ' . $result['discord'] . ' Discord message(s), ' . $result['app'] . ' under the bell'
            . ($result['channel'] ? ', and posted in the channel' : '') . '.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('New message')->icon('ri-send-plane-line')
                ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                ->modalWidth('2xl')
                ->schema(self::messageFields())
                ->modalSubmitActionLabel('Send')
                ->action(function (array $data) {
                    try {
                        Notification::make()->title('Sent')->body(self::send((string) $data['to'], $data))->success()->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->title('Not sent')->body($e->getMessage())->danger()->send();
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
            ->records(fn () => array_combine(array_map(fn ($i) => 'm' . $i, array_keys($log = Wizarr::storeGet('broadcasts', []))), $log) ?: [])
            ->paginated(false)
            ->columns([
                TextColumn::make('at')->label('Sent')->formatStateUsing(fn ($state) => date('M j, Y H:i', (int) $state))->description(fn (array $record) => $record['by'] ?? ''),
                TextColumn::make('subject')->label('Subject')->wrap(),
                TextColumn::make('to')->label('To')->formatStateUsing(fn ($state) => self::recipientLabel((string) $state)),
                TextColumn::make('recipients')->label('Reached')
                    ->state(fn (array $record) => $record['recipients'] . ' customer(s)')
                    ->description(fn (array $record) => $record['mail'] . ' mail · ' . $record['discord'] . ' Discord · ' . $record['app'] . ' bell' . (!empty($record['channel']) ? ' · channel' : '')),
            ])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('"New message" sends one to everyone active, or to those on a server, product or status.');
    }

    private static function recipientLabel(string $to): string
    {
        foreach (self::recipientOptions() as $group) {
            if (isset($group[$to])) {
                return $group[$to];
            }
        }

        return str_starts_with($to, 'user:') ? 'One customer' : $to;
    }
}
