<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
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
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Mail → Automatic mails: every mail Paywarr sends by itself, with
 * a preview, a test to yourself, your own words, or switched off.
 */
class PaywarrMails extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-settings-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Mail';

    protected static ?int $navigationSort = 351;

    protected static ?string $navigationLabel = 'Automatic mails';

    protected static ?string $title = 'Automatic mails';

    protected static ?string $slug = 'paywarr-mails';

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
        return 'Every mail Paywarr sends by itself. Write your own words (blank: Paywarr\'s, in the customer\'s language), or switch one off. Whether a reminder is used at all is still set on the Wizarr server; customers can unsubscribe from all but the "Always" ones.';
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'news' => 'News and offers',
            'events' => 'Movie nights and challenges',
            'reminders' => 'Reminders',
            'stats' => 'Stats and surveys',
            'shows' => 'News from their shows',
            default => 'Always',
        };
    }

    public static function kindColour(string $kind): string
    {
        return match ($kind) {
            'news' => 'warning',
            'events' => 'info',
            'reminders' => 'primary',
            'stats' => 'success',
            'shows' => 'info',
            default => 'gray',
        };
    }

    public static function frame(string $html): HtmlString
    {
        return new HtmlString('<div style="width:100%"><iframe sandbox srcdoc="' . e($html) . '" style="display:block;width:100%;min-width:100%;height:65vh;border:0;border-radius:12px;background:#efedf5"></iframe></div>');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $choices = Wizarr::mailChoices();
                $sent = Wizarr::mailCountsSince('mail_counts_by_mail', 30);
                $rows = [];
                foreach (Wizarr::mailCatalog() as $id => $entry) {
                    $choice = $choices[$id] ?? [];
                    $rows[$id] = ['id' => $id, 'name' => $entry['name'], 'about' => $entry['about'], 'kind' => $entry['kind'], 'html' => !empty($entry['html']),
                        'status' => !empty($choice['off']) ? 'off' : (($choice['subject'] ?? '') !== '' || ($choice['text'] ?? '') !== '' ? 'own' : 'on'),
                        'sent' => $sent[$id] ?? 0];
                }

                return $rows;
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Mail')->description(fn (array $record) => $record['about'])->wrap(),
                TextColumn::make('kind')->label('Unsubscribe as')->badge()->formatStateUsing(fn (string $state) => self::kindLabel($state))->color(fn (string $state) => self::kindColour($state)),
                TextColumn::make('sent')->label('Sent, 30 days')->numeric(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'off' => 'Off', 'own' => 'Your words', default => 'On' })
                    ->color(fn (string $state) => match ($state) { 'off' => 'danger', 'own' => 'info', default => 'success' }),
            ])
            ->recordActions([
                Action::make('preview')->label('Preview')->icon('ri-eye-line')->color('gray')
                    ->modalHeading(fn (array $record) => $record['name'])
                    ->modalDescription(fn (array $record) => $record['html'] ? 'The last one sent.' : 'With example values.')
                    ->modalContent(function (array $record) {
                        $example = (new Wizarr([]))->mailExample($record['id']);

                        return $example ? self::frame($example['html']) : new HtmlString('<p>None sent yet: the preview shows after the first one.</p>');
                    })
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalWidth('3xl'),
                Action::make('test')->label('Send me a test')->icon('ri-send-plane-line')->color('gray')
                    ->visible(fn (array $record) => self::canChange() && !$record['html'])
                    ->action(function (array $record) {
                        $example = (new Wizarr([]))->mailExample($record['id']);
                        $to = (string) Auth::user()?->email;
                        try {
                            \Illuminate\Support\Facades\Mail::html($example['html'], fn ($mail) => $mail->to($to)->subject('[Test] ' . $example['subject']));
                            Notification::make()->title('Sent to ' . $to)->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Not sent')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('edit')->label('Edit')->icon('ri-pencil-line')->visible(fn () => self::canChange())
                    ->modalHeading(fn (array $record) => $record['name'])
                    ->fillForm(fn (array $record) => ['on' => empty(Wizarr::mailChoices()[$record['id']]['off'])] + (Wizarr::mailChoices()[$record['id']] ?? []) + ['subject' => '', 'text' => ''])
                    ->schema(fn (array $record) => self::fields($record))
                    ->modalSubmitActionLabel('Save')
                    ->action(function (array $record, array $data) {
                        Wizarr::setMailChoice($record['id'], ['off' => empty($data['on'])] + $data);
                        Notification::make()->title('Saved')->success()->send();
                        $this->resetTable();
                    }),
                Action::make('reset')->label('Paywarr\'s words')->icon('ri-refresh-line')->color('gray')->requiresConfirmation()
                    ->modalDescription('Back to Paywarr\'s own subject and text, and on.')
                    ->visible(fn (array $record) => self::canChange() && $record['status'] !== 'on')
                    ->action(function (array $record) {
                        Wizarr::setMailChoice($record['id'], []);
                        $this->resetTable();
                    }),
            ]);
    }

    private static function fields(array $record): array
    {
        $entry = Wizarr::mailCatalog()[$record['id']];
        [$title, $text] = Wizarr::mailDefaultWords($record['id']);
        $vars = array_filter(array_keys($entry['vars'] ?? []), fn ($k) => !str_starts_with($k, '_'));
        $help = $vars ? 'You can use ' . implode(', ', array_map(fn ($k) => '{' . $k . '}', $vars)) . '. Your words are used in every language.' : 'Your words are used in every language.';

        return array_values(array_filter([
            Toggle::make('on')->label('Send this mail')->helperText($entry['kind'] === 'essential' ? 'This is about the customer\'s access or payment; switch it off only if you tell them another way.'
                : ($record['id'] === 'welcome' ? 'Off: Paymenter\'s own mail with the link goes instead.' : null)),
            TextInput::make('subject')->label('Subject')->placeholder($title !== '' ? $title : 'Paywarr\'s subject')->helperText($record['html'] ? 'Blank: Paywarr\'s. ' . 'Only the subject of this mail can change.' : $help),
            $record['html'] ? null : Textarea::make('text')->label('Text')->rows(7)->placeholder($text)
                ->helperText('Blank: Paywarr\'s text (shown faintly). An empty line starts a new paragraph. The button stays.')
                ->hintAction(Action::make('copy')->label('Start from Paywarr\'s text')->action(fn ($set) => $set('text', $text))),
        ]));
    }
}
