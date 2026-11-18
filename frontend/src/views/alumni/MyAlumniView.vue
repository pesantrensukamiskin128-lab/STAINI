<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useToast } from 'vue-toastification'
import api from '@/services/api'

const toast = useToast()
const alumni = ref<any>(null)
const loading = ref(true)
const saving = ref(false)

const contact = reactive({ email: '', phone: '', address: '', city: '', province: '' })
const emp = reactive({ company_name: '', position: '', industry: '', city: '', start_date: '', is_current: true })
const study = reactive({ institution: '', program: '', degree: 'S2', entry_year: '' as any, graduation_year: '' as any, is_current: true })
const tracer = reactive({
  employment_status: 'BEKERJA', months_to_first_job: '' as any, first_job_relevance: '',
  first_salary: '' as any, current_salary: '' as any, competency_feedback: '', curriculum_feedback: '',
  suggestion: '', satisfaction_score: 5,
})

const statusLabel: Record<string, string> = {
  BEKERJA: 'Bekerja', WIRAUSAHA: 'Wirausaha', MELANJUTKAN_STUDI: 'Melanjutkan Studi',
  BELUM_BEKERJA: 'Belum Bekerja', LAINNYA: 'Lainnya',
}

const clean = (o: Record<string, any>) =>
  Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== null))

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/my-alumni')
    alumni.value = data
    Object.assign(contact, {
      email: data.email ?? '', phone: data.phone ?? '', address: data.address ?? '',
      city: data.city ?? '', province: data.province ?? '',
    })
  } finally { loading.value = false }
}

async function run(fn: () => Promise<any>, ok: string) {
  saving.value = true
  try { await fn(); toast.success(ok); await load() }
  catch (e: any) { toast.error(e.response?.data?.message ?? 'Gagal menyimpan.') }
  finally { saving.value = false }
}

const saveContact = () => run(() => api.put('/my-alumni', contact), 'Kontak diperbarui.')
const addEmployment = () => run(async () => {
  await api.post('/my-alumni/employments', clean(emp))
  Object.assign(emp, { company_name: '', position: '', industry: '', city: '', start_date: '' })
}, 'Riwayat pekerjaan ditambahkan.')
const delEmployment = (id: number) => run(() => api.delete(`/my-alumni/employments/${id}`), 'Dihapus.')
const addStudy = () => run(async () => {
  await api.post('/my-alumni/further-studies', clean(study))
  Object.assign(study, { institution: '', program: '', entry_year: '', graduation_year: '' })
}, 'Pendidikan lanjut ditambahkan.')
const delStudy = (id: number) => run(() => api.delete(`/my-alumni/further-studies/${id}`), 'Dihapus.')
const submitTracer = () => run(() => api.post('/my-alumni/tracer-study', clean(tracer)), 'Tracer study terkirim. Terima kasih!')

onMounted(load)

const input = 'w-full px-3 py-2 rounded-lg border border-gray-300 text-sm'
const card = 'bg-white rounded-xl border border-gray-200 p-5 space-y-3'
const btn = 'px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50'
</script>

