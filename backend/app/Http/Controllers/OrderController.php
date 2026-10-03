<?php

namespace App\Http\Controllers;

use App\Http\Resources\Presenter;
use App\Models\Order;
use App\Services\IntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly IntakeService $intake)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $query = Order::query()->with('garments')->withCount('garments');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('id')->limit(100)->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $o) => Presenter::order($o)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $order = $this->intake->createOrder($data, $request->user());

        return response()->json(['order' => Presenter::order($order)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::with(['garments.marks', 'garments.photos.uploadedBy', 'garments.pocketItems', 'garments.washer', 'pausedByUser'])
            ->findOrFail($id);

        return response()->json(['order' => Presenter::order($order)]);
    }

    public function submitConfirmation(int $id, Request $request): JsonResponse
    {
        $order = Order::findOrFail($id);
        $order = $this->intake->submitForConfirmation($order, $request->user());

        return response()->json(['order' => Presenter::order($order)]);
    }

    public function confirm(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'signature_name' => ['required', 'string', 'max:100'],
        ]);
        $order = Order::findOrFail($id);
        $order = $this->intake->confirmByCustomer($order, $data['signature_name'], $request->user());

        return response()->json(['order' => Presenter::order($order)]);
    }

    public function refuse(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $order = Order::findOrFail($id);
        $order = $this->intake->refuseSignature($order, $data['note'] ?? null, $request->user());

        return response()->json(['order' => Presenter::order($order)]);
    }

    public function pause(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'in:'.implode(',', array_keys(Order::PAUSE_REASONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $order = Order::findOrFail($id);
        $order = $this->intake->pauseOrder($order, $data['reason'], $data['note'] ?? '店员手动暂停', $request->user());

        return response()->json(['order' => Presenter::order($order)]);
    }

    public function resume(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $order = Order::findOrFail($id);
        $order = $this->intake->resumeOrder($order, $data['note'] ?? '暂停事项已处理', $request->user());

        return response()->json(['order' => Presenter::order($order)]);
    }

    public function events(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $events = $order->events()->with(['actor', 'garment'])->limit(200)->get();

        return response()->json(['events' => $events->map(fn ($e) => Presenter::event($e))]);
    }
}
