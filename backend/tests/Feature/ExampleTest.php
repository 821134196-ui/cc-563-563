<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * 健康检查端点可用（/up 由 Laravel 内置）。
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/up')->assertStatus(200);
    }
}