<template>
  <div class="space-y-5 max-w-4xl">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Alumni & Tracer Study</h1>
      <p class="text-sm text-gray-500 mt-0.5">Lengkapi data alumni dan survei karir Anda.</p>
    </div>

    <p v-if="loading" class="text-sm text-gray-500">Memuat...</p>
    <template v-else-if="alumni">
      <div :class="card">
        <h2 class="font-semibold text-gray-900">Data Kontak</h2>
        <p class="text-xs text-gray-500">{{ alumni.name }} · {{ alumni.nim }} · {{ alumni.study_program?.name }}</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <input v-model="contact.email" type="email" placeholder="Email" :class="input" />
          <input v-model="contact.phone" placeholder="No. HP" :class="input" />
          <input v-model="contact.city" placeholder="Kota" :class="input" />
          <input v-model="contact.province" placeholder="Provinsi" :class="input" />
        </div>
        <textarea v-model="contact.address" placeholder="Alamat" rows="2" :class="input" />
        <button :class="btn" :disabled="saving" @click="saveContact">Simpan Kontak</button>
      </div>

      <div :class="card">
        <h2 class="font-semibold text-gray-900">Riwayat Pekerjaan</h2>
        <ul class="divide-y text-sm">
          <li v-for="e in alumni.employments" :key="e.id" class="py-2 flex justify-between gap-3">
            <span>{{ e.position }} di {{ e.company_name }}<span v-if="e.is_current" class="text-green-600"> (saat ini)</span></span>
            <button class="text-red-600 text-xs" @click="delEmployment(e.id)">Hapus</button>
          </li>
          <li v-if="!alumni.employments?.length" class="py-2 text-gray-400">Belum ada data.</li>
        </ul>
        <div class="grid sm:grid-cols-2 gap-3">
          <input v-model="emp.company_name" placeholder="Nama instansi/perusahaan" :class="input" />
          <input v-model="emp.position" placeholder="Jabatan" :class="input" />
          <input v-model="emp.industry" placeholder="Bidang" :class="input" />
          <input v-model="emp.start_date" type="date" :class="input" />
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="emp.is_current" type="checkbox" /> Masih bekerja di sini</label>
        <button :class="btn" :disabled="saving || !emp.company_name || !emp.position" @click="addEmployment">Tambah Pekerjaan</button>
      </div>

      <div :class="card">
        <h2 class="font-semibold text-gray-900">Pendidikan Lanjut</h2>
        <ul class="divide-y text-sm">
          <li v-for="s in alumni.further_studies" :key="s.id" class="py-2 flex justify-between gap-3">
            <span>{{ s.degree }} {{ s.program }} — {{ s.institution }}</span>
            <button class="text-red-600 text-xs" @click="delStudy(s.id)">Hapus</button>
          </li>
          <li v-if="!alumni.further_studies?.length" class="py-2 text-gray-400">Belum ada data.</li>
        </ul>
        <div class="grid sm:grid-cols-2 gap-3">
          <input v-model="study.institution" placeholder="Institusi" :class="input" />
          <input v-model="study.program" placeholder="Program studi" :class="input" />
          <select v-model="study.degree" :class="input"><option>S1</option><option>S2</option><option>S3</option><option>Profesi</option></select>
          <input v-model="study.entry_year" type="number" placeholder="Tahun masuk" :class="input" />
        </div>
        <button :class="btn" :disabled="saving || !study.institution || !study.program" @click="addStudy">Tambah Pendidikan</button>
      </div>

      <div :class="card">
        <h2 class="font-semibold text-gray-900">Tracer Study</h2>
        <ul v-if="alumni.tracer_studies?.length" class="text-xs text-gray-500 space-y-1">
          <li v-for="t in alumni.tracer_studies" :key="t.id">
            Terkirim {{ t.completed_at?.slice(0, 10) }} — {{ statusLabel[t.employment_status] ?? '-' }}
          </li>
        </ul>
        <div class="grid sm:grid-cols-2 gap-3">
          <select v-model="tracer.employment_status" :class="input">
            <option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option>
          </select>
          <input v-model="tracer.months_to_first_job" type="number" min="0" placeholder="Bulan hingga pekerjaan pertama" :class="input" />
          <input v-model="tracer.first_job_relevance" placeholder="Kesesuaian pekerjaan dengan prodi" :class="input" />
          <select v-model.number="tracer.satisfaction_score" :class="input">
            <option v-for="n in 5" :key="n" :value="n">Kepuasan: {{ n }} / 5</option>
          </select>
          <input v-model="tracer.first_salary" type="number" placeholder="Gaji pertama (opsional)" :class="input" />
          <input v-model="tracer.current_salary" type="number" placeholder="Gaji saat ini (opsional)" :class="input" />
        </div>
        <textarea v-model="tracer.competency_feedback" rows="2" placeholder="Masukan tentang kompetensi lulusan" :class="input" />
        <textarea v-model="tracer.curriculum_feedback" rows="2" placeholder="Masukan tentang kurikulum" :class="input" />
        <textarea v-model="tracer.suggestion" rows="2" placeholder="Saran" :class="input" />
        <button :class="btn" :disabled="saving" @click="submitTracer">Kirim Tracer Study</button>
      </div>
    </template>
  </div>
</template>
