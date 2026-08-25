<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Post a webhook payload with the (optional) HMAC signature header. */
    private function postWebhook(array $payload)
    {
        $signature = hash_hmac('sha512', json_encode($payload), env('PAYSTACK_SECRET_KEY', ''));

        return $this->withHeaders(['x-paystack-signature' => $signature])
            ->postJson('/api/paystack/webhook', $payload);
    }

    public function test_webhook_records_event_and_updates_tenant()
    {
        // create a tenant to update
        $tenantId = \DB::table('tenants')->insertGetId([
            'name' => 'Webhook School',
            'slug' => 'webhook-school',
            'status' => 'active',
            'settings' => json_encode(['plan' => 'free']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'event' => 'transaction.success',
            'data' => [
                'reference' => 'ref-test-999',
                'metadata' => ['tenant_id' => $tenantId],
                'customer' => ['customer_code' => 'CUST_TEST_999'],
            ],
        ];

        $resp = $this->postWebhook($payload);

        $resp->assertStatus(200)->assertJson(['status' => true]);

        $this->assertDatabaseHas('paystack_events', ['event' => 'transaction.success', 'event_key' => 'ref-test-999']);
        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'paystack_customer_code' => 'CUST_TEST_999']);
    }

    public function test_plan_upgrade_webhook_activates_plan()
    {
        $tenantId = \DB::table('tenants')->insertGetId([
            'name' => 'Upgrade School',
            'slug' => 'upgrade-school',
            'status' => 'active',
            'plan' => 'free',
            'subscription_status' => 'active',
            'settings' => json_encode(['plan' => 'free']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'event' => 'transaction.success',
            'data' => [
                'reference' => 'upg-ref-001',
                'metadata' => [
                    'tenant_id' => $tenantId,
                    'plan' => 'basic',
                    'purpose' => 'plan_upgrade',
                ],
            ],
        ];

        $this->postWebhook($payload)->assertStatus(200)->assertJson(['status' => true]);

        $tenant = Tenant::find($tenantId);
        $this->assertSame('basic', $tenant->plan);
        $this->assertSame('active', $tenant->subscription_status);
        $this->assertNotNull($tenant->current_period_end);
    }

    public function test_plan_upgrade_webhook_is_idempotent_on_redelivery()
    {
        $tenantId = \DB::table('tenants')->insertGetId([
            'name' => 'Idempotent School',
            'slug' => 'idempotent-school',
            'status' => 'active',
            'plan' => 'free',
            'subscription_status' => 'active',
            'settings' => json_encode(['plan' => 'free']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'event' => 'transaction.success',
            'data' => [
                'reference' => 'upg-ref-dupe',
                'metadata' => [
                    'tenant_id' => $tenantId,
                    'plan' => 'pro',
                    'purpose' => 'plan_upgrade',
                ],
            ],
        ];

        // Deliver the same event twice (Paystack retries).
        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        // Plan is correctly activated and the redelivery did not corrupt state.
        $tenant = Tenant::find($tenantId);
        $this->assertSame('pro', $tenant->plan);
        $this->assertSame('active', $tenant->subscription_status);

        // The unique (event, event_key) index means the duplicate is not recorded twice.
        $this->assertDatabaseCount('paystack_events', 1);
    }
}
