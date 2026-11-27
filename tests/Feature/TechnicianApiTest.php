<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class TechnicianApiTest extends TestCase
{
    public function test_technician_endpoints_require_authentication(): void
    {
        $this->getJson('/api/technician/dashboard')->assertUnauthorized();
        $this->getJson('/api/technician/orders')->assertUnauthorized();
        $this->putJson('/api/technician/availability', ['available' => true])->assertUnauthorized();
        $this->postJson('/api/technician/orders/KC-TEST-001/reject', [
            'reason_code' => 'unavailable',
        ])->assertUnauthorized();
        $this->postJson('/api/technician/orders/KC-TEST-001/status', [
            'status' => 'on_the_way',
        ])->assertUnauthorized();
    }

    public function test_customer_cannot_access_technician_endpoints(): void
    {
        $customer = new User;
        $customer->user_id = 20;
        $customer->role = 'customer';

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/technician/dashboard')
            ->assertForbidden();
    }
}
