<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Throwable;

/**
 * Paywarr → Backup: download Paywarr's own data as a file, and put such a
 * file back.
 */
class PaywarrBackup extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-archive-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 309;

    protected static ?string $navigationLabel = 'Backup';

    protected static ?string $title = 'Backup';

    protected static ?string $slug = 'paywarr-backup';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.settings.update');
    }

    public function getSubheading(): ?string
    {
        return 'Paywarr\'s own data in one file: settings, mail texts and design, campaigns, movie nights, challenges, the waiting list, price changes, the help page, logs and the rest. API keys, secrets and tokens are left out; type them again after restoring on another server. Customers, subscriptions and invoices are in Paymenter\'s database: back that up too.';
    }

    public function content(Schema $schema): Schema
    {
        $data = Wizarr::backupData()['data'];
        $size = strlen((string) json_encode($data));

        return $schema->components([Section::make('What is in it')->schema([
            Text::make(count($data) . ' parts, about ' . max(1, (int) round($size / 1024)) . ' KB: ' . implode(', ', array_keys($data)) . '.'),
        ])]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')->label('Download a backup')->icon('ri-download-2-line')
                ->action(function () {
                    $json = json_encode(Wizarr::backupData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    return response()->streamDownload(fn () => print ($json), 'paywarr-backup-' . date('Y-m-d') . '.json', ['Content-Type' => 'application/json']);
                }),
            Action::make('restore')->label('Restore')->icon('ri-upload-2-line')->color('danger')
                ->modalDescription('Everything in the file replaces what Paywarr has now (the keys, secrets and tokens you have now are kept). Download a backup first if you are unsure.')
                ->schema([FileUpload::make('file')->label('Backup file (.json)')->acceptedFileTypes(['application/json', 'text/plain'])->storeFiles(false)->required()])
                ->modalSubmitActionLabel('Restore')
                ->action(function (array $data) {
                    try {
                        $file = $data['file'];
                        $backup = json_decode((string) (is_object($file) && method_exists($file, 'get') ? $file->get() : ''), true);
                        $count = Wizarr::restoreBackup(is_array($backup) ? $backup : []);
                        Notification::make()->title('Restored')->body($count . ' parts from ' . date('M j, Y', (int) ($backup['made'] ?? time())) . '.')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Not restored')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
