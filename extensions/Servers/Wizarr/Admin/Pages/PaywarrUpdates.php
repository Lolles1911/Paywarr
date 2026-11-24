<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * Paywarr → Updates: the installed versions, what's newer on GitHub, what
 * it brings, and the commands to update.
 */
class PaywarrUpdates extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-download-cloud-2-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Operations';

    protected static ?int $navigationSort = 308;

    protected static ?string $navigationLabel = 'Updates';

    protected static ?string $title = 'Updates';

    protected static ?string $slug = 'paywarr-updates';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.settings.update') || (bool) Auth::user()?->hasPermission('admin.servers.update');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Wizarr::updatesWaiting();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public function getSubheading(): ?string
    {
        return 'Checked on GitHub every 6 hours. Updating is done on the server, with the commands below.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('check')->label('Check now')->icon('ri-refresh-line')->color('gray')->action(fn () => Wizarr::updateStatus(true))];
    }

    public function content(Schema $schema): Schema
    {
        $sections = [];
        $commands = [];
        foreach (Wizarr::updateStatus() as $row) {
            $newer = $row['latest'] && version_compare($row['latest'], $row['installed'], '>');
            $changes = '';
            foreach ($row['newer'] as $version => $markdown) {
                $changes .= '<h3 style="font-weight:700;margin:1rem 0 .3rem">' . e($version) . '</h3><div class="paywarr-md" style="opacity:.9">' . Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]) . '</div>';
            }
            $sections[] = Section::make($row['name'])->columns(['default' => 1, 'lg' => 3])->schema([
                TextEntry::make('i' . $row['name'])->label('Installed')->state($row['installed']),
                TextEntry::make('l' . $row['name'])->label('Newest')->state($row['latest'] ?? '—')->badge()->color($newer ? 'success' : 'gray')
                    ->helperText($row['error'] !== '' ? $row['error'] : ($newer ? 'A newer version is ready.' : 'Up to date.')),
                TextEntry::make('r' . $row['name'])->label('On GitHub')->state('github.com/' . $row['repo'])->url('https://github.com/' . $row['repo'], true),
                Text::make(new HtmlString($changes !== '' ? '<style>.paywarr-md ul{list-style:disc;padding-left:1.3rem}.paywarr-md li{margin:.2rem 0}.paywarr-md code{font-size:.85em}</style>' . $changes : ''))->columnSpanFull(),
            ]);
            if ($newer) {
                $commands[] = 'cd ' . $row['folder'] . ' && git pull && bash install.sh /opt/paymenter';
            }
        }
        if ($commands) {
            $commands[] = 'cd /opt/paymenter && sudo -u www-data XDG_CONFIG_HOME=/tmp php artisan optimize:clear';
            $commands[] = 'sudo systemctl restart php8.3-fpm paymenter';
            $sections[] = Section::make('Update on the server')->description('Adjust the folders if yours differ.')->schema([
                Text::make(new HtmlString('<pre style="white-space:pre-wrap;font-size:.85rem;padding:.8rem 1rem;border-radius:.6rem;background:rgba(127,127,127,.15)">' . e(implode("\n", $commands)) . '</pre>')),
            ]);
        }

        return $schema->components($sections ?: [Section::make()->schema([Text::make('Nothing to check.')])]);
    }
}
