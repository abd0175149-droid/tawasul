<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول متابعة الرحلات على الفاتورة.
 *
 * عدد المسافرين حقل صريح لأن مجموع كميات البنود يضخّم الرقم: فاتورة فيها
 * تأشيرة ×3 وفندق ×3 لنفس الأشخاص تُحسب ستة أفراد لا ثلاثة.
 *
 * الفهرس مركّب (status, trip_date) لأن كل استعلامات المتابعة تبدأ بـ
 * status = approved ثم تقيّد التاريخ. ولأن Laravel يخزّن الـcast من نوع
 * date في SQLite بصيغة «Y-m-d 00:00:00»، تُقارن التواريخ في الخدمة بمدى
 * نصف‑مفتوح لا بـwhereDate — إذ إن whereDate يلفّ العمود بـstrftime
 * فيُبطل الفهرس ويُجبر مسحاً كاملاً.
 *
 * الفواتير القائمة تأخذ pax = 1، وهي لا تظهر في التقرير أصلاً لأن
 * trip_date فيها فارغ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'return_date')) {
                $table->date('return_date')->nullable()->after('trip_date');
            }
            if (!Schema::hasColumn('invoices', 'pax')) {
                $table->unsignedSmallInteger('pax')->default(1)->after('return_date');
            }
        });

        if (!Schema::hasIndex('invoices', 'invoices_status_trip_date_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['status', 'trip_date'], 'invoices_status_trip_date_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('invoices', 'invoices_status_trip_date_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex('invoices_status_trip_date_index');
            });
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['return_date', 'pax']);
        });
    }
};
