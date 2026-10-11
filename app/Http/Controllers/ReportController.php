<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Disbursement;
use App\Models\Receipt;
use App\Models\LedgerEntry;
use App\Services\TravelersReport;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * صفحة «المسافرون بتاريخ» — الفواتير المعتمدة حسب تاريخ الرحلة.
     */
    public function tripDate(Request $request)
    {
        self::authorizeTripDate();

        $filters = self::tripDateFilters($request);
        $rows = TravelersReport::rows($filters);

        return Inertia::render('Reports/TripDate', [
            'title' => 'المسافرون بتاريخ',
            'rows' => $rows,
            'totals' => TravelersReport::summarise($rows),
            'employees' => TravelersReport::invoiceCreators(),
            'filters' => $filters,
        ]);
    }

    /**
     * مسافرو اليوم — JSON لبطاقة الرئيسية التي تجلب بياناتها بنفسها.
     */
    public function travelersToday()
    {
        self::authorizeTripDate();

        $today = today()->toDateString();
        $rows = TravelersReport::rowsForDate($today);

        return response()->json([
            'date' => $today,
            'rows' => $rows,
            'updated_at' => now()->format('H:i:s'),
        ] + TravelersReport::summarise($rows));
    }

    /**
     * التقرير يكشف هواتف العملاء وما عليهم من متبقٍّ، فيُفحص على الخادم
     * لا في القائمة الجانبية وحدها.
     */
    private static function authorizeTripDate(): void
    {
        abort_unless(
            auth()->user()?->can('reports.trip_date'),
            403,
            'لا تملك صلاحية عرض تقرير المسافرين.'
        );
    }

    /** تطبيع مدخلات الفلترة — السلسلة الفارغة تعني «غير محدد». */
    private static function tripDateFilters(Request $request): array
    {
        $mode = $request->input('mode', 'date');

        if (!in_array($mode, ['date', 'upcoming', 'range', 'all'], true)) {
            $mode = 'date';
        }

        $remaining = $request->input('remaining');

        return [
            'mode' => $mode,
            'date' => $request->input('date') ?: today()->toDateString(),
            'from' => $request->input('from') ?: today()->toDateString(),
            'to' => $request->input('to') ?: today()->addMonth()->toDateString(),
            'employee_id' => $request->input('employee_id') ?: null,
            'remaining' => in_array($remaining, ['with', 'without'], true) ? $remaining : null,
        ];
    }

    public function agentsBalances(Request $request)
    {
        // الرصيد بالدينار من الحساب المحاسبي (نفس مصدر كشف حساب الوكيل) — لا الرصيد المبسّط بالريال
        $agents = Agent::query()
            ->with('account:id,balance')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->get(['id', 'name', 'code', 'country', 'currency', 'phone', 'is_active', 'account_id'])
            ->map(function ($a) {
                $a->balance_jod = round((float) ($a->account?->balance ?? 0), 3);
                unset($a->account);
                return $a;
            })
            ->sortByDesc('balance_jod')
            ->values();

        $total = round($agents->sum('balance_jod'), 3);

        return Inertia::render('Reports/AgentsBalances', [
            'title' => 'أرصدة الوكلاء',
            'agents' => $agents,
            'total' => $total,
            'filters' => $request->only(['search']),
        ]);
    }

    public function clientsBalances(Request $request)
    {
        // الرصيد بالدينار من الحساب المحاسبي (نفس مصدر كشف الحساب) — لا الرصيد المبسّط
        $clients = Client::query()
            ->with('account:id,balance')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->get(['id', 'name', 'code', 'country', 'currency', 'credit_limit_jod', 'phone', 'is_active', 'account_id'])
            ->map(function ($c) {
                $c->balance_jod = round((float) ($c->account?->balance ?? 0), 3);
                unset($c->account);
                return $c;
            })
            ->sortByDesc('balance_jod')
            ->values();

        $total = round($clients->sum('balance_jod'), 3);

        return Inertia::render('Reports/ClientsBalances', [
            'title' => 'ذمم العملاء',
            'clients' => $clients,
            'total' => $total,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * منشئ التقارير التفاعلي (تجميع الخدمات حسب الوكيل/العميل)
     */
    public function builder()
    {
        return Inertia::render('Reports/Builder', [
            'title' => 'منشئ التقارير',
            'data' => \App\Services\ReportBuilderService::dataset(),
        ]);
    }

    public function profitLoss(Request $request)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $invoicesJod = Invoice::where('status', 'approved')->whereBetween('invoice_date', [$from, $to])->sum('total_jod');
        $disbursementsJod = Disbursement::where('status', 'approved')->whereBetween('disbursement_date', [$from, $to])->sum('amount_jod');
        $receiptsJod = Receipt::where('status', 'approved')->whereBetween('receipt_date', [$from, $to])->sum('amount_jod');

        return Inertia::render('Reports/ProfitLoss', [
            'title' => 'الأرباح والخسائر',
            'data' => [
                'invoices_jod' => (float)$invoicesJod,
                'disbursements_jod' => (float)$disbursementsJod,
                'receipts_jod' => (float)$receiptsJod,
            ],
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function dailySummary(Request $request)
    {
        $date = $request->date ?? now()->toDateString();
        $user = auth()->user();

        $disbQuery = Disbursement::with('agent:id,name')->whereDate('disbursement_date', $date);
        $rectQuery = Receipt::with('client:id,name')->whereDate('receipt_date', $date);
        $invQuery = Invoice::with(['client:id,name'])->whereDate('invoice_date', $date);

        // الموظف يرى الملخص الخاص به فقط
        if (!$user->isAdmin()) {
            $disbQuery->where('created_by', $user->id);
            $rectQuery->where('created_by', $user->id);
            $invQuery->where('created_by', $user->id);
        }

        $disbursements = $disbQuery->get();
        $receipts = $rectQuery->get();
        $invoices = $invQuery->get();

        return Inertia::render('Reports/DailySummary', [
            'title' => 'ملخص يومي',
            'date' => $date,
            'disbursements' => $disbursements,
            'receipts' => $receipts,
            'invoices' => $invoices,
            'totals' => [
                'disbursements_jod' => $disbursements->where('status', 'approved')->sum('amount_jod'),
                'receipts_jod' => $receipts->where('status', 'approved')->sum('amount_jod'),
                'invoices_jod' => $invoices->where('status', 'approved')->sum('total_sell_jod'),
            ],
        ]);
    }
}
