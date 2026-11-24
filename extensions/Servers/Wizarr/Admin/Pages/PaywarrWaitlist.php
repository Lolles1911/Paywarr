<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin\Pages;

use App\Models\Product;
use Filament\Actions\Action;
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

/**
 * Paywarr → Waiting list: who waits for a seat on a sold-out product, and
 * who was mailed that a seat is free.
 */
class PaywarrWaitlist extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-time-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr: Customers';

    protected static ?int $navigationSort = 107;

    protected static ?string $navigationLabel = 'Waiting list';

    protected static ?string $title = 'Waiting list';

    protected static ?string $slug = 'paywarr-waitlist';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        return 'When a product is sold out (Capacity), visitors can join this list. When seats open, as many as there are free seats get a mail, in turn; ' . Wizarr::WAITLIST_HOLD_HOURS . ' hours later, if a seat is still free, the next. The shop is open to everyone then, so it is first come. Those who order leave the list by themselves.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $rows = Wizarr::waitlist();
                uasort($rows, fn ($a, $b) => [$a['product'], $a['at']] <=> [$b['product'], $b['at']]);

                return $rows;
            })
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('product')->label('Product')->formatStateUsing(fn ($state) => Product::find((int) $state)?->name ?? '#' . $state),
                TextColumn::make('email')->label('Email')->url(fn (array $record) => $record['user'] ? PaywarrCustomers::getUrl(['customer' => $record['user']]) : null),
                TextColumn::make('at')->label('Joined')->formatStateUsing(fn ($state) => date('M j, H:i', (int) $state)),
                TextColumn::make('mailed')->label('Seat offered')->placeholder('Waiting')->badge()->color('success')
                    ->formatStateUsing(fn ($state) => 'Mailed ' . date('M j, H:i', (int) $state)),
            ])
            ->recordActions([
                Action::make('mail')->label('Offer a seat now')->icon('ri-mail-send-line')->requiresConfirmation()
                    ->modalDescription('Mails them that a seat is free, even if the product is still full. Raise the maximum under Capacity first, or they can\'t order.')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                    ->action(function (array $record) {
                        $all = Wizarr::waitlist();
                        if ((new Wizarr([]))->mailWaitlistSeat($record)) {
                            $all[$record['id']]['mailed'] = time();
                            Wizarr::storePut('waitlist', $all);
                            Notification::make()->title('Mailed')->success()->send();
                        } else {
                            Notification::make()->title('Not sent')->danger()->send();
                        }
                        $this->resetTable();
                    }),
                Action::make('remove')->label('Remove')->icon('ri-delete-bin-line')->color('danger')->requiresConfirmation()
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('admin.services.update'))
                    ->action(function (array $record) {
                        Wizarr::leaveWaitlist($record['id']);
                        $this->resetTable();
                    }),
            ])
            ->emptyStateHeading('Nobody is waiting')
            ->emptyStateDescription('The list opens on a product\'s page when it is sold out (Capacity).');
    }
}
