<?php

namespace Tests\Feature;

use App\Services\TorExitNodeListService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TorExitNodeListServiceTest extends TestCase
{
    public function test_refresh_stores_the_fetched_list_and_is_exit_node_reflects_it(): void
    {
        $ips = collect(range(1, 150))->map(fn ($i) => "10.0.0.{$i}")->implode("\n");
        Http::fake(['*' => Http::response("# Tor exit list\n{$ips}\n", 200)]);

        $service = app(TorExitNodeListService::class);
        $count = $service->refresh();

        $this->assertSame(150, $count);
        $this->assertTrue($service->isExitNode('10.0.0.1'));
        $this->assertFalse($service->isExitNode('192.168.1.1'));
        $this->assertNotNull($service->lastRefreshedAt());
    }

    public function test_refresh_ignores_a_suspiciously_small_response_and_keeps_the_previous_list(): void
    {
        $ips = collect(range(1, 150))->map(fn ($i) => "10.0.0.{$i}")->implode("\n");
        Http::fake(['*' => Http::response($ips, 200)]);
        $service = app(TorExitNodeListService::class);
        $service->refresh();

        Http::fake(['*' => Http::response("10.0.0.1\n10.0.0.2\n", 200)]);
        $count = $service->refresh();

        $this->assertSame(150, $count);
        $this->assertTrue($service->isExitNode('10.0.0.150'));
    }

    public function test_refresh_handles_a_failed_fetch_without_throwing(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $service = app(TorExitNodeListService::class);
        $count = $service->refresh();

        $this->assertSame(0, $count);
    }

    public function test_is_exit_node_is_false_when_list_was_never_fetched(): void
    {
        Cache::flush();

        $service = app(TorExitNodeListService::class);

        $this->assertFalse($service->isExitNode('1.2.3.4'));
    }
}
