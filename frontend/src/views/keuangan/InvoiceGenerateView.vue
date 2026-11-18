<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { ArrowLeftIcon, BanknotesIcon } from '@heroicons/vue/24/outline'
import api from '@/services/api'

const router = useRouter()
const toast = useToast()
const saving = ref(false)
const semesters = ref<any[]>([])
const programs = ref<any[]>([])
const result = ref<any>(null)
const feeTypes = ref<any[]>([])

const form = reactive({ semester_id: '', study_program_id: '', entry_year: '', due_date: '', fee_type_ids: [] as number[] })

function selectFeeTypes(recurringOnly = false) {
  form.fee_type_ids = feeTypes.value
    .filter((fee: any) => !recurringOnly || fee.is_recurring)
    .map((fee: any) => Number(fee.id))
  result.value = null
}

onMounted(async () => {
  const [semRes, progRes, feeRes] = await Promise.all([
    api.get('/semesters', { params: { per_page: 50 } }),
    api.get('/study-programs/all'),
    api.get('/finance/fee-types'),
  ])
  semesters.value = semRes.data.data ?? semRes.data
  programs.value = progRes.data
  feeTypes.value = (feeRes.data ?? []).filter((fee: any) => fee.is_active !== false)
  form.fee_type_ids = feeTypes.value.filter((fee: any) => fee.is_recurring).map((fee: any) => Number(fee.id))
  const active = semesters.value.find((s: any) => s.is_active)
  if (active) {
    form.semester_id = active.id
    form.due_date = active.krs_start ?? ''
  }
})

function onSemesterChanged() {
  const semester = semesters.value.find((item: any) => Number(item.id) === Number(form.semester_id))
  if (semester?.krs_start) form.due_date = semester.krs_start
  result.value = null
}

async function handleGenerate(preview = false) {
  if (!form.semester_id || !form.due_date || !form.fee_type_ids.length) {
    toast.error('Pilih semester, tanggal jatuh tempo, dan minimal satu jenis tagihan.')
    return
  }
  if (!preview && !confirm('Generate tagihan otomatis untuk mahasiswa aktif sesuai filter yang dipilih?')) return

  saving.value = true
  try {
    const { data } = await api.post('/finance/invoices/generate-batch', {
      semester_id: form.semester_id,
      study_program_id: form.study_program_id || null,
      entry_year: form.entry_year || null,
      due_date: form.due_date,
      fee_type_ids: form.fee_type_ids,
      preview,
    })
    result.value = data
    toast.success(data.message)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal generate.')
  } finally { saving.value = false }
}
</script>

<template>
  <div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center gap-3">
      <button class="p-2 rounded-lg hover:bg-gray-100" @click="router.back()"><ArrowLeftIcon class="w-5 h-5 text-gray-500" /></button>
      <h1 class="text-xl font-bold text-gray-900">Generate Tagihan Batch</h1>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
      <p class="text-sm text-gray-600">
        Generate tagihan secara otomatis untuk semua mahasiswa aktif berdasarkan struktur biaya yang sudah dikonfigurasi.
        Jenis tagihan yang dipilih hanya dibuat jika item tersebut belum ada pada semester yang dipilih.
      </p>

      <div>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
          <label class="block text-sm font-medium text-gray-700">Jenis Tagihan <span class="text-red-500">*</span></label>
          <div class="flex gap-2 text-xs">
            <button type="button" class="text-blue-700 underline hover:text-blue-900" @click="selectFeeTypes()">Pilih semua</button>
            <button type="button" class="text-blue-700 underline hover:text-blue-900" @click="selectFeeTypes(true)">Hanya berulang</button>
          </div>
        </div>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <label v-for="fee in feeTypes" :key="fee.id" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm">
            <input v-model="form.fee_type_ids" type="checkbox" :value="Number(fee.id)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
            <span class="flex-1">{{ fee.code }} - {{ fee.name }}</span>
            <span v-if="fee.is_recurring" class="text-[10px] text-gray-500">Berulang</span>
          </label>
        </div>
        <p class="mt-1 text-xs text-gray-500">Semua jenis tagihan aktif tersedia untuk dipilih. Secara awal, jenis berulang yang terpilih; UTS/UAS/KKN/PPL dapat dipilih saat waktunya ditagihkan.</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Semester <span class="text-red-500">*</span></label>
          <select v-model="form.semester_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="onSemesterChanged">
            <option value="">-- Pilih --</option>
            <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Jatuh Tempo <span class="text-red-500">*</span></label>
          <input v-model="form.due_date" type="date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Filter Program Studi (opsional)</label>
          <select v-model="form.study_program_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="result = null">
            <option value="">Semua Program Studi</option>
            <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Filter Tahun Angkatan (opsional)</label>
          <input v-model="form.entry_year" type="number" min="1900" max="2100" inputmode="numeric" placeholder="Contoh: 2024" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @input="result = null" />
        </div>
      </div>

      <div class="border-t border-gray-200 pt-4">
        <div class="flex flex-wrap gap-3">
          <button :disabled="saving" class="px-5 py-2.5 border border-blue-300 text-blue-700 hover:bg-blue-50 disabled:opacity-50 font-medium rounded-lg" @click="handleGenerate(true)">
            {{ saving ? 'Memproses...' : 'Pratinjau' }}
          </button>
          <button :disabled="saving" class="flex items-center gap-2 px-6 py-2.5 bg-green-600 hover:bg-green-700 disabled:bg-green-400 text-white font-medium rounded-lg" @click="handleGenerate(false)">
          <BanknotesIcon class="w-5 h-5" />
          {{ saving ? 'Memproses...' : 'Generate Tagihan' }}
          </button>
        </div>
      </div>

      <!-- Result -->
      <div v-if="result" :class="['mt-4 p-4 border rounded-lg', result.preview ? 'bg-blue-50 border-blue-200' : 'bg-green-50 border-green-200']">
        <p class="text-sm font-medium" :class="result.preview ? 'text-blue-800' : 'text-green-800'">{{ result.message }}</p>
        <div class="grid grid-cols-2 gap-2 mt-3 text-xs md:grid-cols-4">
          <div class="rounded bg-white/70 p-2"><strong>{{ result.students }}</strong><br />Mahasiswa aktif</div>
          <div class="rounded bg-white/70 p-2"><strong>{{ result.preview ? result.would_create : result.created }}</strong><br />{{ result.preview ? 'Akan dibuat' : 'Dibuat' }}</div>
          <div class="rounded bg-white/70 p-2"><strong>{{ result.already_complete }}</strong><br />Sudah lengkap</div>
          <div class="rounded bg-white/70 p-2"><strong>{{ result.without_structure }}</strong><br />Tanpa struktur biaya</div>
        </div>
        <div v-if="result.errors?.length" class="mt-3 rounded bg-red-50 p-3 text-xs text-red-700">
          <p class="font-semibold">{{ result.errors.length }} kesalahan:</p>
          <p v-for="error in result.errors.slice(0, 10)" :key="error">{{ error }}</p>
        </div>
        <button class="mt-3 text-sm text-green-700 underline hover:text-green-800" @click="router.push('/keuangan/tagihan')">
          Lihat Semua Tagihan →
        </button>
      </div>
    </div>
  </div>
</template>
