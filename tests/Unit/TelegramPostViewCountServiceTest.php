<?php

namespace Tests\Unit;

use App\Services\TelegramPostViewCountService;
use RuntimeException;
use Tests\TestCase;

class TelegramPostViewCountServiceTest extends TestCase
{
    public function test_parses_plain_and_abbreviated_counters(): void
    {
        $service = app(TelegramPostViewCountService::class);

        $this->assertSame(499, $service->parseCounter('499'));
        $this->assertSame(1200, $service->parseCounter('1.2K'));
        $this->assertSame(2500000, $service->parseCounter('2,5M'));
    }

    public function test_rejects_an_unrecognized_counter(): void
    {
        $this->expectException(RuntimeException::class);

        app(TelegramPostViewCountService::class)->parseCounter('many views');
    }
}
