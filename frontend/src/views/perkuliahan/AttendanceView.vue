<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { ArrowLeftIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const loading = ref(true)
const saving = ref(false)
const journal = ref<any>(null)
const attendances = ref<any[]>([])
const classMembers = ref<any[]>([])
const attendanceToken = ref('')
const sessionToken = ref('')
const sessionSaving = ref(false)
const sessionForm = ref({ opens_at: '', closes_at: '' })

const statusOptions = ['HADIR', 'IZIN', 'SAKIT', 'ALFA']
const statusColor: Record<string, string> = {
  HADIR: 'bg-green-100 text-green-700',
  IZIN: 'bg-blue-100 text-blue-700',
  SAKIT: 'bg-yellow-100 text-yellow-700',
  ALFA: 'bg-red-100 text-red-700',
}

const classId = computed(() => route.params.id)
const journalId = computed(() => route.params.journalId)

onMounted(async () => {
  try {
    const { data: journalList } = await api.get(`/lectures/${classId.value}/journals`)
    const journalRes = Array.isArray(journalList)
      ? journalList.find((item: any) => item.id == journalId.value)
      : null
    if (!journalRes) {
      throw new Error('Jurnal tidak termasuk dalam kelas yang dipilih.')
    }

    const [memberRes, attendRes] = await Promise.all([
      api.get(`/lectures/${classId.value}/members`).then(({ data }) => data),
      api.get(`/lectures/journals/${journalId.value}/attendances`).then(({ data }) => data),
    ])
    journal.value = journalRes
    sessionForm.value = {
      opens_at: journalRes.attendance_opens_at ? toDateTimeLocal(journalRes.attendance_opens_at) : '',
      closes_at: journalRes.attendance_closes_at ? toDateTimeLocal(journalRes.attendance_closes_at) : defaultCloseTime(),
    }
    const sortedMembers = [...memberRes].sort((a: any, b: any) =>
      String(a.student?.name ?? '').localeCompare(String(b.student?.name ?? ''), 'id', { sensitivity: 'base' })
    )
    classMembers.value = sortedMembers

    // Build attendance map: student_id → status
    const existingMap: Record<number, string> = {}
    for (const att of (attendRes ?? [])) {
      existingMap[att.student_id] = att.status
    }

    // Merge with members
    attendances.value = sortedMembers.map((m: any) => ({
      student_id: m.student_id,
      student: m.student,
      status: existingMap[m.student_id] ?? 'ALFA',
    }))
  } catch (e: any) {
    toast.error('Gagal memuat data presensi.')
  } finally {
    loading.value = false
  }
})

function setAll(status: string) {
  attendances.value = attendances.value.map(a => ({ ...a, status }))
}

const hadir = computed(() => attendances.value.filter(a => a.status === 'HADIR').length)
const sessionActive = computed(() => {
  if (!journal.value?.attendance_opens_at || !journal.value?.attendance_closes_at) return false
  const now = Date.now()
  return now >= new Date(journal.value.attendance_opens_at).getTime()
    && now <= new Date(journal.value.attendance_closes_at).getTime()
})

function toDateTimeLocal(value: string | Date) {
  const date = new Date(value)
  const offset = date.getTimezoneOffset()
  return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 16)
}

function defaultCloseTime() {
  return toDateTimeLocal(new Date(Date.now() + 60 * 60 * 1000))
}

async function openSession() {
  sessionSaving.value = true
  try {
    const { data } = await api.post(`/lectures/journals/${journalId.value}/attendance-session`, {
      opens_at: sessionForm.value.opens_at || undefined,
      closes_at: sessionForm.value.closes_at,
    })
    sessionToken.value = data.token
    journal.value = { ...journal.value, attendance_opens_at: data.opens_at, attendance_closes_at: data.closes_at }
    toast.success('Sesi presensi berhasil dibuka. Bagikan token kepada mahasiswa.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal membuka sesi presensi.')
  } finally {
    sessionSaving.value = false
  }
}

async function closeSession() {
  sessionSaving.value = true
  try {
    await api.post(`/lectures/journals/${journalId.value}/attendance-session/close`)
    journal.value = { ...journal.value, attendance_closes_at: new Date().toISOString() }
    toast.success('Sesi presensi ditutup.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menutup sesi presensi.')
  } finally {
    sessionSaving.value = false
  }
}

