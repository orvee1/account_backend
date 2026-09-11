<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $id      = is_object($product) ? $product->id : (is_numeric($product) ? (int) $product : null);

        return [
            // Core
            'product_type'                       => ['required', Rule::in(['Stock', 'Non-stock', 'Service', 'Combo'])],
            'name'                               => ['required', 'string', 'max:255'],

            // New identifiers
            'sku'                                => [
                'nullable', 'string', 'max:100',
                Rule::unique('products', 'sku')
                    ->ignore($id)
                    ->where(fn($q) => $q->where('company_id', auth()->user()->company_id)),
            ],
            'barcode'                            => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'barcode')
                    ->ignore($id)
                    ->where(fn($q) => $q->where('company_id', auth()->user()->company_id)),
            ],

            'description'                        => ['nullable', 'string'],

            // FKs
            'category_id'                        => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id'                           => ['nullable', 'integer', 'exists:brands,id'],
            'warehouse_id'                       => ['nullable', 'integer', 'exists:warehouses,id'],

            // Pricing
            'costing_price'                      => ['nullable', 'numeric', 'min:0'],
            'sales_price'                        => ['nullable', 'numeric', 'min:0'],
            'tax_percent'                        => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Warranty
            'has_warranty'                       => ['boolean'],
            'warranty_days'                      => ['nullable', 'integer', 'min:0'],

            // Units (optional for all; if provided must be valid and single base unit)
            'units'                              => ['array'],
            'units.*.name'                       => ['required_with:units', 'string', 'max:50'],
            'units.*.factor'                     => ['required_with:units', 'numeric', 'min:0.000001'],
            'units.*.is_base'                    => ['required_with:units', 'boolean'],

            // Opening stock (allowed only for Stock)
            'opening_quantity'                   => ['nullable', 'numeric', 'min:0'],
            'opening_unit_cost'                  => ['nullable', 'numeric', 'min:0'],
            'opening_date'                       => ['nullable', 'date'],
            'manufactured_at'                    => ['nullable', 'date'],
            'expired_at'                         => ['nullable', 'date', 'after_or_equal:manufactured_at'],

            // Combo items (requiredfor Combo)
            'combo_items'                        => ['array'],
            'combo_items.*.product_id'           => ['required_with:combo_items', 'integer', 'exists:products,id'],
            'combo_items.*.quantity'             => ['required_with:combo_items', 'numeric', 'min:0.000001'],

            // Misc
            'barcode'                            => ['nullable', 'string', 'max:255'],
            'unit'                               => ['nullable', 'string', 'max:50'],
            'status'                             => ['nullable', Rule::in(['active', 'inactive'])],
            'meta'                               => ['nullable', 'array'],

            'vat_rate'                           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_inclusive'                      => ['boolean'],
            'ait_rate'                           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'base_uom_id'                        => ['nullable', 'integer', 'exists:units_of_measure,id'],

            'product_uoms'                       => ['array'],
            'product_uoms.*.uom_id'              => ['nullable', 'integer', 'exists:units_of_measure,id'],
            'product_uoms.*.name'                => ['nullable', 'string', 'max:255'],
            'product_uoms.*.symbol'              => ['nullable', 'string', 'max:50'],
            'product_uoms.*.conversion_factor'   => ['required_with:product_uoms', 'numeric', 'min:0.000001'],
            'product_uoms.*.sale_price'          => ['required_with:product_uoms', 'numeric', 'min:0'],
            'product_uoms.*.is_base_uom'         => ['required_with:product_uoms', 'boolean'],
            'product_uoms.*.is_default_sale_uom' => ['required_with:product_uoms', 'boolean'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $t = $this->input('product_type');

            $hasOpening = $this->filled('opening_quantity')
            || $this->filled('opening_unit_cost')
            || $this->filled('opening_date')
            || $this->filled('warehouse_id')
            || $this->filled('batch_no')
            || $this->filled('manufactured_at')
            || $this->filled('expired_at');

            // Type-based constraints
            if ($t === 'Stock') {

                $openingQty = (float) $this->input('opening_quantity', 0);

                /*
    |--------------------------------------------------------------------------
    | Warehouse Validation
    |--------------------------------------------------------------------------
    | Opening stock দেওয়া হলে warehouse অবশ্যই লাগবে।
    */
                if ($openingQty > 0 && ! $this->filled('warehouse_id')) {
                    $v->errors()->add(
                        'warehouse_id',
                        'warehouse_id is required when opening_quantity is provided for Stock products.'
                    );
                }

                /*
    |--------------------------------------------------------------------------
    | Opening Cost Validation
    |--------------------------------------------------------------------------
    | Opening quantity > 0 হলে valuation-এর জন্য একটি valid cost থাকতে হবে।
    |
    | Priority:
    | 1. opening_unit_cost
    | 2. costing_price
    */
                if ($openingQty > 0) {

                    $openingCost = (float) (
                        $this->input('opening_unit_cost') ?? $this->input('costing_price') ?? 0
                    );

                    if ($openingCost <= 0) {
                        $v->errors()->add(
                            'opening_unit_cost',
                            'Opening unit cost or costing price must be greater than zero when opening stock exists.'
                        );
                    }
                }

            } elseif (in_array($t, ['Non-stock', 'Service'], true)) {

                /*
    |--------------------------------------------------------------------------
    | Non-stock / Service
    |--------------------------------------------------------------------------
    | These product types must not contain inventory opening/batch data.
    */
                if ($hasOpening) {
                    $v->errors()->add(
                        'opening_quantity',
                        'Opening/batch fields are not allowed for Non-stock/Service products.'
                    );
                }

            } elseif ($t === 'Combo') {

                /*
    |--------------------------------------------------------------------------
    | Combo Products
    |--------------------------------------------------------------------------
    | Combo itself does not hold opening inventory.
    */
                if ($hasOpening) {
                    $v->errors()->add(
                        'opening_quantity',
                        'Opening/batch fields are not allowed for Combo products.'
                    );
                }

                /*
    |--------------------------------------------------------------------------
    | Combo Items Validation
    |--------------------------------------------------------------------------
    */
                $items = $this->input('combo_items', []);

                if (! is_array($items) || count($items) === 0) {
                    $v->errors()->add(
                        'combo_items',
                        'combo_items is required and must contain at least one item for Combo products.'
                    );
                }
            }

            // Units: if provided, enforce exactly one base unit
            if ($this->filled('units') && is_array($this->input('units'))) {
                $bases = collect($this->input('units'))->where('is_base', true)->count();
                if ($bases === 0) {
                    $v->errors()->add('units', 'At least one unit must have is_base=true.');
                } elseif ($bases > 1) {
                    $v->errors()->add('units', 'Only one unit may have is_base=true.');
                }
            }

            // Product UOMs validation
            if ($this->filled('product_uoms') && is_array($this->input('product_uoms'))) {
                $uoms = collect($this->input('product_uoms'));

                $baseUomsCount = $uoms->where('is_base_uom', true)->count();
                if ($baseUomsCount !== 1) {
                    $v->errors()->add('product_uoms', 'Exactly one UOM must be marked as base UOM.');
                }

                $defaultSaleUomsCount = $uoms->where('is_default_sale_uom', true)->count();
                if ($defaultSaleUomsCount !== 1) {
                    $v->errors()->add('product_uoms', 'Exactly one UOM must be marked as default sale UOM.');
                }

                // Base UOM must have factor 1
                $baseUom = $uoms->where('is_base_uom', true)->first();
                if ($baseUom && (float) $baseUom['conversion_factor'] !== 1.0) {
                    $v->errors()->add('product_uoms', 'The base UOM must have a conversion factor of 1.');
                }

                // No duplicate names (if provided)
                $names = $uoms->pluck('name')->filter()->toArray();
                if (count($names) !== count(array_unique($names))) {
                    $v->errors()->add('product_uoms', 'Duplicate UOM names are not allowed.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'sku.unique'                    => 'This SKU is already used within your company.',
            'barcode.unique'                => 'This barcode is already used within your company.',
            'units.*.name.required_with'    => 'Each provided unit must have a name.',
            'units.*.factor.required_with'  => 'Each provided unit must have a factor.',
            'units.*.is_base.required_with' => 'Each provided unit must specify is_base.',
        ];
    }
}
