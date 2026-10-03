<?php

namespace App\Http\Controllers;

use App\Models\Garment;
use App\Models\Order;
use App\Services\IntakeService;
use Illuminate\Http\Request;

class GarmentController extends Controller
{
    public function __construct(private readonly IntakeService $intake)
    {
    }

    /**
     * 洗护看板：已确认后的全部衣物（待洗护/洗护中/待核对/争议）。
     */
    public function board()
    {
        $garments = Garment::query()
            ->whereIn('status', [
                Garment::STATUS_CONFIRMED,
                Garment::STATUS_WASHING,
                Garment::STATUS_WASHED,
                Garment::STATUS_DISPUTED,
            ])
            ->with(['washer:id,name', 'order:id,code,customer_name'])
            ->orderByRaw("CASE status
                WHEN 'disputed' THEN 0
                WHEN 'washed' THEN 1
                WHEN 'washing' THEN 2
                WHEN 'confirmed' THEN 3 END")
            ->orderBy('id')
            ->get()
            ->map(fn (Garment $g) => [
                'id' => $g->id,
                'tag' => $g->tag,
                'category' => $g->category,
                'color' => $g->color,
                'status' => $g->status,
                'order_code' => $g->order?->code,
                'customer_name' => $g->order?->customer_name,
                'washer' => $g->washer ? ['id' => $g->washer->id, 'name' => $g->washer->name] : null,
            ]);

        return response()->json(['data' => $garments]);
    }

    public function store(Request $request, int $orderId)
    {
        $order = Order::findOrFail($orderId);
        $garment = $this->intake->addGarment($order, $request->all());
        return response()->json(['data' => $garment], 201);
    }

    public function show(int $id)
    {
        $garment = Garment::query()
            ->with([
                'order:id,code,customer_name,status',
                'washer:id,name',
                'photos' => fn ($q) => $q->orderBy('stage')->orderBy('id'),
                'marks' => fn ($q) => $q->orderBy('id'),
                'inspections' => fn ($q) => $q->with('inspector:id,name,role')->latest('id'),
                'disputes' => fn ($q) => $q->with(['compensation', 'notes.author:id,name,role', 'openedBy:id,name'])->latest('id'),
            ])
            ->findOrFail($id);

        return response()->json(['data' => $garment]);
    }
}
