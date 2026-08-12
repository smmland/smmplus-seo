<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('author_name');
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->string('avatar_path')->nullable();
            $table->string('related_service')->nullable();
            $table->string('country_name')->nullable();
            $table->string('country_code', 2)->nullable();
            // Only admin-approved reviews are ever served by the public API - defaults to false
            // so the future user-submission endpoint lands rows that stay invisible until an
            // admin reviews them; the Filament form defaults this toggle to on for reviews an
            // admin writes directly, since they're trusted the moment they're typed.
            $table->boolean('is_approved')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_approved', 'sort_order']);
        });

        // 20 starter reviews so the homepage has real content to show the moment this ships,
        // before any admin has written their own - all pre-approved since an admin wrote them
        // directly. sort_order matches insertion order (0-19); reorder freely in the panel later.
        $now = now();
        $reviews = [
            ['مریم رضایی', 5, 'چند بار از این سایت فالوور و لایک گرفتم، همیشه سرعت تحویل عالی بوده و تا الان هیچ ریزشی نداشتم. پشتیبانی هم واقعاً سریع جواب می‌ده.', 'فالوور اینستاگرام', 'ایران', 'IR'],
            ['علی محمدی', 5, 'برای کانالم ویو تلگرام سفارش دادم، کمتر از یک روز کامل تحویل شد. قیمتش هم نسبت به جاهای دیگه خیلی منصفانه‌ست.', 'ویو تلگرام', 'ایران', 'IR'],
            ['سارا احمدی', 4, 'کیفیت ممبرهایی که میاد خوبه، فقط یکم کندتر از چیزی بود که فکر می‌کردم شروع بشه ولی در نهایت کامل تحویل داده شد.', 'ممبر تلگرام', 'ایران', 'IR'],
            ['حسین کریمی', 5, 'اولین بار بود از یه پنل ایرانی خرید می‌کردم، خیلی راضی بودم. ویدیوهام بعد از خرید فالوور خیلی بیشتر دیده شدن.', 'فالوور تیک‌تاک', 'افغانستان', 'AF'],
            ['نگار حسینی', 5, 'پنل خیلی ساده و راحته، سفارش دادم و چند دقیقه بعد لایک‌ها شروع به اومدن کردن. حتماً بازم استفاده می‌کنم.', 'لایک اینستاگرام', 'ایران', 'IR'],
            ['امیر تقوی', 5, 'برای پیج فروشگاهیم استوری ویو خریدم، هم قیمت خوب بود هم توی همون بازه‌ای که گفته بودن تحویل داده شد.', 'ویو استوری اینستاگرام', 'ایران', 'IR'],
            ['زهرا نوری', 4, 'کارم رو راه انداخت، فقط تیکت پشتیبانی رو یکم دیر جواب دادن. در کل از نتیجه راضی‌ام.', 'فالوور یوتیوب', 'عراق', 'IQ'],
            ['رضا صادقی', 5, 'چند تا کانال مختلف دارم و برای همه‌شون از این سایت ممبر می‌گیرم. تا حالا هیچ مشکلی نداشتم.', 'ممبر تلگرام', 'ایران', 'IR'],
            ['فاطمه یوسفی', 5, 'سرعت تحویل واقعاً عالیه، تو کمتر از یک ساعت لایک‌های سفارش دادم رو دیدم.', 'لایک توییتر', 'ایران', 'IR'],
            ['محمد رحیمی', 5, 'پرداختم رو با کارت انجام دادم و بلافاصله سفارش ثبت شد. فالوورها هم واقعی به نظر می‌رسیدن.', 'فالوور اینستاگرام', 'ترکیه', 'TR'],
            ['الهام کاظمی', 4, 'خوب بود، فقط دوست داشتم گزینه‌های بیشتری برای انتخاب سرعت تحویل وجود داشته باشه.', 'ویو یوتیوب', 'ایران', 'IR'],
            ['پویا اکبری', 5, 'قبلاً از چند سایت دیگه هم امتحان کرده بودم ولی کیفیت این یکی بهتر بود، ریزش تقریباً صفر.', 'فالوور تیک‌تاک', 'ایران', 'IR'],
            ['مینا شریفی', 5, 'برای کانال فروشگاه آنلاینم ممبر خریدم، خیلی سریع اعتماد مشتری‌های جدید هم بیشتر شد.', 'ممبر تلگرام', 'امارات متحده عربی', 'AE'],
            ['بهروز فرجی', 5, 'همیشه همینجا سفارش می‌دم، هم قیمت مناسبه هم پشتیبانی تلگرامیش سریع جواب می‌ده.', 'ویو پست تلگرام', 'ایران', 'IR'],
            ['لیلا مرادی', 5, 'خیلی وقت بود دنبال یه پنل معتبر می‌گشتم، بالاخره پیدا کردم! نتیجه دقیقاً همونی بود که قول داده بودن.', 'لایک اینستاگرام', 'ایران', 'IR'],
            ['کیوان عباسی', 4, 'سفارشم کامل تحویل شد ولی یه بخشیش یه روز طول کشید. در کل تجربه خوبی بود.', 'فالوور اینستاگرام', 'ایران', 'IR'],
            ['شیرین قاسمی', 5, 'همیشه استوری‌های پیجم رو از اینجا ویو می‌گیرم، سرعتش فوق‌العادست و هیچ‌وقت مشکلی نداشتم.', 'ویو استوری', 'ایران', 'IR'],
            ['آرش نجفی', 5, 'برای شروع کانال جدیدم کمک خیلی بزرگی بود، الان دیگه رشد ارگانیک هم داره اضافه می‌شه.', 'ممبر کانال تلگرام', 'ایران', 'IR'],
            ['پریسا اسدی', 5, 'قیمت‌ها خیلی مناسب‌تر از رقباست و کیفیت هم اصلاً افت نداره. به همه دوستام معرفی کردم.', 'فالوور اینستاگرام', 'ایران', 'IR'],
            ['یاسر مرتضوی', 4, 'کارم رو راه انداخت، فقط پنل رو یکم پیچیده‌تر از چیزی که فکر می‌کردم دیدم برای اولین بار.', 'لایک تیک‌تاک', 'ایران', 'IR'],
        ];

        DB::table('reviews')->insert(array_map(fn (array $r, int $i) => [
            'author_name' => $r[0],
            'rating' => $r[1],
            'body' => $r[2],
            'related_service' => $r[3],
            'country_name' => $r[4],
            'country_code' => $r[5],
            'is_approved' => true,
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ], $reviews, array_keys($reviews)));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
