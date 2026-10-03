<?php

namespace App\Support;

use App\Models\OrderEvent;

class EventLog
{
    public static function record(
        string $type,
        ?int $orderId = null,
        ?int $garmentId = null,
        ?int $disputeId = null,
        ?int $actorId = null,
        array $payload = [],
    ): OrderEvent {
        return OrderEvent::create([
            'event_type' => $type,
            'order_id' => $orderId,
            'garment_id' => $garmentId,
            'dispute_id' => $disputeId,
            'actor_id' => $actorId,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);
    }
}
