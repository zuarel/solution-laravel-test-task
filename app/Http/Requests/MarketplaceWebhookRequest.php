<?php

namespace App\Http\Requests;

use App\Rules\Iso8601DateTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;
use Illuminate\Validation\Validator;
use JsonException;

class MarketplaceWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        try {
            $body = json_decode($this->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid JSON body.');
        }

        abort_unless(is_array($body), Response::HTTP_BAD_REQUEST, 'The JSON body must be an object or array.');

        return $body;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'type' => ['required', 'string'],
            'created_at' => ['required', new Iso8601DateTime],
            'data' => ['required', 'array'],
            'data.order' => ['present', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'The :attribute field is required and must not be empty.',
            'present' => 'The :attribute field must be present.',
            'string' => 'The :attribute field must be a string.',
            'array' => 'The :attribute field must be a JSON object or array.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (($validator->getData()['id'] ?? null) !== $this->header('X-Webhook-Id')) {
                $validator->errors()->add('id', 'The event ID must match X-Webhook-Id.');
            }
        }];
    }
}
