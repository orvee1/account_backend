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
        $product =
        $this->route(
            'product'
        );

        $id =
        is_object($product)
            ? $product->id
            : (
            is_numeric($product)
                ? (int) $product
                : null
        );

        $companyId =
        auth()
            ->user()
            ->company_id;

        return [
            /*
            |--------------------------------------------------------------------------
            | Core
            |--------------------------------------------------------------------------
            */

            'product_type'                       => [
                'required',

                Rule::in([
                    'Stock',
                    'Non-stock',
                    'Service',
                    'Combo',
                ]),
            ],

            'name'                               => [
                'required',
                'string',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Identifiers
            |--------------------------------------------------------------------------
            */

            'sku'                                => [
                'nullable',
                'string',
                'max:100',

                Rule::unique(
                    'products',
                    'sku'
                )
                    ->ignore(
                        $id
                    )
                    ->where(
                        fn($query) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                    ),
            ],

            /*
             * IMPORTANT:
             *
             * barcode appears ONLY ONCE.
             *
             * Previously another barcode key later in this array silently
             * overwrote this company-scoped unique validation.
             */
            'barcode'                            => [
                'nullable',
                'string',
                'max:255',

                Rule::unique(
                    'products',
                    'barcode'
                )
                    ->ignore(
                        $id
                    )
                    ->where(
                        fn($query) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                    ),
            ],

            'description'                        => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */

            'category_id'                        => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],

            'brand_id'                           => [
                'nullable',
                'integer',
                'exists:brands,id',
            ],

            /*
             * Warehouse must belong to the authenticated company.
             */
            'warehouse_id'                       => [
                'nullable',
                'integer',

                Rule::exists(
                    'warehouses',
                    'id'
                )->where(
                    fn($query) =>
                    $query->where(
                        'company_id',
                        $companyId
                    )
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Pricing
            |--------------------------------------------------------------------------
            */

            'costing_price'                      => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'sales_price'                        => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'tax_percent'                        => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | VAT / AIT
            |--------------------------------------------------------------------------
            */

            'vat_rate'                           => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'vat_inclusive'                      => [
                'boolean',
            ],

            'ait_rate'                           => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Warranty
            |--------------------------------------------------------------------------
            */

            'has_warranty'                       => [
                'boolean',
            ],

            'warranty_days'                      => [
                'nullable',
                'integer',
                'min:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Legacy Units
            |--------------------------------------------------------------------------
            */

            'units'                              => [
                'nullable',
                'array',
            ],

            'units.*.name'                       => [
                'required_with:units',
                'string',
                'max:50',
            ],

            'units.*.factor'                     => [
                'required_with:units',
                'numeric',
                'min:0.000001',
            ],

            'units.*.is_base'                    => [
                'required_with:units',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Opening Stock
            |--------------------------------------------------------------------------
            |
            | Used by ProductService:
            |
            | opening_quantity
            | opening_unit_cost
            | opening_date
            | warehouse_id
            |
            */

            'opening_quantity'                   => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'opening_unit_cost'                  => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'opening_date'                       => [
                'nullable',
                'date',
            ],

            /*
             * Optional batch metadata.
             */
            'batch_no'                           => [
                'nullable',
                'string',
                'max:100',
            ],

            'manufactured_at'                    => [
                'nullable',
                'date',
            ],

            'expired_at'                         => [
                'nullable',
                'date',
                'after_or_equal:manufactured_at',
            ],

            /*
            |--------------------------------------------------------------------------
            | Combo Items
            |--------------------------------------------------------------------------
            */

            'combo_items'                        => [
                'nullable',
                'array',
            ],

            'combo_items.*.product_id'           => [
                'required_with:combo_items',
                'integer',
                'exists:products,id',
            ],

            'combo_items.*.quantity'             => [
                'required_with:combo_items',
                'numeric',
                'min:0.000001',
            ],

            /*
            |--------------------------------------------------------------------------
            | Miscellaneous
            |--------------------------------------------------------------------------
            */

            'unit'                               => [
                'nullable',
                'string',
                'max:50',
            ],

            'status'                             => [
                'nullable',

                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],

            'meta'                               => [
                'nullable',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Product UOMs
            |--------------------------------------------------------------------------
            */

            'base_uom_id'                        => [
                'nullable',
                'integer',
                'exists:units_of_measure,id',
            ],

            'product_uoms'                       => [
                'nullable',
                'array',
            ],

            'product_uoms.*.uom_id'              => [
                'nullable',
                'integer',
                'exists:units_of_measure,id',
            ],

            'product_uoms.*.name'                => [
                'nullable',
                'string',
                'max:255',
            ],

            'product_uoms.*.symbol'              => [
                'nullable',
                'string',
                'max:50',
            ],

            'product_uoms.*.conversion_factor'   => [
                'required_with:product_uoms',
                'numeric',
                'min:0.000001',
            ],

            'product_uoms.*.sale_price'          => [
                'required_with:product_uoms',
                'numeric',
                'min:0',
            ],

            'product_uoms.*.is_base_uom'         => [
                'required_with:product_uoms',
                'boolean',
            ],

            'product_uoms.*.is_default_sale_uom' => [
                'required_with:product_uoms',
                'boolean',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Additional Validation
    |--------------------------------------------------------------------------
    */

    public function withValidator(
        $validator
    ): void {
        $validator->after(
            function ($validator) {
                $type =
                $this->input(
                    'product_type'
                );

                /*
                |--------------------------------------------------------------------------
                | Detect Opening / Batch Data
                |--------------------------------------------------------------------------
                */

                $hasOpening =
                $this->filled(
                    'opening_quantity'
                )
                ||
                $this->filled(
                    'opening_unit_cost'
                )
                ||
                $this->filled(
                    'opening_date'
                )
                ||
                $this->filled(
                    'warehouse_id'
                )
                ||
                $this->filled(
                    'batch_no'
                )
                ||
                $this->filled(
                    'manufactured_at'
                )
                ||
                $this->filled(
                    'expired_at'
                );

                /*
                |--------------------------------------------------------------------------
                | Stock Product
                |--------------------------------------------------------------------------
                */

                if (
                    $type ===
                    'Stock'
                ) {
                    $openingQty =
                    (float)
                    $this->input(
                        'opening_quantity',
                        0
                    );

                    /*
                     * Opening stock requires a warehouse.
                     */
                    if (
                        $openingQty >
                        0
                        &&
                        ! $this->filled(
                            'warehouse_id'
                        )
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'warehouse_id',
                                'warehouse_id is required when opening_quantity is provided for Stock products.'
                            );
                    }

                    /*
                     * Opening stock must have a positive unit cost.
                     *
                     * Priority matches ProductService:
                     *
                     * opening_unit_cost
                     *       ↓
                     * costing_price
                     */
                    if (
                        $openingQty >
                        0
                    ) {
                        $openingCost =
                        (float) (
                            $this->input(
                                'opening_unit_cost'
                            ) ??
                            $this->input(
                                'costing_price'
                            ) ??
                            0
                        );

                        if (
                            $openingCost <=
                            0
                        ) {
                            $validator
                                ->errors()
                                ->add(
                                    'opening_unit_cost',
                                    'Opening unit cost or costing price must be greater than zero when opening stock exists.'
                                );
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Non-stock / Service
                |--------------------------------------------------------------------------
                */

                elseif (
                    in_array(
                        $type,
                        [
                            'Non-stock',
                            'Service',
                        ],
                        true
                    )
                ) {
                    if (
                        $hasOpening
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'opening_quantity',
                                'Opening/batch fields are not allowed for Non-stock/Service products.'
                            );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Combo
                |--------------------------------------------------------------------------
                */

                elseif (
                    $type ===
                    'Combo'
                ) {
                    if (
                        $hasOpening
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'opening_quantity',
                                'Opening/batch fields are not allowed for Combo products.'
                            );
                    }

                    $items =
                    $this->input(
                        'combo_items',
                        []
                    );

                    if (
                        ! is_array(
                            $items
                        )
                        ||
                        count(
                            $items
                        ) ===
                        0
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'combo_items',
                                'combo_items is required and must contain at least one item for Combo products.'
                            );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Legacy Units
                |--------------------------------------------------------------------------
                |
                | If provided:
                |
                | exactly one unit must be base.
                |
                */

                if (
                    $this->filled(
                        'units'
                    )
                    &&
                    is_array(
                        $this->input(
                            'units'
                        )
                    )
                ) {
                    $units =
                        collect(
                        $this->input(
                            'units'
                        )
                    );

                    $baseCount =
                    $units
                        ->where(
                            'is_base',
                            true
                        )
                        ->count();

                    if (
                        $baseCount ===
                        0
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'units',
                                'At least one unit must have is_base=true.'
                            );
                    } elseif (
                        $baseCount >
                        1
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'units',
                                'Only one unit may have is_base=true.'
                            );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Product UOM Validation
                |--------------------------------------------------------------------------
                */

                if (
                    $this->filled(
                        'product_uoms'
                    )
                    &&
                    is_array(
                        $this->input(
                            'product_uoms'
                        )
                    )
                ) {
                    $uoms =
                        collect(
                        $this->input(
                            'product_uoms'
                        )
                    );

                    /*
                     * Exactly one Base UOM.
                     */
                    $baseUomsCount =
                    $uoms
                        ->where(
                            'is_base_uom',
                            true
                        )
                        ->count();

                    if (
                        $baseUomsCount !==
                        1
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'product_uoms',
                                'Exactly one UOM must be marked as base UOM.'
                            );
                    }

                    /*
                     * Exactly one Default Sale UOM.
                     */
                    $defaultSaleUomsCount =
                    $uoms
                        ->where(
                            'is_default_sale_uom',
                            true
                        )
                        ->count();

                    if (
                        $defaultSaleUomsCount !==
                        1
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'product_uoms',
                                'Exactly one UOM must be marked as default sale UOM.'
                            );
                    }

                    /*
                     * Base UOM conversion factor must equal 1.
                     */
                    $baseUom =
                    $uoms
                        ->where(
                            'is_base_uom',
                            true
                        )
                        ->first();

                    if (
                        $baseUom
                        &&
                        isset(
                            $baseUom[
                                'conversion_factor'
                            ]
                        )
                        &&
                        (float)
                        $baseUom[
                            'conversion_factor'
                        ] !==
                        1.0
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'product_uoms',
                                'The base UOM must have a conversion factor of 1.'
                            );
                    }

                    /*
                     * Duplicate UOM names are not allowed.
                     */
                    $names =
                    $uoms
                        ->pluck(
                            'name'
                        )
                        ->filter()
                        ->map(
                            fn($name) =>
                            strtolower(
                                trim(
                                    (string)
                                    $name
                                )
                            )
                        )
                        ->values()
                        ->all();

                    if (
                        count(
                            $names
                        )
                        !==
                        count(
                            array_unique(
                                $names
                            )
                        )
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                'product_uoms',
                                'Duplicate UOM names are not allowed.'
                            );
                    }
                }
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Messages
    |--------------------------------------------------------------------------
    */

    public function messages(): array
    {
        return [
            'sku.unique'                                       =>
            'This SKU is already used within your company.',

            'barcode.unique'                                   =>
            'This barcode is already used within your company.',

            'warehouse_id.exists'                              =>
            'The selected warehouse does not belong to your company.',

            'units.*.name.required_with'                       =>
            'Each provided unit must have a name.',

            'units.*.factor.required_with'                     =>
            'Each provided unit must have a factor.',

            'units.*.is_base.required_with'                    =>
            'Each provided unit must specify is_base.',

            'product_uoms.*.conversion_factor.required_with'   =>
            'Each provided product UOM must have a conversion factor.',

            'product_uoms.*.sale_price.required_with'          =>
            'Each provided product UOM must have a sale price.',

            'product_uoms.*.is_base_uom.required_with'         =>
            'Each provided product UOM must specify whether it is the base UOM.',

            'product_uoms.*.is_default_sale_uom.required_with' =>
            'Each provided product UOM must specify whether it is the default sale UOM.',
        ];
    }
}
