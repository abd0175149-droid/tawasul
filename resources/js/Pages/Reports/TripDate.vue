<template>
    <AppLayout>
        <template #header>✈️ المسافرون بتاريخ</template>

        <div class="space-y-5">
            <!-- Filters -->
            <div class="dash-card p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">النطاق</label>
                        <select v-model="f.mode" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
                            <option value="date">يوم محدد</option>
                            <option value="upcoming">القادمة</option>
                            <option value="range">من / إلى</option>
                            <option value="all">كل التواريخ</option>
                        </select>
                    </div>

                    <div v-if="f.mode === 'date'">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">التاريخ</label>
                        <input v-model="f.date" type="date" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"/>
                    </div>

                    <template v-if="f.mode === 'range'">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">من</label>
                            <input v-model="f.from" type="date" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">إلى</label>
                            <input v-model="f.to" type="date" :min="f.from || null" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"/>
                        </div>
                    </template>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">الموظف</label>
                        <select v-model="f.employee_id" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
                            <option value="">الكل</option>
                            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">المتبقي</label>
                        <select v-model="f.remaining" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
                            <option value="">الكل</option>
                            <option value="with">عليه متبقٍ</option>
                            <option value="without">مسدَّد بالكامل</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">العرض</label>
                        <select v-model="view" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
                            <option value="both">العميل والوكيل</option>
                            <option value="client">العميل فقط</option>
                            <option value="agent">الوكيل فقط</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="dash-card p-5">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">المسافرون</p>
                    <p class="text-xl font-bold font-mono text-gray-800 dark:text-gray-100" dir="ltr">{{ totals.totalPax }}</p>
                    <p class="text-xs text-gray-400 mt-1">{{ totals.count }} فاتورة</p>
                </div>
                <div class="dash-card p-5">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">إجمالي المتبقي</p>
                    <p class="text-xl font-bold font-mono" :class="totals.totalRemaining > 0 ? 'text-red-500' : 'text-green-500'" dir="ltr">
                        {{ fmt(totals.totalRemaining) }} <span class="text-xs text-gray-400">JOD</span>
                    </p>
                </div>
            </div>

            <!-- Table -->
            <div class="dash-card p-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="px-4 py-3 text-right font-medium">الفاتورة</th>
                            <th class="px-4 py-3 text-right font-medium">تاريخ الرحلة</th>
                            <th class="px-4 py-3 text-right font-medium">العودة</th>
                            <th v-if="view !== 'agent'" class="px-4 py-3 text-right font-medium">العميل</th>
                            <th v-if="view !== 'agent'" class="px-4 py-3 text-right font-medium">الهاتف</th>
                            <th v-if="view !== 'client'" class="px-4 py-3 text-right font-medium">الوكلاء</th>
                            <th class="px-4 py-3 text-center font-medium">الأفراد</th>
                            <th class="px-4 py-3 text-left font-medium">الإجمالي</th>
                            <th class="px-4 py-3 text-left font-medium">المتبقي</th>
                            <th class="px-4 py-3 text-right font-medium">الموظف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="border-b border-gray-100 dark:border-gray-800 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="px-4 py-3 font-mono text-xs text-gray-500" dir="ltr">{{ r.invoice_number }}</td>
                            <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ r.trip_date || '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-500" dir="ltr">{{ r.return_date || '—' }}</td>
                            <td v-if="view !== 'agent'" class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ r.client || '—' }}</td>
                            <td v-if="view !== 'agent'" class="px-4 py-3 font-mono text-xs text-gray-500" dir="ltr">{{ r.phone || '—' }}</td>
                            <td v-if="view !== 'client'" class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">{{ r.agents.join('، ') || '—' }}</td>
                            <td class="px-4 py-3 text-center font-mono">{{ r.pax }}</td>
                            <td class="px-4 py-3 text-left font-mono" dir="ltr">{{ fmt(r.total) }}</td>
                            <td class="px-4 py-3 text-left font-mono" :class="r.remaining > 0 ? 'text-red-500' : 'text-green-500'" dir="ltr">{{ fmt(r.remaining) }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ r.employee || '—' }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="10" class="px-4 py-10 text-center text-sm text-gray-400">لا توجد رحلات مطابقة</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ count: 0, totalPax: 0, totalRemaining: 0 }) },
    employees: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

// «العرض» يخفي أعمدة في المتصفح فقط — لا يستدعي الخادم
const view = ref('both');

const f = reactive({
    mode: props.filters.mode || 'date',
    date: props.filters.date || '',
    from: props.filters.from || '',
    to: props.filters.to || '',
    employee_id: props.filters.employee_id || '',
    remaining: props.filters.remaining || '',
});

const fmt = (n) => Number(n || 0).toFixed(3);

// الفلترة كلها على الخادم، والرابط قابل للمشاركة
let pending = null;
watch(f, () => {
    clearTimeout(pending);
    pending = setTimeout(() => {
        router.get('/reports/trip-date', { ...f }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 250);
}, { deep: true });
</script>

<style scoped>
.dash-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 1rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
:root.dark .dash-card {
    background: rgba(30, 41, 59, 0.7);
    border-color: rgba(71, 85, 105, 0.4);
}
</style>