async function checkIn() {
  if (!attendanceToken.value.trim()) return
  saving.value = true
  try {
    await api.post(`/lectures/journals/${journalId.value}/attendance/check-in`, { token: attendanceToken.value.trim() })
    toast.success('Presensi berhasil dicatat.')
    attendanceToken.value = ''
    const { data } = await api.get(`/lectures/journals/${journalId.value}/attendances`)
    const own = data.find((item: any) => item.student_id === auth.user?.student?.id)
    if (own) attendances.value = attendances.value.map((item) => item.student_id === own.student_id ? { ...item, status: own.status } : item)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal melakukan presensi.')
  } finally {
    saving.value = false
  }
}

async function copySessionToken() {
  await navigator.clipboard?.writeText(sessionToken.value)
  toast.success('Token disalin.')
}

async function save() {
  saving.value = true
  try {
    await api.post(`/lectures/journals/${journalId.value}/attendances`, {
      attendances: attendances.value.map(a => ({
        student_id: a.student_id,
        status: a.status,
      })),
    })
    toast.success('Presensi berhasil disimpan.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menyimpan.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-5">
    <!-- Header -->
    <div class="flex items-start gap-3">
      <button class="p-2 rounded-lg hover:bg-gray-100" @click="router.back()">
        <ArrowLeftIcon class="w-5 h-5 text-gray-500" />
      </button>
      <div class="flex-1">
        <h1 class="text-xl font-bold text-gray-900">
          Presensi — Pertemuan {{ journal?.meeting_number }}
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ journal?.topic }} · {{ journal?.meeting_date }}</p>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center h-48">
      <p class="text-gray-400">Memuat...</p>
    </div>

    <template v-else>
      <!-- Summary & Bulk Actions -->
      <div class="flex flex-wrap items-center justify-between gap-3 bg-white rounded-xl border border-gray-200 p-4">
        <div class="flex items-center gap-4">
          <div class="flex items-center gap-2">
            <CheckCircleIcon class="w-5 h-5 text-green-600" />
            <span class="text-sm font-medium text-gray-700">{{ hadir }}/{{ attendances.length }} Hadir</span>
          </div>
        </div>
        <div v-if="!auth.hasRole('MAHASISWA')" class="flex flex-wrap items-center gap-2">
          <span class="text-xs text-gray-500">Set semua:</span>
          <button v-for="s in statusOptions" :key="s" :class="['px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer', statusColor[s]]" @click="setAll(s)">{{ s }}</button>
        </div>
      </div>

      <!-- Online attendance session -->
      <section class="rounded-xl border border-blue-100 bg-blue-50/50 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="text-sm font-semibold text-gray-900">Presensi online berbasis token</h2>
            <p v-if="journal?.attendance_opens_at && journal?.attendance_closes_at" class="mt-1 text-xs text-gray-600">
              {{ sessionActive ? 'Sesi sedang aktif' : 'Sesi tidak aktif' }} ·
              {{ new Date(journal.attendance_opens_at).toLocaleString('id-ID') }} –
              {{ new Date(journal.attendance_closes_at).toLocaleString('id-ID') }}
            </p>
            <p v-else class="mt-1 text-xs text-gray-600">Belum ada sesi presensi yang dibuka.</p>
          </div>

          <div v-if="!auth.hasRole('MAHASISWA')" class="flex flex-wrap items-end gap-2">
            <label class="text-xs text-gray-600">
              Ditutup pada
              <input v-model="sessionForm.closes_at" type="datetime-local" class="mt-1 block rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs" />
            </label>
            <button :disabled="sessionSaving" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="openSession">
              {{ sessionSaving ? 'Memproses...' : 'Buka / Generate Token' }}
            </button>
            <button v-if="sessionActive" :disabled="sessionSaving" class="rounded-lg bg-red-100 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-200 disabled:opacity-50" @click="closeSession">
              Tutup Sesi
            </button>
          </div>

          <div v-else class="w-full sm:w-auto">
            <form v-if="sessionActive" class="flex gap-2" @submit.prevent="checkIn">
              <input v-model="attendanceToken" required maxlength="8" minlength="8" placeholder="Masukkan token" class="w-36 rounded-lg border border-gray-300 bg-white px-3 py-2 text-center font-mono text-sm uppercase tracking-widest" />
              <button :disabled="saving" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                {{ saving ? '...' : 'Presensi' }}
              </button>
            </form>
            <p v-else class="text-xs text-gray-500">Presensi token belum dibuka atau sudah ditutup.</p>
          </div>
        </div>

        <div v-if="sessionToken && !auth.hasRole('MAHASISWA')" class="mt-4 flex items-center justify-between rounded-lg border border-blue-200 bg-white px-4 py-3">
          <div>
            <p class="text-xs text-gray-500">Token aktif untuk dibagikan kepada mahasiswa</p>
            <p class="mt-1 font-mono text-2xl font-bold tracking-[0.3em] text-blue-700">{{ sessionToken }}</p>
          </div>
          <button class="rounded-lg bg-blue-100 px-3 py-2 text-xs font-medium text-blue-700 hover:bg-blue-200" @click="copySessionToken">
            Salin
          </button>
        </div>
      </section>

      <!-- List Mahasiswa -->
      <div class="space-y-3 sm:hidden">
        <article
          v-for="(att, i) in attendances"
          :key="att.student_id"
          class="rounded-xl border border-gray-200 bg-white p-4"
        >
          <div class="mb-3 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="break-words font-medium text-gray-900">{{ att.student?.name }}</p>
              <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <p class="font-mono text-xs text-gray-500">{{ att.student?.nim }}</p>
                <span
                  class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                  :class="att.student?.class_type === 'HYBRID' ? 'bg-violet-50 text-violet-700' : att.student?.class_type === 'KHUSUS' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'"
                >
                  {{ att.student?.class_type === 'HYBRID' ? 'Hybrid' : att.student?.class_type === 'KHUSUS' ? 'Khusus' : 'Reguler' }}
                </span>
              </div>
            </div>
            <span class="shrink-0 text-xs text-gray-400">#{{ i + 1 }}</span>
          </div>
          <p class="mb-2 text-xs font-medium text-gray-500">Status Presensi</p>
          <div class="grid grid-cols-2 gap-2">
            <template v-if="!auth.hasRole('MAHASISWA')">
              <button
                v-for="s in statusOptions"
                :key="s"
                :class="['rounded-lg px-3 py-2 text-xs font-semibold transition-all', att.status === s ? statusColor[s] + ' ring-2 ring-offset-1 ring-current' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']"
                @click="att.status = s"
              >{{ s }}</button>
            </template>
            <span v-else :class="['col-span-2 rounded-lg px-3 py-2 text-center text-xs font-semibold', statusColor[att.status]]">{{ att.status }}</span>
          </div>
        </article>
        <div v-if="!attendances.length" class="rounded-xl border border-gray-200 bg-white py-8 text-center text-sm text-gray-400">
          Belum ada anggota kelas.
        </div>
      </div>

      <div class="hidden overflow-hidden rounded-xl border border-gray-200 bg-white sm:block">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[860px] text-sm">
            <thead>
              <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase border-b">
                <th class="px-4 py-3">No</th>
                <th class="px-4 py-3">NIM</th>
                <th class="px-4 py-3">Nama Mahasiswa</th>
                <th class="px-4 py-3">Jenis Kelas</th>
                <th class="px-4 py-3 text-center">Status Presensi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(att, i) in attendances" :key="att.student_id" class="border-b border-gray-100 hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-500">{{ i + 1 }}</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ att.student?.nim }}</td>
                <td class="px-4 py-3 font-medium text-gray-900">{{ att.student?.name }}</td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="att.student?.class_type === 'HYBRID' ? 'bg-violet-50 text-violet-700' : att.student?.class_type === 'KHUSUS' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'"
                  >
                    {{ att.student?.class_type === 'HYBRID' ? 'Hybrid' : att.student?.class_type === 'KHUSUS' ? 'Khusus' : 'Reguler' }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center justify-center gap-1">
                    <template v-if="!auth.hasRole('MAHASISWA')">
                      <button
                        v-for="s in statusOptions"
                        :key="s"
                        :class="['px-2.5 py-1 rounded-full text-xs font-medium transition-all', att.status === s ? statusColor[s] + ' ring-2 ring-offset-1 ring-current' : 'bg-gray-100 text-gray-500 hover:bg-gray-200']"
                        @click="att.status = s"
                      >{{ s }}</button>
                    </template>
                    <span v-else :class="['px-2.5 py-1 rounded-full text-xs font-medium', statusColor[att.status]]">{{ att.status }}</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!attendances.length" class="text-center py-8 text-gray-400 text-sm">
            Belum ada anggota kelas.
          </div>
        </div>
      </div>

      <!-- Save Button — hanya dosen/admin -->
      <div v-if="!auth.hasRole('MAHASISWA')" class="flex justify-end">
        <button
          :disabled="saving"
          class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg"
          @click="save"
        >
          {{ saving ? 'Menyimpan...' : 'Simpan Presensi' }}
        </button>
      </div>
    </template>
  </div>
</template>
