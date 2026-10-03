<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Models\Order;
use App\Models\User;
use App\Services\IntakeService;

/**
 * 交付验证 1：衣物标签全局唯一，同一衣物不能重复入库。
 */
class GarmentIntakeTest extends DryCleanTestCase
{
    public function test_garment_tag_must_be_unique_across_orders(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);

        $o1 = $this->postJson('/api/orders', ['customer_name' => '甲'], $this->headers($clerk))->json('order');
        $o2 = $this->postJson('/api/orders', ['customer_name' => '乙'], $this->headers($clerk))->json('order');

        $this->postJson("/api/orders/{$o1['id']}/garments", [
            'tag_no' => 'G-DUP-1', 'category' => '外套',
        ], $this->headers($clerk))->assertCreated();

        // 同一编号入到另一个订单 → 409 拦截
        $response = $this->postJson("/api/orders/{$o2['id']}/garments", [
            'tag_no' => 'G-DUP-1', 'category' => '衬衫',
        ], $this->headers($clerk));

        $response->assertStatus(409)->assertJsonPath('code', 'tag_not_unique');

        // 库里只有一件 G-DUP-1
        $this->assertSame(1, Garment::where('tag_no', 'G-DUP-1')->count());
    }

    public function test_duplicate_tag_is_rejected_even_via_service_concurrently(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);
        $service = app(IntakeService::class);

        $order = Order::create([
            'order_no' => 'X1', 'customer_name' => '并发顾客', 'status' => Order::STATUS_INTAKE,
        ]);

        $service->registerGarment($order, ['tag_no' => 'G-RACE', 'care_labels' => []], $clerk);

        $this->expectException(\App\Exceptions\DomainException::class);
        $this->expectExceptionMessage('不能重复入库');

        $service->registerGarment($order, ['tag_no' => 'G-RACE', 'care_labels' => []], $clerk);
    }

    public function test_no_wash_care_label_pauses_order_automatically(): void
    {
        $clerk = $this->user(User::ROLE_CLERK);
        $order = $this->postJson('/api/orders', ['customer_name' => '标签客'], $this->headers($clerk))->json('order');

        $this->postJson("/api/orders/{$order['id']}/garments", [
            'tag_no' => 'G-LABEL-1', 'care_labels' => ['no_wash'],
        ], $this->headers($clerk))->assertCreated();

        $fresh = $this->getJson("/api/orders/{$order['id']}", $this->headers($clerk))->json('order');
        $this->assertTrue($fresh['is_paused']);
        $this->assertSame('no_wash_label', $fresh['pause']['reason']);
    }
}
