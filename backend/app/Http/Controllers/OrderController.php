<?php

namespace App\Http\Controllers;

use App\Models\Garment;
use App\Models\Order;
use App\Services\IntakeService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly IntakeService $intake)
    {
    }

    /**
     * 收件台数据：订单列表 + 订单数 / 实际衣物件数统计。
     */
    public function index(Request $request)
    {
        $orders = Order::query()
            ->withCount('garments')
            ->with('creator:id,name')
            ->latest('id')
            ->paginate(20);

        $stats = [
            // 订单数（按订单计）
            'order_count' => Order::count(),
            // 实际衣物件数（按件计）
            'garment_count' => Garment::count(),
            'active_order_count' => Order::whereIn('status', [Order::STATUS_INTAKE, Order::STATUS_PAUSED, Order::STATUS_IN_CARE])->count(),
            'paused_order_count' => Order::where('status', Order::STATUS_PAUSED)->count(),
            'disputed_garment_count' => Garment::where('status', Garment::STATUS_DISPUTED)->count(),
        ];

        return response()->json(['data' => $orders->items(), 'meta' => [
            'stats' => $stats,
            'total' => $orders->total(),
            'page' => $orders->currentPage(),
        ]]);
    }

    public function store(Request $request)
    {
        $order = $this->intake->createOrder($request->all(), $request->user());
        return response()->json(['data' => $this->detail($order->id)->getData()->data], 201);
    }

    public function show(int $id)
    {
        return $this->detail($id);
    }

    public function pause(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $order = Order::findOrFail($id);
        return response()->json(['data' => $this->intake->pauseOrder($order, $data['reason'], $data['note'] ?? null, $request->user())]);
    }

    public function resume(Request $request, int $id)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $order = Order::findOrFail($id);
        return response()->json(['data' => $this->intake->resumeOrder($order, $data['note'], $request->user())]);
    }

    public function confirm(Request $request, int $id)
    {
        $order = Order::findOrFail($id);
        $updated = $this->intake->confirmByCustomer($order, $request->user());
        return response()->json(['data' => $this->detail($updated->id)->getData()->data]);
    }

    private function detail(int $id)
    {
        $order = Order::query()
            ->with([
                'creator:id,name,role',
                'garments' => fn ($q) => $q->withCount(['disputes as active_disputes_count' => fn ($d) => $d->whereNotIn('status', ['closed', 'rejected'])]),
                'garments.intakePhotos:id,garment_id,stage,sha256,created_at',
                'garments.marks:id,garment_id,kind,location,description,stage',
                'garments.washer:id,name',
                'events' => fn ($q) => $q->with('actor:id,name,role')->latest('id')->limit(50),
            ])
            ->withCount('garments')
            ->findOrFail($id);

        return response()->json(['data' => $order]);
    }
}
