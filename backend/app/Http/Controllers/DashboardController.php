<?php

namespace App\Http\Controllers;

use App\Models\Garment;
use App\Models\Order;
use App\Models\Compensation;
use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * 收件台：订单数与实际衣物件数同时展示。
     */
    public function intakeStats(): JsonResponse
    {
        $today = now()->startOfDay();

        $orders = Order::query();
        $garments = Garment::query();

        return response()->json([
            'orders_total' => Order::count(),
            'orders_today' => Order::where('created_at', '>=', $today)->count(),
            'orders_in_intake' => Order::whereIn('status', [Order::STATUS_INTAKE, Order::STATUS_AWAITING_CONFIRMATION])->count(),
            'orders_paused' => Order::where('status', Order::STATUS_PAUSED)->count(),
            'orders_in_wash' => Order::where('status', Order::STATUS_IN_WASH)->count(),
            'orders_completed' => Order::where('status', Order::STATUS_COMPLETED)->count(),

            // 实际衣物件数（与订单数并列，避免“一单多件”计数混淆）
            'garments_total' => Garment::count(),
            'garments_today' => Garment::where('created_at', '>=', $today)->count(),
            'garments_by_status' => Garment::query()
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status'),

            'disputes_open' => Dispute::whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_RECHECKING, Dispute::STATUS_NEGOTIATING])->count(),
            'compensations_pending' => Compensation::whereIn('status', [Compensation::STATUS_REQUESTED, Compensation::STATUS_APPROVED])->count(),
            'compensations_paid_total_cents' => Compensation::where('status', Compensation::STATUS_PAID)->sum('amount'),
        ]);
    }

    public function pausedOrders(): JsonResponse
    {
        $orders = Order::with(['garments.pocketItems', 'pausedByUser'])
            ->where('status', Order::STATUS_PAUSED)
            ->orderBy('paused_at')
            ->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'customer_name' => $o->customer_name,
                'pause_reason' => $o->pause_reason,
                'pause_reason_label' => Order::PAUSE_REASONS[$o->pause_reason] ?? $o->pause_reason,
                'pause_note' => $o->pause_note,
                'paused_at' => $o->paused_at?->toIso8601String(),
                'paused_by' => $o->pausedByUser?->name,
                'open_pocket_items' => $o->garments->flatMap->openPocketItems->values(),
                'garment_count' => $o->garments->count(),
            ]),
        ]);
    }
}
