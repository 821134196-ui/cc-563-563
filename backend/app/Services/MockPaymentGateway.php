<?php

namespace App\Services;

use App\Models\MockGatewayTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 模拟赔付支付网关：
 * - charge() 以幂等键发起，相同键/相同赔付单的重复请求只返回首次结果，不二次扣款；
 * - handleCallback() 处理回执，重复回执只累计回调次数，不触发二次入账。
 */
class MockPaymentGateway
{
    public const SIGNING_SECRET = 'mock-gateway-shared-secret';

    public function charge(string $paymentRef, int $amount, string $idempotencyKey): MockGatewayTransaction
    {
        return DB::transaction(function () use ($paymentRef, $idempotencyKey, $amount) {
            // 幂等键重复：直接返回原交易
            $existing = MockGatewayTransaction::where('idempotency_key', $idempotencyKey)
                ->orWhere('payment_ref', $paymentRef)
                ->first();

            if ($existing) {
                return $existing;
            }

            return MockGatewayTransaction::create([
                'transaction_no' => 'TXN'.now()->format('ymdHis').strtoupper(Str::random(6)),
                'payment_ref' => $paymentRef,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => 'CNY',
                'status' => 'success',
                'callback_count' => 0,
                'raw' => ['note' => '模拟网关受理成功，等待回执'],
            ]);
        });
    }

    /**
     * 处理网关回执。
     *
     * @return array{transaction: MockGatewayTransaction, duplicate: bool}
     *               duplicate=true 表示这是重复回执，业务侧不得再入账
     */
    public function handleCallback(array $payload): array
    {
        $key = $payload['idempotency_key'] ?? '';
        $receiptNo = $payload['receipt_no'] ?? '';

        return DB::transaction(function () use ($key, $receiptNo, $payload) {
            // 幂等键优先，回执号其次（模拟环境回执号即交易号）
            $txn = MockGatewayTransaction::where('idempotency_key', $key)->first();
            if (! $txn && $receiptNo !== '') {
                $txn = MockGatewayTransaction::where('transaction_no', $receiptNo)->first();
            }

            if (! $txn) {
                return ['transaction' => null, 'duplicate' => false, 'unknown' => true];
            }

            $firstCallback = $txn->callback_count === 0;
            $txn->increment('callback_count');
            $txn->forceFill([
                'raw' => array_merge($txn->raw ?? [], ['last_callback' => $payload]),
            ])->save();

            return ['transaction' => $txn->fresh(), 'duplicate' => ! $firstCallback, 'unknown' => false];
        });
    }

    public static function sign(array $payload): string
    {
        ksort($payload);
        return hash_hmac('sha256', http_build_query($payload), self::SIGNING_SECRET);
    }
}
