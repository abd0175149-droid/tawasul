<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تعميم سند القبض على كامل شجرة الحسابات بدل العملاء فقط.
 *
 * يتبع نفس نمط سند الصرف (disbursements.account_id): الحساب المستهدف
 * يُختار من الشجرة، ويُبقى client_id للتوافق مع التقارير وكشوف الحسابات
 * — يُملأ تلقائياً حين يكون الحساب مملوكاً لعميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('receipts', 'account_id')) {
            Schema::table('receipts', function (Blueprint $table) {
                $table->foreignId('account_id')->nullable()->after('client_id')
                    ->constrained('accounts')->nullOnDelete();
            });
        }

        // تعبئة الحساب المستهدف للسندات القائمة من حساب العميل المرتبط
        DB::statement('
            UPDATE receipts
               SET account_id = (SELECT c.account_id FROM clients c WHERE c.id = receipts.client_id)
             WHERE account_id IS NULL
               AND client_id IS NOT NULL
        ');

        // client_id لم يعد إلزامياً — السند قد يستهدف حساباً بلا عميل
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
