import { UrlClassifierService } from '../src/sitemap/url-classifier.service';

describe('UrlClassifierService', () => {
  const classifier = new UrlClassifierService();

  it('classifies the root homepage', () => {
    const result = classifier.classify('https://smm.plus/');
    expect(result).toMatchObject({ patternType: 'HOME', lang: 'en', groupKey: 'home' });
  });

  it('classifies a localized homepage', () => {
    const result = classifier.classify('https://smm.plus/fa');
    expect(result).toMatchObject({ patternType: 'HOME', lang: 'fa', groupKey: 'home' });
  });

  it('classifies a blog index page', () => {
    const result = classifier.classify('https://smm.plus/ar/blog');
    expect(result).toMatchObject({ patternType: 'BLOG', lang: 'ar', groupKey: 'blog:__index__' });
  });

  it('classifies a blog post and groups it across locales', () => {
    const en = classifier.classify('https://smm.plus/blog/telegram-seo-guide');
    const fa = classifier.classify('https://smm.plus/fa/blog/telegram-seo-guide');
    expect(en).toMatchObject({ patternType: 'BLOG', lang: 'en', slug: 'telegram-seo-guide' });
    expect(fa).toMatchObject({ patternType: 'BLOG', lang: 'fa', slug: 'telegram-seo-guide' });
    expect(en.groupKey).toBe(fa.groupKey);
  });

  it('classifies marketing landing pages', () => {
    const result = classifier.classify('https://smm.plus/ar/telegram-boost');
    expect(result).toMatchObject({ patternType: 'LANDING', lang: 'ar', slug: 'telegram-boost' });
  });

  it('classifies static/legal pages separately from landing pages', () => {
    const result = classifier.classify('https://smm.plus/about');
    expect(result).toMatchObject({ patternType: 'STATIC', lang: 'en', slug: 'about' });
  });

  it('flags utility/app-only pages as hidden by default', () => {
    const signup = classifier.classify('https://smm.plus/ar/signup');
    const resetPw = classifier.classify('https://smm.plus/resetpassword');
    const userLevels = classifier.classify('https://smm.plus/user-levels');
    for (const r of [signup, resetPw, userLevels]) {
      expect(r.patternType).toBe('UTILITY');
      expect(r.defaultHidden).toBe(true);
    }
  });
});
