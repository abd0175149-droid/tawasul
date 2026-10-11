<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\TravelersReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelersReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'مدير عام', 'slug' => 'admin']);
        $this->user = User::create([
            'name' => 'المدير',
            'email' => 'admin@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'code' => 'C-1',
            'name' => 'عميل تجريبي',
            'phone' => '0790000000',
            'is_active' => true,
        ]);
    }

    private function invoice(string $number, float $total, string $invoiceDate, ?string $tripDate, string $status = 'approved', int $pax = 1): Invoice
    {
        return Invoice::create([
            'invoice_number' => $number,
            'client_id' => $this->client->id,
            'trip_date' => $tripDate,
            'pax' => $pax,
            'invoice_date' => $invoiceDate,
            'exchange_rate_snapshot' => 0.19,
            'total_sell_jod' => $total,
            'total_jod' => $total,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
    }

    private function receipt(string $number, float $amount, float $commission = 0): Receipt
    {
        return Receipt::create([
            'receipt_number' => $number,
            'client_id' => $this->client->id,
            'amount_jod' => $amount,
            'bank_commission' => $commission,
            'payment_method' => $commission > 0 ? 'bank' : 'cash',
            'receipt_date' => '2026-10-01',
            'status' => 'approved',
            'created_by' => $this->user->id,
        ]);
    }

    /** الدفعات توزَّع من الأقدم إصداراً إلى الأحدث. */
    public function test_fifo_allocates_payments_oldest_invoice_first(): void
    {
        $a = $this->invoice('A', 500, '2026-09-01', '2026-10-11');
        $b = $this->invoice('B', 300, '2026-09-05', '2026-10-11');
        $c = $this->invoice('C', 200, '2026-09-09', '2026-10-11');

        $this->receipt('R1', 650);

        $remaining = TravelersReport::remainingFifo(collect([$this->client->id]));

        $this->assertEqualsWithDelta(0.0, $remaining[$a->id], 0.001, 'الأقدم تُسدَّد أولاً');
        $this->assertEqualsWithDelta(150.0, $remaining[$b->id], 0.001, 'الثانية تأخذ ما تبقّى');
        $this->assertEqualsWithDelta(200.0, $remaining[$c->id], 0.001, 'الأحدث تبقى كاملة');
    }

    /** العمولة البنكية مصروف على الشركة ولا تُنقص ذمة العميل. */
    public function test_bank_commission_does_not_reduce_client_credit(): void
    {
        $inv = $this->invoice('A', 100, '2026-09-01', '2026-10-11');
        $this->receipt('R1', 100, commission: 2);

        $remaining = TravelersReport::remainingFifo(collect([$this->client->id]));

        $this->assertEqualsWithDelta(0.0, $remaining[$inv->id], 0.001);
    }

    /** السند غير المعتمد لا يُحتسب. */
    public function test_unapproved_receipts_are_ignored(): void
    {
        $inv = $this->invoice('A', 100, '2026-09-01', '2026-10-11');
        $this->receipt('R1', 100)->update(['status' => 'pending']);

        $remaining = TravelersReport::remainingFifo(collect([$this->client->id]));

        $this->assertEqualsWithDelta(100.0, $remaining[$inv->id], 0.001);
    }

    /** السند الموجّه لحساب بلا عميل لا يُنقص ذمة أي عميل. */
    public function test_receipt_without_client_is_excluded(): void
    {
        $inv = $this->invoice('A', 100, '2026-09-01', '2026-10-11');
        $this->receipt('R1', 100)->update(['client_id' => null]);

        $remaining = TravelersReport::remainingFifo(collect([$this->client->id]));

        $this->assertEqualsWithDelta(100.0, $remaining[$inv->id], 0.001);
    }

    /** الفاتورة المفتوحة للتعديل تختفي من المتابعة حتى يُعاد اعتمادها. */
    public function test_only_approved_invoices_appear(): void
    {
        $this->invoice('A', 100, '2026-09-01', '2026-10-11', status: 'approved');
        $this->invoice('B', 100, '2026-09-01', '2026-10-11', status: 'pending');
        $this->invoice('C', 100, '2026-09-01', '2026-10-11', status: 'editing');

        $rows = TravelersReport::rowsForDate('2026-10-11');

        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows[0]['invoice_number']);
    }

    /** تقييد اليوم يستبعد اليوم السابق واللاحق رغم تخزين التاريخ بوقت. */
    public function test_date_filter_matches_exactly_one_day(): void
    {
        $this->invoice('A', 100, '2026-09-01', '2026-10-10');
        $this->invoice('B', 100, '2026-09-01', '2026-10-11');
        $this->invoice('C', 100, '2026-09-01', '2026-10-12');

        $rows = TravelersReport::rowsForDate('2026-10-11');

        $this->assertCount(1, $rows);
        $this->assertSame('B', $rows[0]['invoice_number']);
    }

    /** وضع «القادمة» يبدأ من اليوم تصاعدياً. */
    public function test_upcoming_mode_returns_future_trips_ascending(): void
    {
        $this->invoice('PAST', 100, '2026-09-01', now()->subDay()->toDateString());
        $this->invoice('SOON', 100, '2026-09-01', now()->addDay()->toDateString());
        $this->invoice('LATER', 100, '2026-09-01', now()->addDays(5)->toDateString());

        $rows = TravelersReport::rows(['mode' => 'upcoming']);

        $this->assertSame(['SOON', 'LATER'], array_column($rows, 'invoice_number'));
    }

    /** عدد الأفراد من الحقل الصريح لا من مجموع كميات البنود. */
    public function test_pax_comes_from_explicit_field(): void
    {
        $this->invoice('A', 100, '2026-09-01', '2026-10-11', pax: 3);

        $rows = TravelersReport::rowsForDate('2026-10-11');

        $this->assertSame(3, $rows[0]['pax']);
        $this->assertSame(3, TravelersReport::summarise($rows)['totalPax']);
    }

    /** فلتر المتبقي يعمل بعد الحساب. */
    public function test_remaining_filter(): void
    {
        $this->invoice('PAID', 100, '2026-09-01', '2026-10-11');
        $this->invoice('OWING', 100, '2026-09-02', '2026-10-11');
        $this->receipt('R1', 100);

        $with = TravelersReport::rows(['mode' => 'date', 'date' => '2026-10-11', 'remaining' => 'with']);
        $without = TravelersReport::rows(['mode' => 'date', 'date' => '2026-10-11', 'remaining' => 'without']);

        $this->assertSame(['OWING'], array_column($with, 'invoice_number'));
        $this->assertSame(['PAID'], array_column($without, 'invoice_number'));
    }

    /** نقطتا الوصول محميتان على الخادم لا في القائمة الجانبية وحدها. */
    public function test_endpoints_require_permission(): void
    {
        $plainRole = Role::create(['name' => 'موظف', 'slug' => 'sales']);
        $plain = User::create([
            'name' => 'موظف',
            'email' => 'sales@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $plainRole->id,
            'is_active' => true,
        ]);

        $this->actingAs($plain)->get('/reports/trip-date')->assertForbidden();
        $this->actingAs($plain)->getJson('/api/travelers-today')->assertForbidden();

        $this->actingAs($this->user)->get('/reports/trip-date')->assertOk();
        $this->actingAs($this->user)->getJson('/api/travelers-today')
            ->assertOk()
            ->assertJsonStructure(['date', 'rows', 'count', 'totalPax', 'totalRemaining', 'updated_at']);
    }

    /** تاريخ الرحلة وعدد المسافرين إلزاميان عند حفظ الفاتورة. */
    public function test_invoice_requires_trip_date_and_pax(): void
    {
        $this->actingAs($this->user)
            ->post('/invoices', [
                'client_id' => $this->client->id,
                'sell_price_jod' => 100,
                'items' => [],
            ])
            ->assertSessionHasErrors(['trip_date', 'pax']);
    }
}
