<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заказ')->schema([
                TextEntry::make('marketplace_id')->label('ID MarketHub'),
                TextEntry::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => OrderResource::STATUS_LABELS[$state->value]),
                TextEntry::make('currency')->label('Валюта'),
                TextEntry::make('total')->label('Итого'),
                TextEntry::make('delivery_price')->label('Доставка'),
                TextEntry::make('discount')->label('Скидка'),
                TextEntry::make('marketplace_created_at')->label('Оформлен (UTC)')->dateTime('d.m.Y H:i:s', timezone: 'UTC'),
                TextEntry::make('marketplace_updated_at')->label('Обновлён (UTC)')->dateTime('d.m.Y H:i:s', timezone: 'UTC'),
                TextEntry::make('paid_at')->label('Оплачен (UTC)')->dateTime('d.m.Y H:i:s', timezone: 'UTC')->placeholder('—'),
                TextEntry::make('cancelled_at')->label('Отменён (UTC)')->dateTime('d.m.Y H:i:s', timezone: 'UTC')->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Причина отмены')->placeholder('—'),
            ])->columns(2),
            Section::make('Покупатель')->schema([
                TextEntry::make('customer_name')->label('Имя'),
                TextEntry::make('customer_email')->label('Email'),
                TextEntry::make('customer_phone')->label('Телефон')->placeholder('—'),
            ]),
            Section::make('Позиции')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->schema([
                    TextEntry::make('sku')->label('Артикул'),
                    TextEntry::make('name')->label('Товар'),
                    TextEntry::make('quantity')->label('Количество'),
                    TextEntry::make('price')->label('Цена за единицу'),
                ])->columns(4),
            ])->columnSpanFull(),
            Section::make('Доставка')->schema([
                TextEntry::make('shipment_carrier')->label('Служба доставки')->placeholder('—'),
                TextEntry::make('shipment_tracking_number')->label('Трек-номер')->placeholder('—'),
                TextEntry::make('shipment_tracking_url')->label('Ссылка на отслеживание')->placeholder('—'),
            ])->columnSpanFull(),
            Section::make('История статусов')->schema([
                RepeatableEntry::make('statusHistory')->hiddenLabel()->schema([
                    TextEntry::make('status')->label('Статус')
                        ->formatStateUsing(fn (OrderStatus $state): string => OrderResource::STATUS_LABELS[$state->value]),
                    TextEntry::make('occurred_at')->label('Время (UTC)')->dateTime('d.m.Y H:i:s', timezone: 'UTC'),
                ])->columns(2),
            ])->columnSpanFull(),
        ]);
    }
}
