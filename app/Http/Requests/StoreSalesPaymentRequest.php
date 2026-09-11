<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'sales_invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'payment_no' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('sales_payments', 'payment_no')->whereNull('deleted_at'),
            ],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
