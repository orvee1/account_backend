<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only([
            'q', 'product_type', 'category_id', 'brand_id',
            'sku', 'barcode', 'status', 'min_price', 'max_price',
            'per_page', 'sort'
        ]);

        $list = app(ProductService::class)->paginate($filters);
        return response()->json($list);
    }

    public function store(ProductRequest $request)
    {
        $data = $request->validated();
        $product = app(ProductService::class)->create($data);
        return response()->json($product->load(['units','comboItems.itemProduct']), 201);
    }

    public function show(Product $product)
    {
        $service = app(ProductService::class);
        $payload = $product->load(['units','comboItems.itemProduct'])->toArray();
        $payload['can_edit_costing_price'] = $service->canEditCostingPrice($product);

        return response()->json($payload);
    }

    public function update(ProductRequest $request, Product $product)
    {
        $service = app(ProductService::class);
        $data = $request->validated();

        if (array_key_exists('costing_price', $data) && !$service->canEditCostingPrice($product)) {
            return response()->json([
                'message' => 'Costing price cannot be changed because this item has stock quantity, stock value, or transaction history.'
            ], 422);
        }

        $updated = $service->update($product->id, $data);
        return response()->json($updated->load(['units','comboItems.itemProduct']));
    }

    public function destroy(Product $product)
    {
        $service = app(ProductService::class);

        if (!$service->canBeDeleted($product)) {
            return response()->json([
                'message' => 'This product/service cannot be deleted because it has quantity, value, or transaction history.'
            ], 422);
        }

        $product->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
