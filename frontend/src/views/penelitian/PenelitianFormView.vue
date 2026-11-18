<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useToast } from 'vue-toastification'
import api from '@/services/api'

const router = useRouter()
const route  = useRoute()
const toast  = useToast()

const isEdit    = !!route.params.id
const loading   = ref(false)
const periods   = ref<any[]>([])
const authorSearch = ref('')
const authorOptions = ref<any[]>([])
const selectedAuthors = ref<any[]>([])
const authorSearchLoading = ref(false)
const authorSearchError = ref('')
let authorSearchTimer: ReturnType<typeof setTimeout> | undefined
let authorSearchRequest = 0

const form = ref({
  type: 'penelitian',
  title: '',
  period_id: '',
  abstract: '',
  keywords: '',
  proposal_link: '',
  dosen_members: [] as number[],
  mahasiswa_members: [] as number[],
})

onMounted(async () => {
  const { data: periodData } = await api.get('/penelitian-periods')
  periods.value = periodData

  if (isEdit) {
    const { data } = await api.get(`/penelitian/${route.params.id}`)
    form.value.type          = data.type
    form.value.title         = data.title
    form.value.period_id     = data.period_id ?? ''
    form.value.abstract      = data.abstract ?? ''
    form.value.keywords      = data.keywords ?? ''
    form.value.proposal_link = data.proposal_link ?? ''
    selectedAuthors.value = (data.members ?? []).map((member: any) => {
      const isLecturer = member.member_type === 'dosen'
      const person = isLecturer ? member.lecturer : member.student
      return {
        type: isLecturer ? 'lecturer' : 'student',
        id: isLecturer ? member.lecturer_id : member.student_id,
        name: person?.name ?? 'Anggota tidak ditemukan',
        identifier: person?.nidn ?? person?.nim ?? '',
      }
    })
    form.value.dosen_members = selectedAuthors.value
      .filter((author) => author.type === 'lecturer').map((author) => author.id)
    form.value.mahasiswa_members = selectedAuthors.value
      .filter((author) => author.type === 'student').map((author) => author.id)
  }
})

onBeforeUnmount(() => {
  if (authorSearchTimer) clearTimeout(authorSearchTimer)
})

function scheduleAuthorSearch() {
  if (authorSearchTimer) clearTimeout(authorSearchTimer)
  const requestId = ++authorSearchRequest
  const query = authorSearch.value.trim()
  authorOptions.value = []
  authorSearchError.value = ''

  if (query.length < 2) {
    authorSearchLoading.value = false
    return
  }

  authorSearchLoading.value = true
  authorSearchTimer = setTimeout(async () => {
    try {
      const { data } = await api.get('/lecturer-works/authors/search', {
        params: { search: query },
      })
      if (requestId === authorSearchRequest) authorOptions.value = data.data
    } catch (error: any) {
      if (requestId === authorSearchRequest) {
        authorSearchError.value = error?.response?.data?.message ?? 'Pencarian anggota gagal.'
      }
    } finally {
      if (requestId === authorSearchRequest) authorSearchLoading.value = false
    }
  }, 300)
}

function addAuthor(author: any) {
  if (selectedAuthors.value.some((selected) => selected.type === author.type && selected.id === author.id)) return
  if (selectedAuthors.value.length >= 50) {
    authorSearchError.value = 'Maksimal 50 anggota tim.'
    return
  }

  selectedAuthors.value.push(author)
  if (author.type === 'lecturer') form.value.dosen_members.push(author.id)
  else form.value.mahasiswa_members.push(author.id)
  authorSearch.value = ''
  authorOptions.value = []
  authorSearchError.value = ''
}

function removeAuthor(author: any) {
  selectedAuthors.value = selectedAuthors.value.filter(
    (selected) => selected.type !== author.type || selected.id !== author.id,
  )
  if (author.type === 'lecturer') {
    form.value.dosen_members = form.value.dosen_members.filter((id) => id !== author.id)
  } else {
    form.value.mahasiswa_members = form.value.mahasiswa_members.filter((id) => id !== author.id)
  }
}

async function submit() {
  if (!form.value.title.trim()) { toast.error('Judul wajib diisi.'); return }
  loading.value = true
  try {
    if (isEdit) {
      await api.put(`/penelitian/${route.params.id}`, form.value)
      toast.success('Proposal diperbarui.')
    } else {
      const { data } = await api.post('/penelitian', form.value)
      toast.success('Proposal berhasil dibuat.')
      router.push(`/penelitian/${data.data.id}`)
      return
    }
    router.push(`/penelitian/${route.params.id}`)
  } catch (e: any) {
    const errs = e?.response?.data?.errors
    if (errs) {
      toast.error(Object.values(errs).flat().join(', '))
    } else {
      toast.error(e?.response?.data?.message ?? 'Gagal menyimpan.')
    }
  } finally { loading.value = false }
}
</script>

