<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchSale;
use App\Models\MerchItem; // ASSUMPTION: rename to your actual merch/inventory item model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MerchSaleController extends Controller
{
    /**
     * GET /api/merch-sales?status=Pending
     * List sales, optionally filtered by status. Used for both the
     * "Pending Sales" list and a "Recent Sales" history table.
     */
    public function index(Request $request)
    {
        $query = MerchSale::query()->orderByDesc('date')->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $sales = $query->limit(200)->get();

        return response()->json([
            'success' => true,
            'sales' => $sales,
        ]);
    }

    /**
     * POST /api/merch-sales
     * Step 1: record the sale as "Pending". Does NOT touch stock yet.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_id' => 'required|integer|exists:merch_items,id', // ASSUMPTION: table name
            'quantity' => 'required|integer|min:1',
            'buyer_name' => 'nullable|string|max:255',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $item = MerchItem::findOrFail($validated['item_id']);

        // Sanity check against current stock so staff gets immediate feedback,
        // even though the real deduction only happens on confirm().
        if ($validated['quantity'] > $item->stock) {
            throw ValidationException::withMessages([
                'quantity' => "Only {$item->stock} unit(s) of {$item->name} left in stock.",
            ]);
        }

        $sale = MerchSale::create([
            'item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => $validated['quantity'],
            'unit_price' => $item->price,
            'total_amount' => $item->price * $validated['quantity'],
            'buyer_name' => $validated['buyer_name'] ?? 'Walk-in',
            'payment_method' => $validated['payment_method'] ?? 'Cash',
            'status' => 'Pending',
            'recorded_by_admin_id' => optional($request->user())->id, // null if no auth guard wired here
            'date' => now()->toDateString(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sale recorded as pending. Confirm it to deduct stock.',
            'sale' => $sale,
        ], 201);
    }

    /**
     * POST /api/merch-sales/{id}/confirm
     * Step 2: staff confirms the sale. THIS is where stock is deducted and
     * the item's sold/revenue counters update. Wrapped in a transaction with
     * a row lock so two staff confirming near-simultaneously can't oversell
     * the same last unit.
     */
    public function confirm(int $id)
    {
        return DB::transaction(function () use ($id) {
            $sale = MerchSale::lockForUpdate()->findOrFail($id);

            if ($sale->status !== 'Pending') {
                return response()->json([
                    'success' => false,
                    'message' => "This sale is already {$sale->status}, it can't be confirmed again.",
                ], 409);
            }

            $item = MerchItem::lockForUpdate()->findOrFail($sale->item_id);

            if ($sale->quantity > $item->stock) {
                return response()->json([
                    'success' => false,
                    'message' => "Not enough stock left ({$item->stock} available, {$sale->quantity} requested). Void this sale instead.",
                ], 409);
            }

            $item->stock -= $sale->quantity;
            $item->sold += $sale->quantity;
            $item->revenue += $sale->total_amount;
            $item->save();

            $sale->status = 'Completed';
            $sale->confirmed_at = now();
            $sale->save();

            return response()->json([
                'success' => true,
                'message' => 'Sale confirmed. Stock and revenue updated.',
                'sale' => $sale,
                'item' => $item,
            ]);
        });
    }

    /**
     * POST /api/merch-sales/{id}/void
     * Cancels a pending sale. No stock effect either way.
     */
    public function void(int $id)
    {
        $sale = MerchSale::findOrFail($id);

        if ($sale->status !== 'Pending') {
            return response()->json([
                'success' => false,
                'message' => "This sale is already {$sale->status}, it can't be voided.",
            ], 409);
        }

        $sale->status = 'Voided';
        $sale->save();

        return response()->json([
            'success' => true,
            'message' => 'Sale voided.',
            'sale' => $sale,
        ]);
    }

    /**
     * GET /api/merch-sales/stats
     * Powers "Total Merch Revenue" / "Total Units Sold" cards. Only counts
     * Completed sales — Pending and Voided never affect these numbers.
     */
    public function stats()
    {
        $completed = MerchSale::where('status', 'Completed');

        $allTimeRevenue = (clone $completed)->sum('total_amount');
        $allTimeUnits = (clone $completed)->sum('quantity');

        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();

        $yearRevenue = (clone $completed)->whereBetween('date', [$yearStart, $yearEnd])->sum('total_amount');
        $yearUnits = (clone $completed)->whereBetween('date', [$yearStart, $yearEnd])->sum('quantity');

        $pendingCount = MerchSale::where('status', 'Pending')->count();

        return response()->json([
            'success' => true,
            'allTimeRevenue' => (float) $allTimeRevenue,
            'allTimeUnitsSold' => (int) $allTimeUnits,
            'yearRevenue' => (float) $yearRevenue,
            'yearUnitsSold' => (int) $yearUnits,
            'pendingCount' => $pendingCount,
        ]);
    }

    /**
     * GET /api/merch-sales/revenue-analytics?period=daily|weekly|monthly|annual
     * Powers the Merch Revenue chart on the Inventory > Revenue Statistics
     * tab. Only sums Completed sales (same rule as stats() above) so the
     * chart matches the real, confirmed revenue — not pending sales.
     */
    public function revenueAnalytics(Request $request)
    {
        $period = $request->query('period', 'daily'); // daily | weekly | monthly | annual

        $labels = [];
        $values = [];

        switch ($period) {
            case 'weekly':
                // Last 8 weeks
                for ($i = 7; $i >= 0; $i--) {
                    $weekStart = now()->subWeeks($i)->startOfWeek();
                    $weekEnd = now()->subWeeks($i)->endOfWeek();
                    $labels[] = $weekStart->format('M d');
                    $values[] = (float) MerchSale::where('status', 'Completed')
                        ->whereBetween('created_at', [$weekStart, $weekEnd])
                        ->sum('total_amount');
                }
                break;

            case 'monthly':
                // Last 12 months
                for ($i = 11; $i >= 0; $i--) {
                    $month = now()->subMonths($i);
                    $labels[] = $month->format('M');
                    $values[] = (float) MerchSale::where('status', 'Completed')
                        ->whereYear('created_at', $month->year)
                        ->whereMonth('created_at', $month->month)
                        ->sum('total_amount');
                }
                break;

            case 'annual':
                // Last 5 years
                for ($i = 4; $i >= 0; $i--) {
                    $year = now()->subYears($i)->year;
                    $labels[] = (string) $year;
                    $values[] = (float) MerchSale::where('status', 'Completed')
                        ->whereYear('created_at', $year)
                        ->sum('total_amount');
                }
                break;

            case 'daily':
            default:
                // Last 7 days
                for ($i = 6; $i >= 0; $i--) {
                    $day = now()->subDays($i);
                    $labels[] = $day->format('D');
                    $values[] = (float) MerchSale::where('status', 'Completed')
                        ->whereDate('created_at', $day->toDateString())
                        ->sum('total_amount');
                }
                break;
        }

        return response()->json([
            'data' => [
                'labels' => $labels,
                'values' => $values,
            ],
        ]);
    }
}
