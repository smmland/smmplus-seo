<?php

namespace Tests\Feature;

use App\Filament\Pages\BlogTranslationQueue;
use App\Filament\Pages\CategoryTranslationQueue;
use App\Filament\Pages\ServiceTranslationQueue;
use App\Filament\Pages\TelegramQueue;
use App\Livewire\NotificationBell;
use App\Models\User;
use App\Services\PanelNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelNotificationNavigationBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_telegram_queue_badge_reflects_unread_notifications_targeting_it(): void
    {
        $this->actingAsSuperAdmin();
        $notifications = app(PanelNotificationService::class);

        $notifications->notify('telegram', 'new_service', 'New service added: Test', null, TelegramQueue::getUrl());
        $notifications->notify('telegram', 'new_service', 'New service added: Another', null, TelegramQueue::getUrl());

        $this->assertSame('2', TelegramQueue::getNavigationBadge());
    }

    public function test_badge_only_counts_notifications_for_that_exact_page(): void
    {
        $this->actingAsSuperAdmin();
        $notifications = app(PanelNotificationService::class);

        $notifications->notify('telegram', 'new_service', 'For telegram queue', null, TelegramQueue::getUrl());
        $notifications->notify('translation', 'new_text', 'For blog queue', null, BlogTranslationQueue::getUrl());

        $this->assertSame('1', TelegramQueue::getNavigationBadge());
        $this->assertSame('1', BlogTranslationQueue::getNavigationBadge());
    }

    public function test_badge_is_null_when_nothing_is_unread(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertNull(ServiceTranslationQueue::getNavigationBadge());
        $this->assertNull(CategoryTranslationQueue::getNavigationBadge());
    }

    public function test_badge_respects_category_access(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'granted_sections' => ['telegram_view']]);
        $this->actingAs($user);

        app(PanelNotificationService::class)->notify('translation', 'new_text', 'Needs translation access', null, BlogTranslationQueue::getUrl());

        // The user can't even see the translation category, so the badge stays hidden even
        // though a notification targeting that exact URL exists.
        $this->assertNull(BlogTranslationQueue::getNavigationBadge());
    }

    public function test_visiting_the_page_clears_its_own_badge_and_the_bells_unread_count(): void
    {
        $this->actingAsSuperAdmin();
        app(PanelNotificationService::class)->notify('telegram', 'new_service', 'New service', null, TelegramQueue::getUrl());

        $this->assertSame('1', TelegramQueue::getNavigationBadge());

        Livewire::test(TelegramQueue::class);

        $this->assertNull(TelegramQueue::getNavigationBadge());
        Livewire::test(NotificationBell::class)->assertSet('unreadCount', 0);
    }

    public function test_visiting_one_page_does_not_clear_another_pages_badge(): void
    {
        $this->actingAsSuperAdmin();
        $notifications = app(PanelNotificationService::class);
        $notifications->notify('telegram', 'new_service', 'For telegram queue', null, TelegramQueue::getUrl());
        $notifications->notify('translation', 'new_text', 'For blog queue', null, BlogTranslationQueue::getUrl());

        Livewire::test(TelegramQueue::class);

        $this->assertNull(TelegramQueue::getNavigationBadge());
        $this->assertSame('1', BlogTranslationQueue::getNavigationBadge());
    }

    public function test_marking_read_is_per_user(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true]);
        $otherAdmin = User::factory()->create(['is_super_admin' => true]);

        app(PanelNotificationService::class)->notify('telegram', 'new_service', 'New service', null, TelegramQueue::getUrl());

        $this->actingAs($viewer);
        Livewire::test(TelegramQueue::class);
        $this->assertNull(TelegramQueue::getNavigationBadge());

        $this->actingAs($otherAdmin);
        $this->assertSame('1', TelegramQueue::getNavigationBadge());
    }
}
