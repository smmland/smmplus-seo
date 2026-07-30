<?php

namespace App\Console\Commands;

use App\Models\Language;
use Illuminate\Console\Command;

class SeedLanguagesCommand extends Command
{
    protected $signature = 'translation:seed-languages';

    protected $description = 'Create the default set of languages if the languages table is empty';

    private const DEFAULTS = [
        ['code' => 'en', 'name' => 'English', 'is_default' => true],
        ['code' => 'fa', 'name' => 'فارسی'],
        ['code' => 'ar', 'name' => 'العربية'],
        ['code' => 'tr', 'name' => 'Türkçe'],
        ['code' => 'ru', 'name' => 'Русский'],
        ['code' => 'es', 'name' => 'Español'],
        ['code' => 'fr', 'name' => 'Français'],
        ['code' => 'de', 'name' => 'Deutsch'],
        ['code' => 'it', 'name' => 'Italiano'],
        ['code' => 'bp', 'name' => 'Português (Brasil)'],
        ['code' => 'id', 'name' => 'Bahasa Indonesia'],
        ['code' => 'ko', 'name' => '한국어'],
        ['code' => 'zh', 'name' => '中文'],
        ['code' => 'ja', 'name' => '日本語'],
        ['code' => 'th', 'name' => 'ไทย'],
        ['code' => 'vi', 'name' => 'Tiếng Việt'],
        ['code' => 'pl', 'name' => 'Polski'],
        ['code' => 'uk', 'name' => 'Українська'],
    ];

    public function handle(): int
    {
        if (Language::query()->exists()) {
            $this->info('Languages table already has rows, skipping.');

            return self::SUCCESS;
        }

        foreach (self::DEFAULTS as $i => $language) {
            Language::query()->create([
                ...$language,
                'is_default' => $language['is_default'] ?? false,
                'sort_order' => $i,
            ]);
        }

        $this->info('Seeded '.count(self::DEFAULTS).' languages.');

        return self::SUCCESS;
    }
}
