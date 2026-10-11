<template>
    <div class="dash-card p-5">
        <!-- Header -->
        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div class="flex items-center gap-2">
                <h4 class="font-bold text-sm text-gray-700 dark:text-gray-200">✈️ مسافرو اليوم</h4>
                <span class="text-xs text-gray-400 font-mono" dir="ltr">{{ date || '—' }}</span>
                <span v-if="loading" class="text-xs text-gray-400 animate-pulse">⏳</span>
            </div>
            <div class="flex items-center gap-4 text-xs">
                <span class="text-gray-500 dark:text-gray-400">
                    المسافرون <b class="text-gray-700 dark:text-gray-200 font-mono">{{ totalPax }}</b>
                    <span class="text-gray-400"> · {{ count }} فاتورة</span>
                </span>
                <span class="text-gray-500 dark:text-gray-400">
                    المتبقي
                    <b class="font-mono" :class="totalRemaining > 0 ? 'text-red-500' : 'text-green-500'" dir="ltr">
                        {{ fmt(totalRemaining) }}
                    </b>
                    <span class="text-gray-400">JOD</span>
                </span>
                <Link href="/reports/trip-date" class="text-gold-600 dark:text-gold-400 hover:underline font-medium">عرض الكل ←</Link>
            </div>
        </div>

        <!-- Error state — متميّز عن الفراغ -->
        <div v-if="error" class="py-6 text-center">
            <p class="text-sm text-red-500">{{ error }}</p>
            <button @click="load" class="mt-2 text-xs text-gold-600 dark:text-gold-400 hover:underline">إعادة المحاولة</button>
        </div>

        <!-- Empty -->
        <p v-else-if="!rows.length && !loading" class="py-6 text-center text-sm text-gray-400">
            لا مسافرين اليوم
        </p>

        <!-- Table -->
        <div v-else-if="rows.length" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="px-3 py-2 text-right font-medium">الفاتورة</th>
                        <th class="px-3 py-2 text-right font-medium">العميل</th>
                        <th class="px-3 py-2 text-right font-medium">الهاتف</th>
                        <th class="px-3 py-2 text-center font-medium">الأفراد</th>
                        <th class="px-3 py-2 text-left font-medium">المتبقي</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" class="border-b border-gray-100 dark:border-gray-800 last:border-0">
                        <td class="px-3 py-2 font-mono text-xs text-gray-500" dir="ltr">{{ r.invoice_number }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ r.client || '—' }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-gray-500" dir="ltr">{{ r.phone || '—' }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ r.pax }}</td>
                        <td class="px-3 py-2 text-left font-mono" :class="r.remaining > 0 ? 'text-red-500' : 'text-green-500'" dir="ltr">
                            {{ fmt(r.remaining) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="updatedAt" class="mt-3 text-[11px] text-gray-400 text-left" dir="ltr">آخر تحديث {{ updatedAt }}</p>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';

const REFRESH_MS = 60000;

const rows = ref([]);
const date = ref('');
const count = ref(0);
const totalPax = ref(0);
const totalRemaining = ref(0);
const updatedAt = ref('');
const loading = ref(false);
const error = ref('');

let timer = null;

const fmt = (n) => Number(n || 0).toFixed(3);

const load = async () => {
    // حارس ضد تداخل الطلبات: دورة المؤقت قد تحلّ قبل انتهاء السابقة
    if (loading.value) return;

    loading.value = true;
    try {
        const res = await fetch('/api/travelers-today', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });

        if (!res.ok) {
            // الخطأ يُعرض صراحةً — 403 ليس «لا يوجد مسافرون»
            error.value = res.status === 403
                ? 'لا تملك صلاحية عرض المسافرين.'
                : `تعذّر تحميل المسافرين (${res.status}).`;
            return;
        }

        const data = await res.json();
        rows.value = data.rows || [];
        date.value = data.date || '';
        count.value = data.count || 0;
        totalPax.value = data.totalPax || 0;
        totalRemaining.value = data.totalRemaining || 0;
        updatedAt.value = data.updated_at || '';
        error.value = '';
    } catch (e) {
        error.value = 'تعذّر الاتصال بالخادم.';
    } finally {
        loading.value = false;
    }
};

const onVisibility = () => {
    if (document.visibilityState === 'visible') load();
};

onMounted(() => {
    load();
    timer = setInterval(load, REFRESH_MS);
    document.addEventListener('visibilitychange', onVisibility);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibility);
});
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
