<?php

namespace Tests\Unit;

use App\Services\UrlClassifierService;
use PHPUnit\Framework\TestCase;

class UrlClassifierServiceTest extends TestCase
{
    private UrlClassifierService $classifier;

    protected function setUp(): void
    {
        $this->classifier = new UrlClassifierService();
    }

    public function test_classifies_the_root_homepage(): void
    {
        $result = $this->classifier->classify('https://smm.plus/');
        $this->assertSame('HOME', $result['pattern_type']);
        $this->assertSame('en', $result['lang']);
        $this->assertSame('home', $result['group_key']);
    }

    public function test_classifies_a_localized_homepage(): void
    {
        $result = $this->classifier->classify('https://smm.plus/fa');
        $this->assertSame('HOME', $result['pattern_type']);
        $this->assertSame('fa', $result['lang']);
        $this->assertSame('home', $result['group_key']);
    }

    public function test_classifies_a_blog_index_page(): void
    {
        $result = $this->classifier->classify('https://smm.plus/ar/blog');
        $this->assertSame('BLOG', $result['pattern_type']);
        $this->assertSame('ar', $result['lang']);
        $this->assertSame('blog:__index__', $result['group_key']);
    }

    public function test_classifies_a_blog_post_and_groups_it_across_locales(): void
    {
        $en = $this->classifier->classify('https://smm.plus/blog/telegram-seo-guide');
        $fa = $this->classifier->classify('https://smm.plus/fa/blog/telegram-seo-guide');

        $this->assertSame('BLOG', $en['pattern_type']);
        $this->assertSame('en', $en['lang']);
        $this->assertSame('telegram-seo-guide', $en['slug']);
        $this->assertSame('fa', $fa['lang']);
        $this->assertSame($en['group_key'], $fa['group_key']);
    }

    public function test_classifies_marketing_landing_pages(): void
    {
        $result = $this->classifier->classify('https://smm.plus/ar/telegram-boost');
        $this->assertSame('LANDING', $result['pattern_type']);
        $this->assertSame('ar', $result['lang']);
        $this->assertSame('telegram-boost', $result['slug']);
    }

    public function test_classifies_static_legal_pages_separately_from_landing_pages(): void
    {
        $result = $this->classifier->classify('https://smm.plus/about');
        $this->assertSame('STATIC', $result['pattern_type']);
        $this->assertSame('en', $result['lang']);
        $this->assertSame('about', $result['slug']);
    }

    public function test_flags_utility_app_only_pages_as_hidden_by_default(): void
    {
        foreach (['https://smm.plus/ar/signup', 'https://smm.plus/resetpassword', 'https://smm.plus/user-levels'] as $url) {
            $result = $this->classifier->classify($url);
            $this->assertSame('UTILITY', $result['pattern_type']);
            $this->assertTrue($result['default_hidden']);
        }
    }
}
