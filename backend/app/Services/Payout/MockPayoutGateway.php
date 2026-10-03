<?php

namespace App\Services\Payout;

/**
 * 模拟赔付回执网关。
 * 真实项目里这里会是支付/理赔接口；当前实现本地生成回执。
 * 关键约定：idempotencyKey 相同的请求必须返回同一笔回执，绝不二次出款。
 */
class MockPayoutGateway
{
    public function pay(string $idempotencyKey, int $amountCents, string $reference): array
    {
        return [
            'ok' => true,
            'gateway' => 'mock',
            'receipt_no' => $this->generateReceiptNo($idempotencyKey),
            'idempotency_key' => $idempotencyKey,
            'amount_cents' => $amountCents,
            'reference' => $reference,
            'currency' => 'CNY',
            'paid_at' => now()->toIso8601String(),
            'mock' => true,
        ];
    }

    private function generateReceiptNo(string $idempotencyKey): string
    {
        // 由幂等键 + 门店盐值确定性生成：同一赔付的重复请求拿到同一回执号，
        // 不同门店/部署之间不会撞号
        $salt = (string) config('app.key');
        return 'RC' . strtoupper(substr(hash('sha256', $salt . '|' . $idempotencyKey), 0, 18));
    }
}
