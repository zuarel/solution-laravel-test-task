<?php

namespace App\Validation;

use App\Enums\OrderStatus;
use App\Rules\Iso8601DateTime;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;

class MarketplaceOrderValidator
{
    // decimal(18, 2): up to 16 integer digits; leading zeros do not affect the range.
    private const AMOUNT_PATTERN = '/\A-?0*[0-9]{1,16}\.[0-9]{2}\z/';

    private const POSTGRES_INTEGER_MAX = 2147483647;

    private const CURRENCY_CODE_PATTERN = '/\A[A-Z]{3}\z/';

    public function validate(array $payload): void
    {
        Validator::make($payload, $this->rules($payload), $this->messages())->validate();
    }

    private function rules(array $payload): array
    {
        $amount = ['required', 'string', 'regex:'.self::AMOUNT_PATTERN];
        $hasShipment = data_get($payload, 'data.order.shipment') !== null;

        return [
            'data.order' => ['required', 'array'],
            'data.order.id' => ['required', 'string'],
            'data.order.number' => ['required', 'string'],
            'data.order.status' => ['required', Rule::enum(OrderStatus::class)],
            'data.order.currency' => ['required', 'string', 'regex:'.self::CURRENCY_CODE_PATTERN],
            'data.order.customer' => ['required', 'array'],
            'data.order.customer.email' => ['required', 'string', 'email:rfc'],
            'data.order.customer.name' => ['required', 'string'],
            'data.order.customer.phone' => ['present', 'nullable', 'string'],
            'data.order.items' => ['required', 'array', 'list', 'min:1'],
            'data.order.items.*' => ['required', 'array'],
            'data.order.items.*.sku' => ['required', 'string'],
            'data.order.items.*.name' => ['required', 'string'],
            'data.order.items.*.quantity' => ['required', 'integer:strict', 'min:1', 'max:'.self::POSTGRES_INTEGER_MAX],
            'data.order.items.*.price' => $amount,
            'data.order.delivery_price' => $amount,
            'data.order.discount' => $amount,
            'data.order.total' => $amount,
            'data.order.shipment' => ['present', Rule::requiredIf($hasShipment), 'nullable', 'array'],
            'data.order.shipment.carrier' => [Rule::requiredIf($hasShipment), 'string'],
            'data.order.shipment.tracking_number' => [Rule::requiredIf($hasShipment), 'string'],
            'data.order.shipment.tracking_url' => [Rule::requiredIf($hasShipment), 'string', 'url:http,https'],
            'data.order.paid_at' => ['present', Rule::requiredIf(data_get($payload, 'data.order.paid_at') !== null), 'nullable', new Iso8601DateTime],
            'data.order.cancelled_at' => ['present', Rule::requiredIf(data_get($payload, 'data.order.cancelled_at') !== null), 'nullable', new Iso8601DateTime],
            'data.order.cancel_reason' => ['present', 'nullable', 'string'],
            'data.order.created_at' => ['required', new Iso8601DateTime],
            'data.order.updated_at' => ['required', new Iso8601DateTime],
        ];
    }

    private function messages(): array
    {
        return [
            'required' => 'The :attribute field is required and must not be empty.',
            'present' => 'The :attribute field must be present, even when its value is null.',
            'string' => 'The :attribute field must be a string.',
            'array' => 'The :attribute field must be a JSON object or array.',
            'list' => 'The :attribute field must be a JSON array with consecutive indexes.',
            'integer' => 'The :attribute field must be an integer JSON number, not a string.',
            'email' => 'The :attribute field must contain a valid email address.',
            'data.order.status.'.EnumRule::class => 'The order status must be one of: '.implode(', ', array_column(OrderStatus::cases(), 'value')).'.',
            'data.order.currency.regex' => 'The currency code must contain exactly three uppercase letters.',
            'data.order.items.min' => 'The order must contain at least one item.',
            'data.order.items.*.quantity.min' => 'The item quantity must be at least :min.',
            'data.order.items.*.quantity.max' => 'The item quantity must not exceed :max.',
            'regex' => 'The :attribute field must be a decimal string with exactly two fractional digits and at most 16 integer digits, excluding leading zeros.',
            'data.order.shipment.tracking_url.url' => 'The tracking URL must be a valid HTTP or HTTPS URL.',
        ];
    }
}
