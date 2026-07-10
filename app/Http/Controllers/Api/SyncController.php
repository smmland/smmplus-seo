<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\SitemapGeneratorService;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly SitemapGeneratorService $generator,
    ) {}

    public function run()
    {
        $result = $this->sync->runSync();
        $this->generator->generate();

        return response()->json($result);
    }

    public function history(Request $request)
    {
        $take = min((int) $request->query('limit', 20), 100);

        return SyncRun::query()->orderByDesc('started_at')->take($take)->get();
    }

    public function status()
    {
        return response()->json([
            'running' => $this->sync->isRunning(),
            'lastGeneratedAt' => $this->generator->getLastGeneratedAt(),
        ]);
    }
}
