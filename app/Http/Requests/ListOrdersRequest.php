<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(OrderStatus::class)],
            'email' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?OrderStatus
    {
        $status = $this->validated('status');

        if ($status === null || $status === '') {
            return null;
        }

        return $status instanceof OrderStatus
            ? $status
            : OrderStatus::from($status);
    }

    public function email(): ?string
    {
        $email = $this->validated('email');

        if ($email === null || $email === '') {
            return null;
        }

        return $email;
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
