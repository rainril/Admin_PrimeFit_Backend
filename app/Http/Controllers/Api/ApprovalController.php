<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\WalkIn;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    // GET /api/approval-requests?status=pending
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');
        $requests = ApprovalRequest::where('status', $status)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests);
    }

    // POST /api/approval-requests
    // Staff calls this instead of directly editing/deleting -- creates a
    // pending request that Owner must approve before anything actually
    // changes in the walk_ins table.
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:edit,delete',
            'target_type' => 'required|string',
            'target_id' => 'required|integer',
            'payload' => 'nullable|array',
            'requested_by' => 'nullable|string',
        ]);

        $original = null;
        if ($request->target_type === 'walk_in') {
            $walkIn = WalkIn::find($request->target_id);
            if ($walkIn) {
                $original = $walkIn->toArray();
            }
        }

        $approval = ApprovalRequest::create([
            'type' => $request->type,
            'target_type' => $request->target_type,
            'target_id' => $request->target_id,
            'payload' => $request->payload,
            'original' => $original,
            'requested_by' => $request->requested_by,
            'status' => 'pending',
        ]);

        return response()->json($approval, 201);
    }

    // POST /api/approval-requests/{id}/approve
    // Only actually applies the edit/delete once Owner approves.
    public function approve($id)
    {
        $approval = ApprovalRequest::findOrFail($id);

        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'This request was already reviewed.'], 422);
        }

        if ($approval->target_type === 'walk_in') {
            $walkIn = WalkIn::find($approval->target_id);
            if ($walkIn) {
                if ($approval->type === 'edit') {
                    $walkIn->update($approval->payload ?? []);
                } elseif ($approval->type === 'delete') {
                    $walkIn->delete();
                }
            }
        }

        $approval->status = 'approved';
        $approval->save();

        return response()->json(['message' => 'Approved.']);
    }

    // POST /api/approval-requests/{id}/reject
    public function reject($id)
    {
        $approval = ApprovalRequest::findOrFail($id);

        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'This request was already reviewed.'], 422);
        }

        $approval->status = 'rejected';
        $approval->save();

        return response()->json(['message' => 'Rejected.']);
    }
}