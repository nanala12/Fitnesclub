<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // Domain validation and forbidden financial fields remain in CreateOnlineOrder.
        return ['checkout_request_id' => ['required', 'uuid'], 'purchaser.email' => ['required', 'string', 'email', 'max:50']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['purchaser.email.max' => 'Przelewy24 obsługuje adres kupującego o długości do 50 znaków.',
            'checkout_request_id.required' => 'Odśwież formularz zakupu i spróbuj ponownie.', 'checkout_request_id.uuid' => 'Odśwież formularz zakupu i spróbuj ponownie.'];
    }
}
