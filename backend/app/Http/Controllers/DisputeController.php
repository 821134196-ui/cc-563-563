<?php

namespace App\Http\Controllers;

use App\Http\Resources\Presenter;
use App\Models\Compensation;
use App\Models\Dispute;
use App\Models\Garment;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $query = Dispute::query()->with(['garment', 'order', 'compensation.receipt', 'reviews']);
        if ($status !== '') {
            $query->where('status', $status);
        }
        $list = $query->orderByDesc('id')->limit(200)->get();

        return response()->json(['disputes' => $list->map(fn (Dispute $d) => Presenter::dispute($d))]);
    }

    public function show(int $id): JsonResponse
    {
        $dispute = Dispute::with(['garment.photos', 'garment.marks', 'garment.washer', 'order', 'reviews.reviewer', 'compensation.receipt', 'opener'])
            ->findOrFail($id);

        return response()->json(['dispute' => Presenter::dispute($dispute)]);
    }

    public function open(Request $request, int $garmentId): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:new_damage,new_stain,missing,other'],
            'description' => ['required', 'string', 'max:1000'],
            'requested_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $garment = Garment::findOrFail($garmentId);
        $dispute = $this->disputes->openDispute($garment, $data, $request->user());

        return response()->json(['dispute' => Presenter::dispute($dispute->load(['reviews.reviewer', 'compensation', 'garment', 'opener']))], 201);
    }

    public function startRecheck(Request $request, int $id): JsonResponse
    {
        $dispute = Dispute::findOrFail($id);
        $dispute = $this->disputes->startRecheck($dispute, $request->user());

        return response()->json(['dispute' => Presenter::dispute($dispute)]);
    }

    public function submitReview(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'result' => ['required', 'in:confirmed,not_found,inconclusive'],
            'finding' => ['required', 'string', 'max:2000'],
            'evidence_photo_ids' => ['nullable', 'array'],
            'evidence_photo_ids.*' => ['integer'],
        ]);

        $dispute = Dispute::findOrFail($id);
        $review = $this->disputes->submitReview($dispute, $data, $request->user());

        return response()->json(['review' => $review, 'dispute' => Presenter::dispute($dispute->fresh())], 201);
    }

    public function propose(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        $dispute = Dispute::findOrFail($id);
        $comp = $this->disputes->proposeCompensation($dispute, $data['amount'], $request->user());

        return response()->json(['compensation' => Presenter::compensation($comp->load('receipt'))], 201);
    }

    public function repropose(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        $comp = Compensation::findOrFail($id);
        $comp = $this->disputes->reproposeCompensation($comp, $data['amount'], $request->user());

        return response()->json(['compensation' => Presenter::compensation($comp->fresh('receipt'))]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $comp = Compensation::with('garment')->findOrFail($id);
        $comp = $this->disputes->approveCompensation($comp, $data['approved'], $data['note'] ?? null, $request->user());

        return response()->json(['compensation' => Presenter::compensation($comp->fresh('receipt'))]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $comp = Compensation::with('receipt')->findOrFail($id);
        $comp = $this->disputes->pay($comp, $request->user());

        return response()->json(['compensation' => Presenter::compensation($comp)]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);
        $dispute = Dispute::with('compensation')->findOrFail($id);
        $dispute = $this->disputes->closeDispute($dispute, $data['note'], $request->user());

        return response()->json(['dispute' => Presenter::dispute($dispute)]);
    }
}
