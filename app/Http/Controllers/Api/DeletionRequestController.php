<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeletionRequest;
use App\Models\EquipmentItem;
use App\Models\MerchItem;
use Illuminate\Http\Request;

class DeletionRequestController extends Controller
{
    /**
     * GET /api/deletion-requests?status=Pending
     * Owner's approval queue. Defaults to Pending if no status given.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'Pending');

        $requests = DeletionRequest::where('status', $status)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'requests' => $requests,
            'count' => $requests->count(),
        ]);
    }

    /**
     * POST /api/deletion-requests
     * Staff calls this instead of DELETE-ing the item directly. Does NOT
     * remove anything yet — just logs the request for the owner to review.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_type' => 'required|in:equipment,merch',
            'item_id' => 'required|integer',
            'item_name' => 'required|string|max:255',
            'requested_by' => 'nullable|string|max:255',
        ]);

        // Avoid piling up duplicate pending requests for the same item.
        $existing = DeletionRequest::where('item_type', $validated['item_type'])
            ->where('item_id', $validated['item_id'])
            ->where('status', 'Pending')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'A removal request for this item is already pending owner approval.',
                'request' => $existing,
            ], 200);
        }

        $deletionRequest = DeletionRequest::create($validated + ['status' => 'Pending']);

        return response()->json([
            'success' => true,
            'message' => 'Removal request sent for owner approval.',
            'request' => $deletionRequest,
        ], 201);
    }

    /**
     * POST /api/deletion-requests/{id}/approve
     * Owner approves — THIS is where the item actually gets deleted.
     */
    public function approve(Request $request, int $id)
    {
        $deletionRequest = DeletionRequest::findOrFail($id);

        if ($deletionRequest->status !== 'Pending') {
            return response()->json([
                'success' => false,
                'message' => "This request is already {$deletionRequest->status}.",
            ], 409);
        }

        if ($deletionRequest->item_type === 'merch') {
            $item = MerchItem::withCount('sales')->find($deletionRequest->item_id);

            if ($item && $item->sales_count > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot approve — this merch item has sales history. Set stock to 0 instead to retire it.',
                ], 409);
            }

            $item?->delete();
        } else {
            EquipmentItem::find($deletionRequest->item_id)?->delete();
        }

        $deletionRequest->update([
            'status' => 'Approved',
            'reviewed_by' => $request->input('reviewed_by'),
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$deletionRequest->item_name} has been removed.",
            'request' => $deletionRequest,
        ]);
    }

    /**
     * POST /api/deletion-requests/{id}/reject
     * Owner rejects — item stays untouched.
     */
    public function reject(Request $request, int $id)
    {
        $deletionRequest = DeletionRequest::findOrFail($id);

        if ($deletionRequest->status !== 'Pending') {
            return response()->json([
                'success' => false,
                'message' => "This request is already {$deletionRequest->status}.",
            ], 409);
        }

        $deletionRequest->update([
            'status' => 'Rejected',
            'reviewed_by' => $request->input('reviewed_by'),
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Removal request for {$deletionRequest->item_name} was rejected.",
            'request' => $deletionRequest,
        ]);
    }
}
