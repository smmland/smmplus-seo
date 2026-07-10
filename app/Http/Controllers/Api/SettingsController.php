<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function show()
    {
        return response()->json([
            'syncIntervalHours' => $this->settings->getSyncIntervalHours(),
            'sourceSitemapUrl' => $this->settings->getSourceSitemapUrl(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'syncIntervalHours' => ['sometimes', 'integer', 'min:1'],
            'sourceSitemapUrl' => ['sometimes', 'url'],
        ]);

        if (array_key_exists('syncIntervalHours', $data)) {
            $this->settings->setSyncIntervalHours($data['syncIntervalHours']);
        }
        if (array_key_exists('sourceSitemapUrl', $data)) {
            $this->settings->setSourceSitemapUrl($data['sourceSitemapUrl']);
        }

        return $this->show();
    }
}
