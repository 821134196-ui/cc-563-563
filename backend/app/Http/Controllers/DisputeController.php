<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\DisputeNote;
use App\Services\CompensationService;
use App\Services\InspectionService;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function __construct(
        private readonly InspectionService $inspections,
        private readonly CompensationService $compensation,
    ) {
    }

    public function index()
    {
        $disputes = Dispute::query()
            ->with(['garment:id,tag,order_id,washer_id', 'garment.washer:id,name', 'compensation'])
            ->latest('id')
            ->paginate(30);

        return response()->json(['data' => $disputes->items(), 'meta' => ['total' => $disputes->total()]]);
    }

    public function show(int $id)
    {
        $dispute = Dispute::query()
            ->with([
                'garment:id,tag,order_id,washer_id,status',
                'garment.washer:id,name',
                'openedBy:id,name,role',
                'notes' => fn ($q) => $q->with('author:id,name,role')->oldest('id'),
                'compensation',
                'compensation.receipt',
                'compensation.requestedBy:id,name,role',
                'compensation.decidedBy:id,name,role',
            ])
            ->findOrFail($id);

        return response()->json(['data' => $dispute]);
    }

    public function requestRecheck(Request $request, int $id)
    {
        $dispute = Dispute::findOrFail($id);
        return response()->json(['data' => $this->inspections->requestRecheck($dispute, $request->user())]);
    }

    public function addNote(Request $request, int $id)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $dispute = Dispute::findOrFail($id);
        return response()->json(['data' => $this->inspections->addNote($dispute, $data['body'], $request->user())]);
    }

    /**
     * 发起赔付申请。
     */
    public function requestCompensation(Request $request, int $id)
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $dispute = Dispute::findOrFail($id);
        $comp = $this->compensation->request($dispute, (int) $data['amount_cents'], $data['reason'], $request->user());
        return response()->json(['data' => $comp], 201);
    }

    /**
     * 店长审批（通过/驳回）。洗护人员调用会被 403 拦截。
     */
    public function decide(Request $request, int $id)
    {
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $dispute = Dispute::findOrFail($id);
        $comp = $dispute->compensation()->firstOrFail();
        return response()->json(['data' => $this->compensation->decide($comp, (bool) $data['approved'], $data['note'] ?? '', $request->user())]);
    }

    /**
     * 执行赔付（模拟网关）。重复提交返回同一张回执，绝不二次付款。
     */
    public function pay(Request $request, int $id)
    {
        $data = $request->validate(['idempotency_key' => ['nullable', 'string', 'max:96']]);
        $dispute = Dispute::findOrFail($id);
        $comp = $dispute->compensation()->firstOrFail();
        $receipt = $this->compensation->pay($comp, $request->user(), $data['idempotency_key'] ?? null);
        // 新建回执 201；幂等重放（重复赔付请求/回执）返回同一记录 200，语义上不是第二次出款
        return response()->json(['data' => $receipt->load('compensation')], $receipt->wasRecentlyCreated ? 201 : 200);
    }
}
