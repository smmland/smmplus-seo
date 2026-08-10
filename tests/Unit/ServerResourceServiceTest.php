<?php

namespace Tests\Unit;

use App\Services\ServerResourceService;
use Tests\TestCase;

class ServerResourceServiceTest extends TestCase
{
    public function test_load_average_returns_a_float_or_null_without_throwing(): void
    {
        $result = (new ServerResourceService)->loadAverage();

        $this->assertTrue($result === null || is_float($result));
    }

    public function test_memory_usage_percent_returns_a_plausible_percentage_or_null_without_throwing(): void
    {
        $result = (new ServerResourceService)->memoryUsagePercent();

        if ($result !== null) {
            $this->assertGreaterThanOrEqual(0, $result);
            $this->assertLessThanOrEqual(100, $result);
        } else {
            $this->assertNull($result);
        }
    }
}
