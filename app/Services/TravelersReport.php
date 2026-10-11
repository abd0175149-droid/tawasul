<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * متابعة مواعيد سفر العملاء.
 *
 * مصدر البيانات حقل واحد هو invoices.trip_date — لا جدول رحلات. وتستعمل
 * هذه الخدمةُ صفحةُ «المسافرون بتاريخ» وبطاقةُ «مسافرو اليوم» معاً، فالرقم
 * واحد في كل واجهة.
 */
class TravelersReport
{
    /** هامش تسامح للمقارنة بالدينار (ثلاث خانات عشرية). */
    private const EPSILON = 0.001;

    /**
     * صفوف التقرير بعد تطبيق الفلاتر.
     *
     * $filters: mode (date|upcoming|range|all)، date، from، to،
     *           employee_id، remaining (with|without)
     */
    public static function rows(array $filters): array
    {
        $mode = $filters['mode'] ?? 'date';

        $invoices = Invoice::query()
            ->with(['client:id,name,phone', 'items.agent:id,name', 'creator:id,name'])
            ->where('status', 'approved')
            ->tap(fn (Builder $q) => self::applyDateFilter($q, $mode, $filters))
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $q, $id) => $q->where('created_by', $id)
            )
            ->orderBy('trip_date', $mode === 'all' ? 'desc' : 'asc')
            ->orderBy('invoice_number')
            ->get();

        $remaining = self::remainingFifo($invoices->pluck('client_id'));

        $rows = $invoices->map(fn (Invoice $i) => [
            'id'             => $i->id,
            'invoice_number' => $i->invoice_number,
            'client'         => $i->client?->name,
            'phone'          => $i->client_phone ?: $i->client?->phone,
            'trip_date'      => $i->trip_date?->toDateString(),
            'return_date'    => $i->return_date?->toDateString(),
            'agents'         => $i->items->pluck('agent.name')->filter()->unique()->values()->all(),
            'pax'            => (int) $i->pax,
            'total'          => round((float) $i->total_sell_jod, 3),
            // الفاتورة غير الموجودة في خريطة التوزيع لم تُسدَّد منها شيء
            'remaining'      => $remaining[$i->id] ?? round((float) $i->total_sell_jod, 3),
            'employee'       => $i->creator?->name,
        ]);

        // فلتر المتبقي بعد الحساب لأنه ليس عموداً في الجدول
        $rows = match ($filters['remaining'] ?? null) {
            'with'    => $rows->filter(fn ($r) => $r['remaining'] > self::EPSILON),
            'without' => $rows->filter(fn ($r) => $r['remaining'] <= self::EPSILON),
            default   => $rows,
        };

        return $rows->values()->all();
    }

    /** صفوف يوم واحد — تستعملها بطاقة الرئيسية. */
    public static function rowsForDate(string $date): array
    {
        return self::rows(['mode' => 'date', 'date' => $date]);
    }

    /** إجماليات الصفوف المعروضة. */
    public static function summarise(array $rows): array
    {
        return [
            'count'          => count($rows),
            'totalPax'       => array_sum(array_column($rows, 'pax')),
            'totalRemaining' => round(array_sum(array_column($rows, 'remaining')), 3),
        ];
    }

    /** المستخدمون الذين أنشأوا فواتير — لقائمة فلتر الموظف. */
    public static function invoiceCreators(): array
    {
        return User::query()
            ->whereIn('id', Invoice::query()->distinct()->pluck('created_by')->filter())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * توزيع دفعات كل عميل على فواتيره من الأقدم إصداراً إلى الأحدث.
     *
     * سند القبض مربوط بالعميل لا بالفاتورة، فلا سبيل لمعرفة المتبقي على
     * فاتورة بعينها إلا بالتوزيع. ويجري على كل فواتير العميل المعتمدة لا
     * على فواتير التاريخ المعروض فقط، وإلا اختلّ التوزيع.
     *
     * @param  Collection<int,int|null>  $clientIds
     * @return array<int,float>  معرّف الفاتورة ← المتبقي عليها
     */
    public static function remainingFifo(Collection $clientIds): array
    {
        $clientIds = $clientIds->filter()->unique()->values();

        if ($clientIds->isEmpty()) {
            return [];
        }

        $invoices = Invoice::query()
            ->where('status', 'approved')
            ->whereIn('client_id', $clientIds)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get(['id', 'client_id', 'total_sell_jod']);

        // المبلغ كاملاً لا ناقص العمولة البنكية: AccountingService::recordReceipt
        // يُقيّد الطرف الدائن بكامل amount_jod والعمولة مصروف على الشركة.
        // وclient_id قد يكون فارغاً بعد تعميم السندات على شجرة الحسابات،
        // فتُستبعد السندات التي لا تخص عميلاً.
        $pools = Receipt::query()
            ->where('status', 'approved')
            ->whereNotNull('client_id')
            ->whereIn('client_id', $clientIds)
            ->groupBy('client_id')
            ->selectRaw('client_id, SUM(amount_jod) as paid')
            ->pluck('paid', 'client_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        $remaining = [];

        foreach ($invoices as $invoice) {
            $pool    = $pools[$invoice->client_id] ?? 0.0;
            $total   = (float) $invoice->total_sell_jod;
            $applied = min($pool, $total);

            $pools[$invoice->client_id] = round($pool - $applied, 3);
            $remaining[$invoice->id]    = round($total - $applied, 3);
        }

        return $remaining;
    }

    /**
     * تقييد التاريخ بمدى نصف‑مفتوح لا بـwhereDate.
     *
     * الـcast من نوع date يُخزَّن في SQLite نصاً «Y-m-d 00:00:00»، وwhereDate
     * يلفّ العمود بـstrftime فيُبطل فهرس (status, trip_date) ويُجبر مسحاً
     * كاملاً. المقارنة بمدى تُبقي الفهرس صالحاً.
     */
    private static function applyDateFilter(Builder $query, string $mode, array $filters): void
    {
        match ($mode) {
            'upcoming' => $query->where('trip_date', '>=', self::dayStart(Carbon::today())),

            'range' => $query
                ->where('trip_date', '>=', self::dayStart(self::parse($filters['from'] ?? null) ?? Carbon::today()))
                ->where('trip_date', '<', self::dayStart((self::parse($filters['to'] ?? null) ?? Carbon::today())->addDay())),

            'all' => $query->whereNotNull('trip_date'),

            default => (function () use ($query, $filters) {
                $day = self::parse($filters['date'] ?? null) ?? Carbon::today();
                $query->where('trip_date', '>=', self::dayStart($day))
                      ->where('trip_date', '<', self::dayStart($day->copy()->addDay()));
            })(),
        };
    }

    private static function dayStart(Carbon $date): string
    {
        return $date->copy()->startOfDay()->format('Y-m-d H:i:s');
    }

    /** يتسامح مع القيمة الفارغة أو التالفة فيعيد null بدل أن يرمي. */
    private static function parse(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
