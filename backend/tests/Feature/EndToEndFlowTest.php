<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\Order;
use App\Models\User;

/**
 * 端到端冒烟：收件台统计、预检→确认→洗护→完检→交付→订单完成。
 */
class EndToEndFlowTest extends DryCleanTestCase
{
    public function test_full_happy_path_with_two_garments(): void
    {
        $clerk = $this->user(User::ROLE_CLERK, '王小柜');
        $washer = $this->user(User::ROLE_WASHER, '李洗护');
        $manager = $this->user(User::ROLE_MANAGER, '赵经理');

        // 收件台：订单数与实际件数并列
        $stats = $this->getJson('/api/dashboard/intake-stats', $this->headers($clerk))->json();
        $this->assertArrayHasKey('orders_total', $stats);
        $this->assertArrayHasKey('garments_total', $stats);

        $order = $this->postJson('/api/orders', ['customer_name' => '张女士', 'customer_phone' => '13800000000'],
            $this->headers($clerk))->json('order');

        foreach (['G-E2E-1', 'G-E2E-2'] as $i => $tag) {
            $this->postJson("/api/orders/{$order['id']}/garments", [
                'tag_no' => $tag, 'category' => $i === 0 ? '大衣' : '围巾', 'care_labels' => [],
            ], $this->headers($clerk))->assertCreated();

            $garmentId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
                ->json("order.garments.{$i}.id");

            $this->postJson("/api/garments/{$garmentId}/photos",
                ['photo' => $this->jpeg('intake-'.$tag), 'stage' => 'intake'], $this->headers($clerk))
                ->assertCreated();
            $this->postJson("/api/garments/{$garmentId}/marks", [
                'kind' => 'stain', 'label' => '旧渍', 'x' => 100, 'y' => 100,
            ], $this->headers($clerk))->assertCreated();
        }

        // 没有照片时不能提交确认（这里两件都有，应通过）
        $this->postJson("/api/orders/{$order['id']}/submit-confirmation", [], $this->headers($clerk))->assertOk();
        $this->postJson("/api/orders/{$order['id']}/confirm",
            ['signature_name' => '张女士'], $this->headers($clerk))->assertOk();

        // 两件均完成洗护、完检、交付
        $garments = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))->json('order.garments');
        foreach ($garments as $g) {
            $this->assertTrue($g['intake_locked']);

            $this->postJson("/api/garments/{$g['id']}/wash/start", [], $this->headers($washer))->assertOk();
            $this->postJson("/api/garments/{$g['id']}/wash/finish", [], $this->headers($washer))->assertOk();
            $this->postJson("/api/garments/{$g['id']}/photos",
                ['photo' => $this->jpeg('done-'.$g['tag_no']), 'stage' => 'completion'],
                $this->headers($clerk))->assertCreated();

            // 并排对比接口同时返回两侧照片
            $cmp = $this->getJson("/api/garments/{$g['id']}/comparison", $this->headers($clerk))->json();
            $this->assertNotEmpty($cmp['intake_photos']);
            $this->assertNotEmpty($cmp['completion_photos']);

            $this->postJson("/api/garments/{$g['id']}/inspection/pass", [], $this->headers($clerk))->assertOk();
            $this->postJson("/api/garments/{$g['id']}/handover", [], $this->headers($clerk))->assertOk();
        }

        // 全部交付且无争议 → 订单完成
        $final = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))->json('order');
        $this->assertSame(Order::STATUS_COMPLETED, $final['status']);
        $this->assertSame(2, $final['garment_count']);

        // 统计已反映
        $stats2 = $this->getJson('/api/dashboard/intake-stats', $this->headers($clerk))->json();
        $this->assertSame(1, $stats2['orders_completed']);
        $this->assertSame(2, $stats2['garments_total']);

        // 审计事件链完整
        $events = $this->getJson("/api/orders/{$order['id']}/events", $this->headers($manager))->json('events');
        $types = array_column($events, 'type');
        foreach (['order.created', 'garment.registered', 'order.confirmed', 'garment.wash_started',
            'garment.inspection_passed', 'garment.handed_over', 'order.completed'] as $need) {
            $this->assertContains($need, $types, "缺少审计事件 {$need}");
        }
    }

    public function test_cannot_complete_inspection_without_completion_photo(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);
        $washer = $this->user(User::ROLE_WASHER);
        $ready = $this->makeReadyForInspectionGarment($clerk, $washer, 'G-NOPHOTO');

        // 该辅助方法已拍完完检照，再验证一个没有完检照的场景
        $order = $this->postJson('/api/orders', ['customer_name' => '无完检照'], $this->headers($clerk))->json('order');
        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-NOPHOTO-2', 'care_labels' => [],
        ], $this->headers($clerk))->assertCreated();
        $garmentId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->json('order.garments.0.id');
        $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $this->jpeg(), 'stage' => 'intake'], $this->headers($clerk))->assertCreated();
        $this->postJson("/api/orders/{$order['id']}/submit-confirmation", [], $this->headers($clerk))->assertOk();
        $this->postJson("/api/orders/{$order['id']}/confirm", ['signature_name' => 'x'], $this->headers($clerk))->assertOk();
        $this->postJson("/api/garments/{$garmentId}/wash/start", [], $this->headers($washer))->assertOk();
        $this->postJson("/api/garments/{$garmentId}/wash/finish", [], $this->headers($washer))->assertOk();

        // 未拍完检照直接完检 → 422
        $this->postJson("/api/garments/{$garmentId}/inspection/pass", [], $this->headers($clerk))
            ->assertStatus(422)
            ->assertJsonPath('code', 'completion_photo_required');
    }
}
