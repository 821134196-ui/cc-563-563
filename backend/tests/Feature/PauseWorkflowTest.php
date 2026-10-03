<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;

/**
 * 顾客拒签 / 禁止水洗 / 口袋物品 → 订单暂停，处理后才能恢复。
 */
class PauseWorkflowTest extends DryCleanTestCase
{
    public function test_refused_signature_pauses_order_and_blocks_intake(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);

        $order = $this->postJson('/api/orders', ['customer_name' => '拒签客'], $this->headers($clerk))->json('order');
        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-PAUSE-1', 'care_labels' => [],
        ], $this->headers($clerk))->assertCreated();
        $garmentId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->json('order.garments.0.id');
        $this->postJson("/api/garments/{$garmentId}/photos",
            ['photo' => $this->jpeg(), 'stage' => 'intake'], $this->headers($clerk))->assertCreated();

        $this->postJson("/api/orders/{$order['id']}/submit-confirmation", [], $this->headers($clerk))->assertOk();

        // 顾客拒签 → 暂停
        $this->postJson("/api/orders/{$order['id']}/refuse",
            ['note' => '顾客不认可污渍描述'], $this->headers($clerk))->assertOk();

        $paused = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))->json('order');
        $this->assertTrue($paused['is_paused']);
        $this->assertSame('refused_signature', $paused['pause']['reason']);

        // 暂停期间不能再录入衣物
        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-PAUSE-2', 'care_labels' => [],
        ], $this->headers($clerk))
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_paused');

        // 暂停提示接口能看到该单
        $this->getJson('/api/dashboard/paused', $this->headers($clerk))
            ->assertOk()
            ->assertJsonFragment(['order_no' => $paused['order_no']]);

        // 店员处理后恢复
        $this->postJson("/api/orders/{$order['id']}/resume",
            ['note' => '已当面重新核对并解释'], $this->headers($clerk))->assertOk();

        $resumed = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))->json('order');
        $this->assertFalse($resumed['is_paused']);
        $this->assertSame(Order::STATUS_AWAITING_CONFIRMATION, $resumed['status']);
    }

    public function test_pocket_item_pauses_order_and_resumes_only_after_returned(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);

        $order = $this->postJson('/api/orders', ['customer_name' => '口袋客'], $this->headers($clerk))->json('order');
        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-PKT-1', 'care_labels' => [],
        ], $this->headers($clerk))->assertCreated();
        $garment = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))->json('order.garments.0');

        // 登记口袋物品 → 自动暂停
        $this->postJson("/api/garments/{$garment['id']}/pocket-items", [
            'description' => '现金 200 元', 'note' => '内袋',
        ], $this->headers($clerk))->assertCreated();

        $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->assertJsonPath('order.is_paused', true)
            ->assertJsonPath('order.pause.reason', 'pocket_item');

        // 物品未归还，强制恢复会被拒绝
        $this->postJson("/api/orders/{$order['id']}/resume", ['note' => 'x'], $this->headers($clerk))
            ->assertStatus(409)
            ->assertJsonPath('code', 'pocket_items_open');

        // 归还物品 → 自动恢复
        $itemId = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->json('order.garments.0.pocket_items.0.id');
        $this->postJson("/api/garments/{$garment['id']}/pocket-items/{$itemId}/return", [], $this->headers($clerk))
            ->assertOk();

        $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))
            ->assertJsonPath('order.is_paused', false);
    }
}
