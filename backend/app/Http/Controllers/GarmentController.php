<?php

namespace App\Http\Controllers;

use App\Http\Resources\Presenter;
use App\Models\Garment;
use App\Models\PocketItem;
use App\Services\IntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GarmentController extends Controller
{
    public function __construct(private readonly IntakeService $intake)
    {
    }

    public function store(Request $request, int $orderId): JsonResponse
    {
        $data = $request->validate([
            'tag_no' => ['required', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:30'],
            'brand' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'care_labels' => ['array'],
            'care_labels.*' => ['string', 'max:40'],
        ]);

        $order = \App\Models\Order::findOrFail($orderId);
        $garment = $this->intake->registerGarment($order, $data, $request->user());

        return response()->json([
            'garment' => Presenter::garment($garment->load('photos', 'marks', 'pocketItems')),
            'order' => Presenter::order($order->fresh('garments')),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $garment = Garment::with(['marks', 'photos', 'pocketItems', 'washer', 'order'])->findOrFail($id);

        return response()->json(['garment' => Presenter::garment($garment)]);
    }

    public function addMark(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:stain,damage'],
            'label' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'x' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'y' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $garment = Garment::findOrFail($id);
        $mark = $this->intake->addMark($garment, [
            'kind' => $data['kind'],
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
            'x_permille' => $data['x'] ?? 0,
            'y_permille' => $data['y'] ?? 0,
        ], $request->user());

        return response()->json(['mark' => $mark], 201);
    }

    public function reportPocketItem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $garment = Garment::with('order')->findOrFail($id);
        $item = $this->intake->reportPocketItem($garment, $data, $request->user());

        return response()->json([
            'pocket_item' => $item,
            'order' => Presenter::order($garment->order->fresh()->load('garments')),
        ], 201);
    }

    public function returnPocketItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $garment = Garment::with('order')->findOrFail($id);
        $item = $garment->pocketItems()->findOrFail($itemId);
        $this->intake->returnPocketItem($item, $request->user());

        return response()->json([
            'pocket_item' => $item->fresh(),
            'order' => Presenter::order($garment->order->fresh()->load('garments')),
        ]);
    }
}
