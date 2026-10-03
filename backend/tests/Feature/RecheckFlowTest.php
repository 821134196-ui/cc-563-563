<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\Garment;
use App\Models\Inspection;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecheckFlowTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'rt-' . $user->id;
        $user->update(['api_token' => hash('sha256', $token)]);
        return ['Authorization' => "Bearer $token"];
    }

    /**
     * 复检确认“仍有差异”必须复用同一条争议，不得新开争议单。
     */
    public function test_recheck_discrepancy_reuses_existing_dispute(): void
    {
        Storage::fake('local');
        $washer = User::factory()->create(['role' => User::ROLE_WASHER]);
        $order = Order::create(['code' => 'ODR1', 'customer_name' => 'x', 'status' => 'in_care']);
        $garment = Garment::create([
            'order_id' => $order->id, 'tag' => 'RC-1', 'status' => 'disputed',
            'washer_id' => $washer->id, 'intake_locked_at' => now(),
        ]);
        Photo::create(['garment_id' => $garment->id, 'stage' => 'intake', 'disk_path' => 'a', 'sha256' => 'h-in']);
        Photo::create(['garment_id' => $garment->id, 'stage' => 'postwash', 'disk_path' => 'b', 'sha256' => 'h-post']);
        $dispute = Dispute::create([
            'garment_id' => $garment->id, 'opened_by' => $washer->id,
            'description' => '差异', 'status' => 'rechecking',
        ]);
        Photo::create(['garment_id' => $garment->id, 'stage' => 'recheck', 'disk_path' => 'c', 'sha256' => 'h-rc']);

        $this->postJson("/api/garments/{$garment->id}/inspections", [
            'result' => 'discrepancy',
            'kind' => 'recheck',
            'notes' => '复检仍见裂口',
            'new_marks' => [['kind' => 'damage', 'location' => '右肩', 'description' => '裂口确认']],
        ], $this->headers($washer))->assertCreated();

        // 仍然只有一条争议，且进入赔偿协商
        $this->assertSame(1, Dispute::count());
        $this->assertSame(Dispute::STATUS_NEGOTIATING, $dispute->fresh()->status);
        $this->assertSame(Garment::STATUS_DISPUTED, $garment->fresh()->status);
    }

    /**
     * 复检“核对一致”了结争议与衣物。
     */
    public function test_recheck_consistent_closes_dispute(): void
    {
        Storage::fake('local');
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $order = Order::create(['code' => 'ODR2', 'customer_name' => 'y', 'status' => 'in_care']);
        $garment = Garment::create([
            'order_id' => $order->id, 'tag' => 'RC-2', 'status' => 'disputed', 'intake_locked_at' => now(),
        ]);
        Photo::create(['garment_id' => $garment->id, 'stage' => 'intake', 'disk_path' => 'a', 'sha256' => 'h-in2']);
        Photo::create(['garment_id' => $garment->id, 'stage' => 'recheck', 'disk_path' => 'c', 'sha256' => 'h-rc2']);
        $dispute = Dispute::create([
            'garment_id' => $garment->id, 'opened_by' => $cashier->id,
            'description' => '疑似褪色', 'status' => 'rechecking',
        ]);

        $this->postJson("/api/garments/{$garment->id}/inspections", [
            'result' => 'consistent',
            'kind' => 'recheck',
            'notes' => '复检为正常色差，无损坏',
        ], $this->headers($cashier))->assertCreated();

        $this->assertSame(Dispute::STATUS_CLOSED, $dispute->fresh()->status);
        $this->assertSame(Garment::STATUS_DONE, $garment->fresh()->status);
    }
}
