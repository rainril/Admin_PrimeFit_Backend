<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchItem;
use Illuminate\Http\Request;

class MerchItemController extends Controller
{
    /**
     * GET /api/merch-items
     * Returns the item list plus the summary numbers for the top cards
     * ("Total Merch Items", "Total Merch Revenue", "Total Units Sold").
     */
    public function index()
    {
        $items = MerchItem::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'items' => $items,
            'totalItems' => $items->count(),
            'totalRevenue' => (float) $items->sum('revenue'),
            'totalUnitsSold' => (int) $items->sum('sold'),
        ]);
    }

    /**
     * POST /api/merch-items
     * "+ Add Item" — creates a new merch item. sold/revenue start at 0.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sku' => 'required|string|max:50|unique:merch_items,sku',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'image_url' => 'nullable|string|max:255',
        ]);

        $item = MerchItem::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Item added.',
            'item' => $item,
        ], 201);
    }

    /**
     * PUT /api/merch-items/{id}
     * "Edit Price" (and can also update name/sku if you extend the form).
     */
    public function update(Request $request, int $id)
    {
        $item = MerchItem::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'image_url' => 'nullable|string|max:255',
        ]);

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Item updated.',
            'item' => $item,
        ]);
    }

    /**
     * POST /api/merch-items/{id}/restock
     * Manual stock addition — e.g. new delivery arrived. This is separate
     * from the sales flow (MerchSaleController), which only ever DEDUCTS
     * stock on confirm().
     */
    public function restock(Request $request, int $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item = MerchItem::findOrFail($id);
        $item->stock += $validated['quantity'];
        $item->save();

        return response()->json([
            'success' => true,
            'message' => "Added {$validated['quantity']} unit(s). New stock: {$item->stock}.",
            'item' => $item,
        ]);
    }

    /**
     * DELETE /api/merch-items/{id}
     * "Remove" — blocked if the item has confirmed sales history, so you
     * don't lose the audit trail. Use stock=0 instead if you just want to
     * stop selling it.
     */
    public function destroy(int $id)
    {
        $item = MerchItem::withCount('sales')->findOrFail($id);

        if ($item->sales_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This item has sales history and cannot be deleted. Set stock to 0 instead to retire it.',
            ], 409);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed.',
        ]);
    }
}
