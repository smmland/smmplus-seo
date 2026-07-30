<?php

namespace App\Console\Commands;

use App\Services\BlogTranslationDetectionService;
use App\Services\TranslationSettingsService;
use Illuminate\Console\Command;

class RefreshBlogTranslationStatusCommand extends Command
{
    protected $signature = 'translation:refresh-blog-status {--force : Re-check every blog URL, ignoring the recheck interval}';

    protected $description = 'Fetch each non-default-language blog URL\'s title, compare it to the default language, and hide/unhide accordingly';

    public function handle(BlogTranslationDetectionService $detector, TranslationSettingsService $settings): int
    {
        $result = $detector->refresh(force: (bool) $this->option('force'));
        $settings->recordScheduledRun();

        $this->info("Checked={$result['checked']} hidden={$result['hidden']} unhidden={$result['unhidden']} errors={$result['errors']}");

        return self::SUCCESS;
    }
}
