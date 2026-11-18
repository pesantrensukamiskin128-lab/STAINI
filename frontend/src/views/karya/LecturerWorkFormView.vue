<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import api from '@/services/api'
import { toDateInput } from '@/utils/formValue'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()

const isEdit  = !!route.params.id
const loading = ref(isEdit)
const saving  = ref(false)

const form = reactive({
  type: 'buku', title: '', year: new Date().getFullYear(),
  description: '', keywords: '', publisher: '',
  isbn_issn: '', hki_number: '', published_date: '',
})
const mainFile    = ref<File | null>(null)
const supportFile = ref<File | null>(null)
const existingMainFile    = ref<string | null>(null)
const existingSupportFile = ref<string | null>(null)
const authorSearch = ref('')
const authorOptions = ref<any[]>([])
const selectedAuthors = ref<any[]>([])
const authorSearchLoading = ref(false)
const authorSearchError = ref('')
let authorSearchTimer: ReturnType<typeof setTimeout> | undefined
let authorSearchRequest = 0

const TYPE_OPTIONS = [
  { value: 'buku', label: '📚 Buku' },
  { value: 'modul_ajar', label: '📝 Modul Ajar' },
  { value: 'hki_paten', label: '🏅 HKI / Paten' },
  { value: 'penelitian_mandiri', label: '🔬 Penelitian Mandiri' },
  { value: 'pengabdian_mandiri', label: '🤝 Pengabdian Mandiri' },
]

onMounted(async () => {
  if (!isEdit) return
  try {
    const { data } = await api.get(`/lecturer-works/${route.params.id}`)
    Object.assign(form, {
      type: data.type, title: data.title, year: data.year,
      description: data.description ?? '', keywords: data.keywords ?? '',
      publisher: data.publisher ?? '', isbn_issn: data.isbn_issn ?? '',
      hki_number: data.hki_number ?? '',
      published_date: toDateInput(data.published_date),
    })
    selectedAuthors.value = (data.co_authors ?? []).map((author: any) => {
      const person = author.lecturer ?? author.student
      return {
        type: author.lecturer_id ? 'lecturer' : 'student',
        id: author.lecturer_id ?? author.student_id,
        name: person?.name ?? 'Penulis tidak ditemukan',
        identifier: person?.nidn ?? person?.nim ?? '',
      }
    })
    existingMainFile.value    = data.main_file_path
    existingSupportFile.value = data.support_file_path
  } finally { loading.value = false }
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
      const { data } = await api.get('/lecturer-works/authors/search', { params: { search: query } })
      if (requestId === authorSearchRequest) authorOptions.value = data.data
    } catch (error: any) {
      if (requestId === authorSearchRequest) {
        authorSearchError.value = error?.response?.data?.message ?? 'Pencarian penulis gagal.'
      }
    } finally {
      if (requestId === authorSearchRequest) authorSearchLoading.value = false
    }
  }, 300)
}

function addAuthor(author: any) {
  if (selectedAuthors.value.some((selected) => selected.type === author.type && selected.id === author.id)) return
  selectedAuthors.value.push(author)
  authorSearch.value = ''
  authorOptions.value = []
}

function removeAuthor(author: any) {
  selectedAuthors.value = selectedAuthors.value.filter(
    (selected) => selected.type !== author.type || selected.id !== author.id,
  )
}