<template>
  <div class="max-w-2xl mx-auto space-y-6">
    <div>
      <h1 class="text-xl font-bold text-gray-900">{{ isEdit ? 'Edit Proposal' : 'Ajukan Proposal Hibah' }}</h1>
      <p class="text-sm text-gray-500 mt-0.5">Isi informasi proposal penelitian atau pengabdian kepada masyarakat</p>
    </div>

    <form class="bg-white rounded-xl border border-gray-200 p-6 space-y-5" @submit.prevent="submit">
      <!-- Jenis -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Jenis <span class="text-red-500">*</span></label>
        <div class="flex gap-3">
          <label v-for="opt in [{ val: 'penelitian', label: 'Penelitian', icon: '🔬' }, { val: 'pengabdian', label: 'Pengabdian kepada Masyarakat', icon: '🤝' }]"
            :key="opt.val"
            :class="['flex items-center gap-2 px-4 py-2.5 rounded-lg border cursor-pointer transition-colors text-sm',
              form.type === opt.val ? 'border-blue-500 bg-blue-50 text-blue-700 font-medium' : 'border-gray-300 hover:border-gray-400']">
            <input type="radio" v-model="form.type" :value="opt.val" class="hidden" />
            <span>{{ opt.icon }}</span> {{ opt.label }}
          </label>
        </div>
      </div>

      <!-- Judul -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Judul <span class="text-red-500">*</span></label>
        <input v-model="form.title" type="text" class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="Judul proposal penelitian/pengabdian" />
      </div>

      <!-- Periode -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Periode Hibah <span class="text-red-500">*</span></label>
        <select v-model="form.period_id" class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">-- Pilih Periode --</option>
          <option v-for="p in periods.filter(p => p.type === form.type || !form.type)" :key="p.id" :value="p.id">
            {{ p.name }} ({{ p.year }})
          </option>
        </select>
      </div>

      <!-- Abstrak & Kata Kunci -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Abstrak <span class="text-gray-400 font-normal">(opsional)</span></label>
        <textarea v-model="form.abstract" rows="4" class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="Abstrak singkat penelitian..." />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Kata Kunci <span class="text-gray-400 font-normal">(opsional)</span></label>
        <input v-model="form.keywords" type="text" class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="Pisahkan dengan koma, contoh: pendidikan, teknologi" />
      </div>

      <!-- Link Proposal -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Link Proposal (Google Drive) <span class="text-gray-400 font-normal">(opsional saat draft)</span></label>
        <input v-model="form.proposal_link" type="url" class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="https://drive.google.com/..." />
      </div>

      <!-- Anggota tim -->
      <div class="space-y-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Anggota Tim <span class="text-gray-400 font-normal">(opsional)</span></label>
          <p class="text-xs text-gray-500">Cari dan tambahkan dosen atau mahasiswa berdasarkan nama, NIDN, atau NIM.</p>
        </div>
        <div v-if="selectedAuthors.length" class="space-y-2">
          <div v-for="(author, index) in selectedAuthors" :key="`${author.type}-${author.id}`"
            class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-gray-800">{{ index + 1 }}. {{ author.name }}</p>
              <p class="text-xs text-gray-500">
                {{ author.type === 'lecturer' ? 'Dosen' : 'Mahasiswa' }}
                <span v-if="author.identifier"> · {{ author.identifier }}</span>
              </p>
            </div>
            <button type="button" class="shrink-0 text-xs text-red-600 hover:text-red-700" @click="removeAuthor(author)">Hapus</button>
          </div>
        </div>
        <div class="relative">
          <input v-model="authorSearch" type="search" autocomplete="off"
            placeholder="Cari nama atau NIDN/NIM anggota..."
            class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            @input="scheduleAuthorSearch" />
          <p v-if="authorSearchLoading" class="mt-1 text-xs text-gray-500">Mencari anggota...</p>
          <p v-else-if="authorSearchError" class="mt-1 text-xs text-red-600">{{ authorSearchError }}</p>
          <p v-else-if="authorSearch.trim().length >= 2 && !authorOptions.length" class="mt-1 text-xs text-gray-500">Tidak ada anggota yang cocok.</p>
          <div v-if="authorOptions.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
            <button v-for="author in authorOptions" :key="`${author.type}-${author.id}`" type="button"
              :disabled="selectedAuthors.some((selected) => selected.type === author.type && selected.id === author.id)"
              class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-blue-50 disabled:opacity-50"
              @click="addAuthor(author)">
              <span class="min-w-0">
                <span class="block truncate text-sm font-medium text-gray-800">{{ author.name }}</span>
                <span class="text-xs text-gray-500">
                  {{ author.type === 'lecturer' ? 'Dosen' : 'Mahasiswa' }}
                  <span v-if="author.identifier"> · {{ author.identifier }}</span>
                </span>
              </span>
              <span class="shrink-0 text-xs text-blue-600">Tambah</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center gap-3 pt-2">
        <button type="submit" :disabled="loading"
          class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg">
          {{ loading ? 'Menyimpan...' : (isEdit ? 'Simpan Perubahan' : 'Simpan sebagai Draft') }}
        </button>
        <button type="button" class="px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg"
          @click="router.back()">Batal</button>
      </div>
    </form>
  </div>
</template>
