<?php

namespace App\Http\Controllers;

use App\Services\DisputeService;
use App\Services\MockPaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 模拟支付网关回执接口。真实系统里由支付平台服务端回调，
 * 这里以签名校验 + 幂等键模拟；重复回执不会二次付款。
 */
class GatewayCallbackController extends Controller
{
    public function __construct(private readonly DisputeService $disputes)
    {
    }

    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receipt_no' => ['required', 'string', 'max:80'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'status' => ['required', 'string', 'max:30'],
            'signature' => ['required', 'string', 'size:64'],
        ]);

        $signBase = [
            'idempotency_key' => $data['idempotency_key'],
            'receipt_no' => $data['receipt_no'],
            'status' => $data['status'],
        ];
        if (! hash_equals(MockPaymentGateway::sign($signBase), $data['signature'])) {
            return response()->json(['message' => '回执签名无效', 'code' => 'bad_signature'], 401);
        }

        try {
            $receipt = $this->disputes->recordGatewayCallback($data);
        } catch (\App\Exceptions\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                'duplicate' => $e->errorCode === 'duplicate_callback',
            ], $e->statusCode);
        }

        return response()->json([
            'ok' => true,
            'receipt_no' => $receipt->receipt_no,
            'message' => '回执已受理',
        ]);
    }
}
