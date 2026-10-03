<?php

namespace App\Http\Controllers;

use App\Models\Garment;
use App\Services\InspectionService;
use Illuminate\Http\Request;

class CareController extends Controller
{
    public function __construct(private readonly InspectionService $inspections)
    {
    }

    public function start(Request $request, int $garmentId)
    {
        $garment = Garment::findOrFail($garmentId);
        return response()->json(['data' => $this->inspections->startWashing($garment, $request->user())]);
    }

    public function finish(Request $request, int $garmentId)
    {
        $garment = Garment::findOrFail($garmentId);
        return response()->json(['data' => $this->inspections->finishWashing($garment, $request->user())]);
    }

    /**
     * 完工核对。body:
     *  result: consistent|discrepancy
     *  notes?: string
     *  new_marks?: [{kind, location, description}]
     *  kind?: postwash（默认）|recheck
     */
    public function inspect(Request $request, int $garmentId)
    {
        $data = $request->validate([
            'result' => ['required', 'string', 'in:consistent,discrepancy'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', 'in:postwash,recheck'],
            'new_marks' => ['array'],
            'new_marks.*.kind' => ['required', 'string', 'in:stain,damage'],
            'new_marks.*.location' => ['required', 'string', 'max:100'],
            'new_marks.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $garment = Garment::findOrFail($garmentId);
        $inspection = $this->inspections->inspect(
            $garment,
            $data['result'],
            $data['notes'] ?? '',
            $data['new_marks'] ?? null,
            $data['kind'] ?? 'postwash',
            $request->user(),
        );

        return response()->json(['data' => $inspection->load('inspector:id,name,role')], 201);
    }
}
