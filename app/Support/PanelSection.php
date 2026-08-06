<?php

namespace App\Support;

/**
 * The panel's grantable areas of access, one per Filament navigation group. A non-super-admin
 * user can be given any combination of these (User resource, super-admin only); every gated Page
 * and Resource in that group checks the matching permission via User::hasAccess().
 *
 * Deliberately not included here: user management and the self-update installer (PanelUpdate) -
 * both stay restricted to is_super_admin only, since either one can hand out or exceed full
 * access on its own.
 */
class PanelSection
{
    public const TRANSLATION = 'translation';
    public const FREE_SERVICE = 'free_service';
    public const GIVEAWAY = 'giveaway';
    public const TELEGRAM = 'telegram';
    public const SEO = 'seo';
    public const GENERAL = 'general';

    public const LABELS = [
        self::TRANSLATION => 'Translation',
        self::FREE_SERVICE => 'Free Service Gateway',
        self::GIVEAWAY => 'Giveaway',
        self::TELEGRAM => 'Telegram Channel',
        self::SEO => 'SEO',
        self::GENERAL => 'General Settings',
    ];

    public const DESCRIPTIONS = [
        self::TRANSLATION => 'Blog & service translation queues, translation settings, languages',
        self::FREE_SERVICE => 'Free service gateway: services, stats, API servers, blocked IPs, settings',
        self::GIVEAWAY => 'Giveaway claims and settings',
        self::TELEGRAM => 'Telegram channel auto-post queue and settings',
        self::SEO => 'URLs, sync history, sitemap sync settings',
        self::GENERAL => 'General panel settings and AI cost dashboard',
    ];

    public static function permissionKey(string $section): string
    {
        return "access_{$section}";
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::LABELS;
    }
}
