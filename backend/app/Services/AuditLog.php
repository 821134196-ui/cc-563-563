<?php

namespace App\Services;

use App\Models\OrderEvent;

class AuditLog
{
    public static function record(
        string $action,
        ?int $actorId = null,
        ?int $orderId = null,
        ?int $garmentId = null,
        array $payload = [],
    ): void {
        OrderEvent::create([
            'action' => $action,
            'actor_id' => $actorId,
            'order_id' => $orderId,
            'garment_id' => $garmentId,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);
    }
}
