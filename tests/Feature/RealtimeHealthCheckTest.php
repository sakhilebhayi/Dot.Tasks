<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * /up/realtime walks each link of the real-time chain independently
 * (broadcasting config -> queue -> reverb:start process) so it can report
 * which one broke instead of just "unhealthy". Laravel's own /up only
 * proves the app boots. Same shape as Dot.Mines'
 * RealtimeHealthCheckTest (the ecosystem reference implementation).
 */
class RealtimeHealthCheckTest extends TestCase
{
    private function configureHealthyBroadcasting(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
    }

    public function test_reports_healthy_when_every_link_is_up(): void
    {
        $this->configureHealthyBroadcasting();
        config(['queue.default' => 'database']);

        // Simulate a running reverb:start process with a real listening socket.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "Could not bind a test socket: {$errstr}");
        [, $port] = explode(':', stream_socket_get_name($server, false));
        config(['reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => $port]);

        $response = $this->getJson('/up/realtime');

        fclose($server);

        $response->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_reports_degraded_when_broadcast_driver_is_not_reverb(): void
    {
        config(['broadcasting.default' => 'log', 'queue.default' => 'database']);

        $this->getJson('/up/realtime')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded');
    }

    public function test_reports_degraded_when_reverb_credentials_are_missing(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.secret' => null,
            'broadcasting.connections.reverb.app_id' => null,
            'queue.default' => 'database',
        ]);

        $this->getJson('/up/realtime')
            ->assertStatus(503);
    }

    public function test_reports_degraded_when_reverb_process_is_unreachable(): void
    {
        $this->configureHealthyBroadcasting();
        config([
            'queue.default' => 'database',
            // Port 1 is reserved and nothing will ever be listening on it locally.
            'reverb.servers.reverb.host' => '127.0.0.1',
            'reverb.servers.reverb.port' => 1,
        ]);

        $this->getJson('/up/realtime')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded');
    }

    public function test_reports_degraded_when_redis_queue_connection_is_unreachable(): void
    {
        $this->configureHealthyBroadcasting();
        config([
            'queue.default' => 'redis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
        ]);

        $this->getJson('/up/realtime')
            ->assertStatus(503);
    }

    public function test_public_response_exposes_only_the_overall_status(): void
    {
        config(['broadcasting.default' => 'log', 'queue.default' => 'database']);

        $response = $this->getJson('/up/realtime')->assertStatus(503);

        $this->assertSame(['status' => 'degraded'], $response->json());
        $this->assertStringNotContainsString('BROADCAST_CONNECTION', $response->getContent());
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
    }
}
