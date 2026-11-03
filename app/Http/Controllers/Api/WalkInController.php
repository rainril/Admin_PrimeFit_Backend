<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalkIn;
use Illuminate\Http\Request;

class WalkInController extends Controller
{
    // GET /api/walk-ins - listahan ng lahat ng walk-in customers
    public function index()
    {
        $walkIns = WalkIn::orderByDesc('created_at')->get();

        $result = $walkIns->map(function ($w) {
            return [
                'id' => (string) $w->id,
                'name' => $w->name,
                'date' => $w->date,
                'checkIn' => $w->check_in,
                'checkOut' => $w->check_out,
                'amount' => (float) $w->amount,
                'method' => $w->method,
                'status' => $w->status,
            ];
        });

        return response()->json($result);
    }

    // POST /api/walk-ins - gumawa ng bagong walk-in record (Manual Check In)
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'checkIn' => 'nullable|string',
            'checkOut' => 'nullable|string',
            'amount' => 'required|numeric',
            'method' => 'required|string',
            'status' => 'required|string',
        ]);

        $walkIn = WalkIn::create([
            'name' => $request->name,
            'date' => now()->toDateString(),
            'check_in' => $request->checkIn,
            'check_out' => $request->checkOut,
            'amount' => $request->amount,
            'method' => $request->method,
            'status' => $request->status,
        ]);

        return response()->json([
            'id' => (string) $walkIn->id,
            'name' => $walkIn->name,
            'date' => $walkIn->date,
            'checkIn' => $walkIn->check_in,
            'checkOut' => $walkIn->check_out,
            'amount' => (float) $walkIn->amount,
            'method' => $walkIn->method,
            'status' => $walkIn->status,
        ], 201);
    }

    // POST /api/walk-ins/{id}/check-out - record a walk-in's check-out once.
    public function checkOut($id)
    {
        $walkIn = WalkIn::whereKey($id)
            ->whereDate('date', now()->toDateString())
            ->first();

        if (!$walkIn) {
            return response()->json(['message' => 'Today\'s walk-in record was not found.'], 404);
        }
        if (!$walkIn->check_in) {
            return response()->json(['message' => 'This walk-in has no check-in time.'], 409);
        }
        if ($walkIn->check_out && !in_array(trim($walkIn->check_out), ['--', 'No check-out'], true)) {
            return response()->json(['message' => 'This walk-in is already checked out.'], 409);
        }

        $walkIn->check_out = now()->format('g:i A');
        $walkIn->save();

        return response()->json([
            'message' => 'Walk-in checked out successfully.',
            'id' => (string) $walkIn->id,
            'checkOut' => $walkIn->check_out,
        ]);
    }

    // PUT /api/walk-ins/{id} - i-update ang walk-in record
    public function update(Request $request, $id)
    {
        $walkIn = WalkIn::findOrFail($id);

        $walkIn->update($request->only([
            'name', 'check_in', 'check_out', 'amount', 'method', 'status',
        ]));

        return response()->json(['message' => 'Updated successfully']);
    }

    // DELETE /api/walk-ins/{id}
    public function destroy($id)
    {
        WalkIn::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
