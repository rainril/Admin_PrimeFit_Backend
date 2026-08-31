<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use Illuminate\Http\Request;

class EquipmentItemController extends Controller
{
    /**
     * GET /api/equipment-items
     * Returns the item list plus the summary numbers for the top cards
     * ("Total Equipment", "Available", "In Maintenance", "Damaged").
     * Note: these counts SUM the `qty` column per status, matching how
     * your original hardcoded cards worked (qty-weighted, not row count).
     */
    public function index()
    {
        $items = EquipmentItem::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'items' => $items,
            'totalQty' => (int) $items->sum('qty'),
            'availableQty' => (int) $items->where('status', 'Available')->sum('qty'),
            'maintenanceQty' => (int) $items->where('status', 'Maintenance')->sum('qty'),
            'damagedQty' => (int) $items->where('status', 'Damaged')->sum('qty'),
        ]);
    }

    /**
     * POST /api/equipment-items
     * "+ Add Item" (Equipment type).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'barcode' => 'required|string|max:50|unique:equipment_items,barcode',
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'qty' => 'required|integer|min:0',
            'status' => 'nullable|in:Available,Maintenance,Damaged',
            'location' => 'nullable|string|max:255',
            'next_maintenance' => 'nullable|date',
            'description' => 'nullable|string',
        ]);

        $validated['status'] = $validated['status'] ?? 'Available';

        $item = EquipmentItem::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Equipment added.',
            'item' => $item,
        ], 201);
    }

    /**
     * PUT /api/equipment-items/{id}
     * "Edit" — status, qty, and location (the fields you asked to make
     * editable). Also accepts name/category/description/next_maintenance
     * if you want to extend the edit form later — all fields are optional
     * here ("sometimes"), so you only send what actually changed.
     */
    public function update(Request $request, int $id)
    {
        $item = EquipmentItem::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|in:Available,Maintenance,Damaged',
            'qty' => 'sometimes|integer|min:0',
            'location' => 'sometimes|nullable|string|max:255',
            'name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:100',
            'next_maintenance' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string',
        ]);

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Equipment updated.',
            'item' => $item,
        ]);
    }

    /**
     * POST /api/equipment-items/{id}/maintain
     * Quick action from the table row — same as your old "Maintain" button,
     * just flips status to Maintenance without opening the full edit form.
     */
    public function maintain(int $id)
    {
        $item = EquipmentItem::findOrFail($id);
        $item->status = 'Maintenance';
        $item->save();

        return response()->json([
            'success' => true,
            'message' => "{$item->name} marked for maintenance.",
            'item' => $item,
        ]);
    }

    /**
     * DELETE /api/equipment-items/{id}
     */
    public function destroy(int $id)
    {
        $item = EquipmentItem::findOrFail($id);
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Equipment removed.',
        ]);
    }
}
