<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { BookOpenIcon, MagnifyingGlassIcon, ArrowDownTrayIcon, HeartIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

interface Book {
  id: number
  title: string
  subtitle?: string
  author: string
  isbn?: string
  publisher?: string
  publish_year?: number
  language: string
  category?: string
  shelf?: string
  description?: string
  cover_url?: string
  access_type: 'physical' | 'digital' | 'both'
  total_copies: number
  available_copies: number
  is_favorite?: boolean
  has_digital_file?: boolean
  study_program?: { name: string; code: string } | null
}

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const books = ref<Book[]>([])
const filters = ref({ categories: [] as string[], years: [] as number[], programs: [] as any[] })
const search = ref('')
const category = ref('')
const year = ref('')
const accessType = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const selected = ref<Book | null>(null)
const announcements = ref<any[]>([])
const procurementOpen = ref(false)
const procurement = ref({ title: '', author: '', isbn: '', reason: '' })
const submitting = ref(false)
const accessLabel: Record<Book['access_type'], string> = {
  physical: 'Buku fisik',
  digital: 'E-book',
  both: 'Fisik & digital',
}

onMounted(async () => {
  if (auth.token && !auth.user) await auth.fetchMe()
  await Promise.all([load(), loadAnnouncements()])
})

let searchTimer: ReturnType<typeof setTimeout>
watch([search, category, year, accessType], () => {
  clearTimeout(searchTimer)
  page.value = 1
  searchTimer = setTimeout(load, 300)
})

async function load(nextPage = page.value) {
  loading.value = true
  try {
    const { data } = await api.get('/library/catalog', {
      params: {
        search: search.value || undefined,
        category: category.value || undefined,
        year: year.value || undefined,
        access_type: accessType.value || undefined,
        page: nextPage,
        per_page: 12,
      },
    })
    books.value = data.data
    page.value = data.current_page
    lastPage.value = data.last_page
    total.value = data.total
    filters.value = data.filters
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Katalog buku gagal dimuat.')
  } finally {
    loading.value = false
  }
}

async function loadAnnouncements() {
  try {
    const { data } = await api.get('/library/announcements')
    announcements.value = data
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Pengumuman perpustakaan gagal dimuat.')
  }
}

async function openBook(book: Book) {
  try {
    const { data } = await api.get(`/library/books/${book.id}`)
    selected.value = data
    book.is_favorite = data.is_favorite
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Detail buku gagal dimuat.')
  }
}

function requireMember() {
  if (!auth.isAuthenticated) {
    toast.warning('Masuk dengan akun ASC untuk menggunakan layanan perpustakaan.')
    router.push('/login')
    return false
  }
  if (!auth.hasPermission('digilib.borrow')) {
    toast.error('Akun ini belum memiliki akses layanan perpustakaan.')
    return false
  }
  return true
}

async function toggleFavorite(book: Book) {
  if (!requireMember()) return
  try {
    const { data } = await api.post(`/library/me/books/${book.id}/favorite`)
    book.is_favorite = data.favorited
    if (selected.value?.id === book.id) selected.value.is_favorite = data.favorited
    toast.success(data.favorited ? 'Buku disimpan ke favorit.' : 'Buku dihapus dari favorit.')
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Favorit gagal diperbarui.')
  }
}

async function reserve(book: Book) {
  if (!requireMember()) return
  try {
    const { data } = await api.post(`/library/me/books/${book.id}/reservations`)
    toast.success(data.message)
    selected.value = null
  } catch (error: any) {
    toast.error(error?.response?.data?.errors?.book?.[0] ?? error?.response?.data?.message ?? 'Reservasi gagal.')
  }
}

async function downloadBook(book: Book) {
  if (!requireMember()) return
  try {
    const { data } = await api.get(`/library/books/${book.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([data]))
    const link = document.createElement('a')
    link.href = url
    link.download = `${book.title}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  } catch (error: any) {
    toast.error(error?.response?.status === 401 ? 'Masuk dengan akun ASC untuk mengakses e-book.' : 'File e-book gagal diunduh.')
  }
}

async function submitProcurement() {
  if (!requireMember()) return
  submitting.value = true
  try {
    const { data } = await api.post('/library/me/procurement-requests', procurement.value)
    toast.success(data.message)
    procurementOpen.value = false
    procurement.value = { title: '', author: '', isbn: '', reason: '' }
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Usulan buku gagal dikirim.')
  } finally {
    submitting.value = false
  }
}

</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-gray-200 bg-white">
      <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-4">
        <BookOpenIcon class="h-8 w-8 text-blue-700" />
        <div>
          <p class="font-bold text-gray-900">Digital Library ASC</p>
          <p class="text-xs text-gray-500">Katalog dan layanan sirkulasi perpustakaan</p>
        </div>
        <div class="flex-1" />
        <a href="/repository" class="text-sm font-medium text-gray-600">Repository ASC</a>
        <RouterLink v-if="auth.isAuthenticated" to="/perpustakaan/akun" class="text-sm font-medium text-blue-700">Layanan Saya</RouterLink>
        <RouterLink v-if="auth.hasPermission('digilib.books.manage')" to="/digilib/kelola" class="text-sm font-medium text-gray-600">Kelola</RouterLink>
        <a :href="auth.isAuthenticated ? (auth.hasPermission('digilib.books.manage') ? '/digilib/kelola' : '/dashboard') : '/login'" class="rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white">
          {{ auth.isAuthenticated ? (auth.hasPermission('digilib.books.manage') ? 'Panel Pengelola' : 'Dashboard ASC') : 'Masuk' }}
        </a>
      </div>
    </header>

    <main class="mx-auto max-w-7xl space-y-7 px-4 py-7">
      <section class="rounded-3xl bg-gradient-to-r from-blue-950 to-blue-700 p-6 text-white sm:p-9">
        <span class="text-xs font-semibold uppercase tracking-widest text-blue-100">PERPUSTAKAAN DIGITAL</span>
        <h1 class="mt-2 text-3xl font-bold">Koleksi untuk belajar dan bertumbuh</h1>
        <p class="mt-2 max-w-2xl text-sm text-blue-50">Cari koleksi fisik dan digital kampus, kelola reservasi, dan jelajahi karya ilmiah melalui Repository ASC.</p>
        <div class="mt-5 grid gap-2 sm:grid-cols-2">
          <label class="flex items-center gap-2 rounded-xl bg-white px-3 text-gray-500">
            <MagnifyingGlassIcon class="h-5 w-5" />
            <input v-model="search" class="w-full border-0 py-3 text-sm text-gray-900 outline-none ring-0" placeholder="Cari judul, penulis, ISBN..." />
          </label>
          <div class="flex flex-wrap gap-2">
            <button class="rounded-xl border border-white/30 px-4 py-2 text-sm hover:bg-white/10" @click="procurementOpen = true">Usulkan Buku</button>
            <a href="/repository" class="rounded-xl border border-white/30 px-4 py-2 text-center text-sm hover:bg-white/10">Buka Repository ASC</a>
          </div>
        </div>
      </section>

      <section v-if="announcements.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
        <h2 class="font-semibold text-amber-900">Pengumuman Perpustakaan</h2>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
          <article v-for="item in announcements" :key="item.id">
            <p class="text-sm font-medium text-amber-900">{{ item.title }}</p>
            <p class="text-xs text-amber-800">{{ item.body }}</p>
          </article>
        </div>
      </section>

      <div class="grid gap-5 lg:grid-cols-[240px_1fr]">
        <aside class="h-fit space-y-4 rounded-2xl border border-gray-200 bg-white p-4">
          <h2 class="font-semibold text-gray-900">Filter koleksi</h2>
          <label class="block text-xs font-medium text-gray-600">Kategori
            <select v-model="category" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm">
              <option value="">Semua kategori</option>
              <option v-for="item in filters.categories" :key="item" :value="item">{{ item }}</option>
            </select>
          </label>
          <label class="block text-xs font-medium text-gray-600">Tahun terbit
            <select v-model="year" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm">
              <option value="">Semua tahun</option>
              <option v-for="item in filters.years" :key="item" :value="item">{{ item }}</option>
            </select>
          </label>
          <label class="block text-xs font-medium text-gray-600">Format
            <select v-model="accessType" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm">
              <option value="">Semua format</option>
              <option value="physical">Buku fisik</option>
              <option value="digital">E-book</option>
              <option value="both">Fisik dan digital</option>
            </select>
          </label>
          <RouterLink v-if="auth.isAuthenticated" to="/perpustakaan/akun" class="block rounded-lg bg-blue-50 px-3 py-2 text-sm font-medium text-blue-800">Pinjaman, reservasi & favorit →</RouterLink>
        </aside>

        <section>
          <div class="mb-3 flex items-center justify-between">
            <h2 class="font-semibold text-gray-900">Katalog buku <span class="text-sm font-normal text-gray-500">({{ total }})</span></h2>
            <a href="/repository" class="text-sm font-medium text-blue-700">Repository karya ilmiah ASC →</a>
          </div>
          <div v-if="loading" class="rounded-xl bg-white py-14 text-center text-gray-500">Memuat katalog...</div>
          <div v-else-if="!books.length" class="rounded-xl bg-white py-14 text-center text-gray-500">Belum ada buku yang cocok dengan pencarian ini.</div>
          <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="book in books" :key="book.id" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
              <button class="flex h-48 w-full items-center justify-center bg-gray-50 p-4" @click="openBook(book)">
                <img v-if="book.cover_url" :src="book.cover_url" :alt="book.title" class="h-full max-w-full object-contain" />
                <BookOpenIcon v-else class="h-16 w-16 text-blue-200" />
              </button>
              <div class="space-y-2 p-4">
                <div class="flex items-center justify-between gap-2">
                  <span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-800">{{ book.category || 'Koleksi' }}</span>
                  <button :aria-label="book.is_favorite ? 'Hapus favorit' : 'Simpan favorit'" @click="toggleFavorite(book)">
                    <HeartIcon class="h-5 w-5" :class="book.is_favorite ? 'fill-rose-500 text-rose-500' : 'text-gray-400'" />
                  </button>
                </div>
                <button class="block text-left" @click="openBook(book)">
                  <h3 class="line-clamp-2 font-semibold text-gray-900">{{ book.title }}</h3>
                  <p class="mt-1 text-sm text-gray-500">{{ book.author }}</p>
                </button>
                <div class="flex items-center justify-between text-xs text-gray-500">
                  <span>{{ book.publish_year || 'Tahun tidak tersedia' }}</span>
                  <span>{{ book.available_copies }} tersedia / {{ accessLabel[book.access_type] }}</span>
                </div>
              </div>
            </article>
          </div>
          <div v-if="lastPage > 1" class="mt-5 flex justify-center gap-2">
            <button v-for="number in lastPage" :key="number" class="rounded-lg px-3 py-2 text-sm"
              :class="page === number ? 'bg-blue-700 text-white' : 'bg-white text-gray-700'"
              @click="load(number)">{{ number }}</button>
          </div>
        </section>
      </div>
    </main>

    <div v-if="selected" class="fixed inset-0 z-40 flex items-center justify-center bg-black/50 p-4" @click.self="selected = null">
      <section class="max-h-[90vh] w-full max-w-2xl overflow-auto rounded-2xl bg-white p-5">
        <button class="mb-4 flex items-center gap-1 text-sm text-gray-500" @click="selected = null"><ArrowLeftIcon class="h-4 w-4" /> Kembali</button>
        <div class="grid gap-5 sm:grid-cols-[150px_1fr]">
          <img v-if="selected.cover_url" :src="selected.cover_url" :alt="selected.title" class="max-h-56 w-full rounded-lg bg-gray-50 object-contain p-2" />
          <BookOpenIcon v-else class="h-24 w-24 text-blue-200" />
          <div>
            <p class="text-xs font-medium uppercase text-blue-700">{{ selected.category || 'Koleksi Perpustakaan' }}</p>
            <h2 class="mt-1 text-xl font-bold text-gray-900">{{ selected.title }}</h2>
            <p class="mt-1 text-sm text-gray-600">{{ selected.subtitle }}</p>
            <p class="mt-2 text-sm text-gray-700">Penulis: {{ selected.author }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-500">
              <div><dt>ISBN</dt><dd class="font-medium text-gray-700">{{ selected.isbn || '-' }}</dd></div>
              <div><dt>Penerbit / Tahun</dt><dd class="font-medium text-gray-700">{{ selected.publisher || '-' }} / {{ selected.publish_year || '-' }}</dd></div>
              <div><dt>Program Studi</dt><dd class="font-medium text-gray-700">{{ selected.study_program?.name || 'Umum' }}</dd></div>
              <div><dt>Lokasi Rak</dt><dd class="font-medium text-gray-700">{{ selected.shelf || '-' }}</dd></div>
            </dl>
          </div>
        </div>
        <p class="mt-5 whitespace-pre-line text-sm leading-6 text-gray-600">{{ selected.description || 'Deskripsi belum tersedia.' }}</p>
        <div class="mt-5 flex flex-wrap gap-2">
          <button v-if="selected.available_copies > 0 && ['physical','both'].includes(selected.access_type)"
            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white" @click="toast.info('Peminjaman buku fisik diproses melalui pustakawan.')">
            Tersedia {{ selected.available_copies }} · Pinjam di Perpustakaan
          </button>
          <button v-else-if="['physical','both'].includes(selected.access_type)" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white" @click="reserve(selected)">Reservasi antrean</button>
          <button v-if="selected.has_digital_file" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm" @click="downloadBook(selected)">
            <ArrowDownTrayIcon class="h-4 w-4" /> Baca / unduh e-book
          </button>
          <button class="rounded-lg border border-gray-300 px-4 py-2 text-sm" @click="toggleFavorite(selected)">{{ selected.is_favorite ? '★ Tersimpan' : '☆ Simpan favorit' }}</button>
        </div>
        <p class="mt-3 text-xs text-gray-500">Peminjaman fisik, pengembalian, perpanjangan, dan pembayaran denda dilayani pustakawan menggunakan akun ASC.</p>
      </section>
    </div>

    <div v-if="procurementOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="procurementOpen = false">
      <form class="w-full max-w-lg space-y-3 rounded-2xl bg-white p-5" @submit.prevent="submitProcurement">
        <h2 class="text-lg font-semibold">Usulan Pengadaan Buku</h2>
        <input v-model="procurement.title" required class="w-full rounded-lg border p-2 text-sm" placeholder="Judul buku" />
        <input v-model="procurement.author" class="w-full rounded-lg border p-2 text-sm" placeholder="Penulis" />
        <input v-model="procurement.isbn" class="w-full rounded-lg border p-2 text-sm" placeholder="ISBN" />
        <textarea v-model="procurement.reason" class="w-full rounded-lg border p-2 text-sm" placeholder="Alasan pengadaan" />
        <div class="flex justify-end gap-2"><button type="button" class="rounded-lg px-3 py-2 text-sm" @click="procurementOpen = false">Batal</button><button :disabled="submitting" class="rounded-lg bg-blue-700 px-3 py-2 text-sm text-white">Kirim Usulan</button></div>
      </form>
    </div>

  </div>
</template>
