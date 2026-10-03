<?php

namespace App\Http\Controllers;

use App\Http\Resources\Presenter;
use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Services\WashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WashController extends Controller
{
    public function __construct(private readonly WashService $wash)
    {
    }

    /**
     * 洗护人员视角：待洗护 / 洗护中 / 待完检 的衣物。
     */
    public function queue(): JsonResponse
    {
        $garments = Garment::with(['order', 'photos', 'washer'])
            ->whereIn('status', [
                Garment::STATUS_CONFIRMED,
                Garment::STATUS_WASHING,
                Garment::STATUS_READY_INSPECTION,
            ])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'garments' => $garments->map(fn (Garment $g) => [
                ...Presenter::garment($g),
                'order_no' => $g->order->order_no,
                'customer_name' => $g->order->customer_name,
            ]),
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $garment = Garment::findOrFail($id);
        $garment = $this->wash->startWashing($garment, $request->user());

        return response()->json(['garment' => Presenter::garment($garment)]);
    }

    public function finish(Request $request, int $id): JsonResponse
    {
        $garment = Garment::findOrFail($id);
        $garment = $this->wash->markWashed($garment, $request->user());

        return response()->json(['garment' => Presenter::garment($garment)]);
    }

    public function passInspection(Request $request, int $id): JsonResponse
    {
        $garment = Garment::findOrFail($id);
        $garment = $this->wash->passInspection($garment, $request->user());

        return response()->json(['garment' => Presenter::garment($garment)]);
    }

    public function handover(Request $request, int $id): JsonResponse
    {
        $garment = Garment::findOrFail($id);
        $garment = $this->wash->handOver($garment, $request->user());

        return response()->json(['garment' => Presenter::garment($garment)]);
    }

    /**
     * 完检对比视图：同一件衣物的原始预检照片与当前（完检）照片并排。
     */
    public function comparison(int $id): JsonResponse
    {
        $garment = Garment::with(['marks', 'washer', 'order'])
            ->findOrFail($id);

        $intake = GarmentPhoto::where('garment_id', $garment->id)
            ->where('stage', GarmentPhoto::STAGE_INTAKE)
            ->orderBy('id')->get();
        $completion = GarmentPhoto::where('garment_id', $garment->id)
            ->where('stage', GarmentPhoto::STAGE_COMPLETION)
            ->orderBy('id')->get();
        $disputePhotos = GarmentPhoto::where('garment_id', $garment->id)
            ->where('stage', GarmentPhoto::STAGE_DISPUTE)
            ->orderBy('id')->get();

        return response()->json([
            'garment' => Presenter::garment($garment),
            'intake_photos' => $intake->map(fn ($p) => Presenter::photo($p)),
            'completion_photos' => $completion->map(fn ($p) => Presenter::photo($p)),
            'dispute_photos' => $disputePhotos->map(fn ($p) => Presenter::photo($p)),
        ]);
    }
}