async function handleSave() {
  if (!form.title.trim()) { toast.error('Judul wajib diisi.'); return }
  saving.value = true
  try {
    const fd = new FormData()
    Object.entries(form).forEach(([k, v]) => { if (v !== '' && v !== null) fd.append(k, String(v)) })
    fd.append('authors', JSON.stringify(selectedAuthors.value.map(({ type, id }) => ({ type, id }))))
    if (mainFile.value)    fd.append('main_file', mainFile.value)
    if (supportFile.value) fd.append('support_file', supportFile.value)

    if (isEdit) {
      await api.post(`/lecturer-works/${route.params.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      toast.success('Karya berhasil diupdate.')
    } else {
      const { data } = await api.post('/lecturer-works', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      toast.success('Karya berhasil disimpan sebagai draft.')
      router.push(`/karya-dosen/${data.data.id}`)
      return
    }
    router.push(`/karya-dosen/${route.params.id}`)
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal menyimpan.') }
  finally { saving.value = false }
}
</script>

<template>
  <div class="max-w-2xl mx-auto space-y-5">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <button class="p-2 rounded-lg hover:bg-gray-100" @click="router.back()"><ArrowLeftIcon class="w-5 h-5 text-gray-500" /></button>
      <h1 class="text-xl font-bold text-gray-900">{{ isEdit ? 'Edit Karya' : 'Tambah Karya Baru' }}</h1>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat...</div>
    <div v-else class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">

      <!-- Jenis & Judul -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Karya <span class="text-red-500">*</span></label>
        <select v-model="form.type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option v-for="t in TYPE_OPTIONS" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Judul <span class="text-red-500">*</span></label>
        <input v-model="form.title" required placeholder="Judul karya secara lengkap..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Penulis Bersama</label>
        <p class="text-xs text-gray-500 mb-2">Tambahkan dosen lain atau mahasiswa. Dosen pemilik karya tercatat sebagai penulis utama.</p>
        <div v-if="selectedAuthors.length" class="space-y-2 mb-3">
          <div v-for="(author, index) in selectedAuthors" :key="`${author.type}-${author.id}`"
            class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-gray-800">{{ index + 2 }}. {{ author.name }}</p>
              <p class="text-xs text-gray-500">{{ author.type === 'lecturer' ? 'Dosen' : 'Mahasiswa' }}<span v-if="author.identifier"> · {{ author.identifier }}</span></p>
            </div>
            <button type="button" class="text-xs text-red-600 hover:text-red-700" @click="removeAuthor(author)">Hapus</button>
          </div>
        </div>
        <div class="relative">
          <input v-model="authorSearch" type="search" placeholder="Cari nama atau NIDN/NIM penulis..."
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
            @input="scheduleAuthorSearch" />
          <div v-if="authorSearchLoading" class="mt-1 text-xs text-gray-500">Mencari penulis...</div>
          <p v-else-if="authorSearchError" class="mt-1 text-xs text-red-600">{{ authorSearchError }}</p>
          <div v-else-if="authorSearch.trim().length >= 2 && authorOptions.length === 0" class="mt-1 text-xs text-gray-500">Tidak ada penulis yang cocok.</div>
          <div v-if="authorOptions.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
            <button v-for="author in authorOptions" :key="`${author.type}-${author.id}`" type="button"
              :disabled="selectedAuthors.some((selected) => selected.type === author.type && selected.id === author.id)"
              class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-blue-50 disabled:opacity-50"
              @click="addAuthor(author)">
              <span class="min-w-0">
                <span class="block truncate text-sm font-medium text-gray-800">{{ author.name }}</span>
                <span class="text-xs text-gray-500">{{ author.type === 'lecturer' ? 'Dosen' : 'Mahasiswa' }}<span v-if="author.identifier"> · {{ author.identifier }}</span></span>
              </span>
              <span class="shrink-0 text-xs text-blue-600">{{ selectedAuthors.some((selected) => selected.type === author.type && selected.id === author.id) ? 'Ditambahkan' : 'Tambah' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Tahun & Tanggal -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tahun <span class="text-red-500">*</span></label>
          <input v-model.number="form.year" type="number" :min="2000" :max="new Date().getFullYear() + 1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Terbit</label>
          <input v-model="form.published_date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
        </div>
      </div>

      <!-- Penerbit & ISBN -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Penerbit</label>
          <input v-model="form.publisher" placeholder="Nama penerbit..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ISBN / ISSN</label>
          <input v-model="form.isbn_issn" placeholder="978-xxx-xxx..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
        </div>
      </div>

      <!-- No. HKI (hanya jika hki_paten) -->
      <div v-if="form.type === 'hki_paten'">
        <label class="block text-sm font-medium text-gray-700 mb-1">No. HKI / Paten</label>
        <input v-model="form.hki_number" placeholder="EC00xxxx..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
      </div>

      <!-- Deskripsi -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Deskripsi</label>
        <textarea v-model="form.description" rows="3" placeholder="Ringkasan singkat tentang karya ini..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
      </div>

      <!-- Kata kunci -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Kata Kunci</label>
        <input v-model="form.keywords" placeholder="pendidikan, teknologi, inovasi (pisah dengan koma)" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
      </div>

      <!-- File upload -->
      <div class="border-t border-gray-100 pt-4 space-y-4">
        <h3 class="text-sm font-semibold text-gray-800">File Dokumen</h3>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">File Utama <span class="text-xs text-gray-400">(PDF/DOC/DOCX/JPG/PNG, maks 10MB)</span></label>
          <div v-if="existingMainFile && !mainFile" class="mb-2 flex items-center gap-2 text-xs text-green-600">
            <span>✓ File sudah ada.</span>
            <span class="text-gray-400">Upload baru untuk mengganti.</span>
          </div>
          <input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-blue-50 file:text-blue-700 border border-gray-200 rounded-lg cursor-pointer" @change="(e) => mainFile = (e.target as HTMLInputElement).files?.[0] ?? null" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">File Pendukung <span class="text-xs text-gray-400">(opsional)</span></label>
          <div v-if="existingSupportFile && !supportFile" class="mb-2 flex items-center gap-2 text-xs text-green-600">
            <span>✓ File sudah ada.</span>
            <span class="text-gray-400">Upload baru untuk mengganti.</span>
          </div>
          <input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-gray-50 file:text-gray-700 border border-gray-200 rounded-lg cursor-pointer" @change="(e) => supportFile = (e.target as HTMLInputElement).files?.[0] ?? null" />
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
        <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="router.back()">Batal</button>
        <button :disabled="saving" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="handleSave">
          {{ saving ? 'Menyimpan...' : (isEdit ? 'Update' : 'Simpan Draft') }}
        </button>
      </div>
    </div>
  </div>
</template>
