<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    public const STATUS_LABELS = [
        'created' => 'Создан',
        'paid' => 'Оплачен',
        'shipped' => 'Передан в доставку',
        'delivered' => 'Доставлен',
        'cancelled' => 'Отменён',
    ];

    protected static ?string $model = Order::class;

    protected static ?string $modelLabel = 'заказ';

    protected static ?string $pluralModelLabel = 'Заказы';

    protected static ?string $recordTitleAttribute = 'number';

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Номер')->searchable(),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => self::STATUS_LABELS[$state->value]),
                TextColumn::make('customer_email')->label('Email'),
                TextColumn::make('customer_name')->label('Покупатель'),
                TextColumn::make('total')->label('Сумма')
                    ->formatStateUsing(fn (string $state, Order $record): string => $state.' '.$record->currency),
                TextColumn::make('marketplace_created_at')->label('Оформлен')
                    ->dateTime('d.m.Y H:i:s', timezone: 'UTC')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(self::STATUS_LABELS),
                Filter::make('email')->label('Email покупателя')
                    ->schema([TextInput::make('email')->label('Email покупателя')])
                    ->query(fn (Builder $query, array $data): Builder => $query->forEmail($data['email'] ?: null)),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('marketplace_created_at')->orderByDesc('id'))
            ->recordActions([ViewAction::make()->label('Открыть')])
            ->emptyStateHeading('Заказов пока нет')
            ->emptyStateDescription('Заказы появятся после обработки вебхуков MarketHub.');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
