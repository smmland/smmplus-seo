<?php

namespace App\Support;

/**
 * The panel's grantable areas of access, one group per Filament navigation group, each split
 * into up to three independently-grantable tiers:
 *
 * - VIEW: see the section's list/queue pages (read-only - no mutating buttons work).
 * - EDIT: perform the section's actions (translate, mark rewarded, confirm, create/edit/delete
 *   records, ...). Implies VIEW for page access, but is checked separately for each individual
 *   mutating action/button.
 * - SETTINGS: the section's own dedicated settings sub-page, independent of VIEW/EDIT.
 *
 * GENERAL has no EDIT tier - it's just a read-only cost dashboard (VIEW) plus a settings form
 * (SETTINGS), with nothing in between to gate.
 *
 * A non-super-admin user can be given any combination of these (User resource, super-admin
 * only); every gated Page/Resource/action in that group checks the matching key via
 * User::hasAccess()/hasAnyAccess().
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

    public const TIER_VIEW = 'view';
    public const TIER_EDIT = 'edit';
    public const TIER_SETTINGS = 'settings';

    public const SECTION_LABELS = [
        self::TRANSLATION => 'Translation',
        self::FREE_SERVICE => 'Free Service Gateway',
        self::GIVEAWAY => 'Giveaway',
        self::TELEGRAM => 'Telegram Channel',
        self::SEO => 'SEO',
        self::GENERAL => 'General Settings',
    ];

    // GENERAL has no "edit" tier - see class docblock.
    public const SECTIONS_WITH_EDIT_TIER = [
        self::TRANSLATION,
        self::FREE_SERVICE,
        self::GIVEAWAY,
        self::TELEGRAM,
        self::SEO,
    ];

    public const TIER_LABELS = [
        self::TIER_VIEW => 'View',
        self::TIER_EDIT => 'Edit',
        self::TIER_SETTINGS => 'Settings',
    ];

    public const TIER_DESCRIPTIONS = [
        self::TIER_VIEW => 'See the lists/queues (read-only).',
        self::TIER_EDIT => 'Perform actions: translate, confirm, mark rewarded, create/edit/delete records, etc.',
        self::TIER_SETTINGS => 'The section\'s own dedicated settings page.',
    ];

    /**
     * @return list<string>
     */
    public static function tiersFor(string $section): array
    {
        return in_array($section, self::SECTIONS_WITH_EDIT_TIER, true)
            ? [self::TIER_VIEW, self::TIER_EDIT, self::TIER_SETTINGS]
            : [self::TIER_VIEW, self::TIER_SETTINGS];
    }

    public static function key(string $section, string $tier): string
    {
        return "{$section}_{$tier}";
    }

    /**
     * Page-level access for a section's main list/queue pages: granted by either VIEW or EDIT
     * (EDIT implies you can also just look).
     *
     * @return list<string>
     */
    public static function viewOrEditKeys(string $section): array
    {
        return [self::key($section, self::TIER_VIEW), self::key($section, self::TIER_EDIT)];
    }

    /**
     * All 17 grantable keys (5 sections x 3 tiers + general's 2), grouped by section for the
     * Users form: ['translation' => ['translation_view' => 'View', ...], ...].
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::SECTION_LABELS as $section => $sectionLabel) {
            foreach (self::tiersFor($section) as $tier) {
                $groups[$section][self::key($section, $tier)] = self::TIER_LABELS[$tier];
            }
        }

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        $out = [];

        foreach (self::SECTION_LABELS as $section => $sectionLabel) {
            foreach (self::tiersFor($section) as $tier) {
                $out[self::key($section, $tier)] = self::TIER_DESCRIPTIONS[$tier];
            }
        }

        return $out;
    }

    /**
     * All 17 grantable keys as one flat list, labeled "Section — Tier" (e.g. "Giveaway — Edit"),
     * for a single CheckboxList that lets an admin toggle any combination - see UserResource.
     * Ordered section-by-section, view/edit/settings within each, so a 3-column checkbox grid
     * naturally puts each section's own tiers on one row.
     *
     * @return array<string, string>
     */
    public static function flatOptions(): array
    {
        $out = [];

        foreach (self::SECTION_LABELS as $section => $sectionLabel) {
            foreach (self::tiersFor($section) as $tier) {
                $out[self::key($section, $tier)] = "{$sectionLabel} — ".self::TIER_LABELS[$tier];
            }
        }

        return $out;
    }
}
