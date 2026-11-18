<script setup lang="ts">
import { onMounted, ref, reactive } from 'vue'
import { ArrowDownTrayIcon, ArrowUpTrayIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import api from '@/services/api'

const toast = useToast()
const tabs = [
  { id: 'books', label: 'Katalog Buku' },
  { id: 'circulation', label: 'Sirkulasi' },
  { id: 'procurement', label: 'Pengadaan' },
  { id: 'visits', label: 'Kunjungan' },
  { id: 'reports', label: 'Laporan' },
  { id: 'settings', label: 'Pengaturan' },
]
const activeTab = ref('books')
const dashboard = ref<any>(null)
const books = ref<any[]>([])
const loans = ref<any[]>([])
const reservations = ref<any[]>([])
const procurementRequests = ref<any[]>([])
const visits = ref<any[]>([])
const report = ref<any>(null)
const settings = reactive({
  library_name: '', tagline: '', loan_days: 14, loan_limit: 3, fine_per_day: 1000,
  reservation_hold_hours: 48,
})
const search = ref('')
const busy = ref(false)
const importInput = ref<HTMLInputElement | null>(null)
const announcementTitle = ref<HTMLInputElement | null>(null)
const announcementBody = ref<HTMLTextAreaElement | null>(null)
const bookCover = ref<File | null>(null)
const bookDigitalFile = ref<File | null>(null)
const checkoutUsers = ref<any[]>([])
const checkoutBooks = ref<any[]>([])
const checkoutSearch = ref('')
const bookModal = ref(false)
const editingId = ref<number | null>(null)
const bookForm = reactive({
  title: '', subtitle: '', author: '', isbn: '', accession_no: '', barcode: '',
  publisher: '', publish_year: new Date().getFullYear(), language: 'Indonesia',
  category: '', study_program_id: '', classification: '', shelf: '', description: '',
  access_type: 'physical', total_copies: 1, available_copies: 1, status: 'published',
})
const requestStates: Record<string, string> = {
  submitted: 'Diajukan', reviewed: 'Ditinjau', approved: 'Disetujui', purchased: 'Dibeli', rejected: 'Ditolak',
}
const visitForm = reactive({ user_id: '', visitor_name: '', purpose: '' })
const checkoutForm = reactive({ user_id: '', book_id: '' })

onMounted(async () => {
  try {
    const { data } = await api.get('/library/manage/dashboard')
    dashboard.value = data
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Ringkasan perpustakaan gagal dimuat.')
  }
  await loadTab()
})

async function loadTab() {
  busy.value = true
  try {
    if (activeTab.value === 'books') {
      const { data } = await api.get('/library/manage/books', { params: { search: search.value || undefined, per_page: 50 } })
      books.value = data.data
    } else if (activeTab.value === 'circulation') {
      const [loanRes, reservationRes] = await Promise.all([
        api.get('/library/manage/loans', { params: { search: search.value || undefined } }),
        api.get('/library/manage/reservations'),
      ])
      loans.value = loanRes.data.data
      reservations.value = reservationRes.data.data
      await loadCheckoutOptions()
    } else if (activeTab.value === 'procurement') {
      const { data } = await api.get('/library/manage/procurement-requests')
      procurementRequests.value = data.data
    } else if (activeTab.value === 'visits') {
      const { data } = await api.get('/library/manage/visits')
      visits.value = data.data
      await loadCheckoutOptions()
    } else if (activeTab.value === 'reports') {
      const { data } = await api.get('/library/manage/reports')
      report.value = data
    } else if (activeTab.value === 'settings') {
      const { data } = await api.get('/library/manage/settings')
      Object.assign(settings, {
        library_name: data.library_name ?? '',
        tagline: data.tagline ?? '',
        loan_days: data.loan_days ?? 14,
        loan_limit: data.loan_limit ?? 3,
        fine_per_day: data.fine_per_day ?? 1000,
        reservation_hold_hours: data.reservation_hold_hours ?? 48,
      })
    }
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Data modul perpustakaan gagal dimuat.')
  } finally {
    busy.value = false
  }
}

function selectTab(tab: string) {
  activeTab.value = tab
  loadTab()
}

function openBook(book?: any) {
  editingId.value = book?.id ?? null
  bookCover.value = null
  bookDigitalFile.value = null
  Object.assign(bookForm, {
    title: book?.title ?? '', subtitle: book?.subtitle ?? '', author: book?.author ?? '',
    isbn: book?.isbn ?? '', accession_no: book?.accession_no ?? '', barcode: book?.barcode ?? '',
    publisher: book?.publisher ?? '', publish_year: book?.publish_year ?? new Date().getFullYear(),
    language: book?.language ?? 'Indonesia', category: book?.category ?? '',
    study_program_id: book?.study_program_id ? String(book.study_program_id) : '',
    classification: book?.classification ?? '', shelf: book?.shelf ?? '', description: book?.description ?? '',
    access_type: book?.access_type ?? 'physical', total_copies: book?.total_copies ?? 1,
    available_copies: book?.available_copies ?? 1, status: book?.status ?? 'published',
  })
  bookModal.value = true
}

async function saveBook() {
  busy.value = true
  try {
    const payload = { ...bookForm, study_program_id: bookForm.study_program_id || null }
    const { data } = editingId.value
      ? await api.put(`/library/manage/books/${editingId.value}`, payload)
      : await api.post('/library/manage/books', payload)
    const savedBookId = editingId.value ?? data.data.id
    if (bookCover.value || bookDigitalFile.value) {
      const files = new FormData()
      if (bookCover.value) files.append('cover', bookCover.value)
      if (bookDigitalFile.value) files.append('digital_file', bookDigitalFile.value)
      await api.post(`/library/manage/books/${savedBookId}/files`, files)
    }
    toast.success(data.message)
    bookModal.value = false
    await loadTab()
  } catch (error: any) {
    const firstError = error?.response?.data?.errors && Object.values(error.response.data.errors).flat()[0]
    toast.error(firstError ?? error?.response?.data?.message ?? 'Buku gagal disimpan.')
  } finally {
    busy.value = false
  }
}

async function deleteBook(book: any) {
  if (!confirm(`Hapus buku “${book.title}”?`)) return
  try {
    const { data } = await api.delete(`/library/manage/books/${book.id}`)
    toast.success(data.message)
    await loadTab()
  } catch (error: any) {
    toast.error(error?.response?.data?.errors?.book?.[0] ?? error?.response?.data?.message ?? 'Buku gagal dihapus.')
  }
}

async function importBooks(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  busy.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const { data } = await api.post('/library/manage/books/import', form)
    toast.success(data.message)
    data.warnings?.slice(0, 3).forEach((warning: string) => toast.warning(warning))
    await loadTab()
  } catch (error: any) {
    toast.error(error?.response?.data?.errors?.file?.[0] ?? error?.response?.data?.message ?? 'Impor buku gagal.')
  } finally {
    busy.value = false
    input.value = ''
  }
}

async function loanAction(loan: any, action: 'return' | 'lost') {
  try {
    const { data } = await api.post(`/library/manage/loans/${loan.id}/${action}`)
    toast.success(data.message)
    await loadTab()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Transaksi gagal diproses.')
  }
}

async function payFine(loan: any) {
  const unpaid = Number(loan.fine_amount) - Number(loan.fine_paid)
  const amount = prompt(`Denda belum dibayar Rp ${unpaid.toLocaleString('id-ID')}. Masukkan nominal pembayaran:`)
  if (!amount) return
  try {
    const { data } = await api.post(`/library/manage/loans/${loan.id}/fine-payment`, { amount })
    toast.success(data.message)
    await loadTab()
  } catch (error: any) {
    toast.error(error?.response?.data?.errors?.amount?.[0] ?? error?.response?.data?.message ?? 'Pembayaran gagal dicatat.')
  }
}

async function updateProcurement(item: any, status: string) {
  try {
    const { data } = await api.put(`/library/manage/procurement-requests/${item.id}`, { status })
    toast.success(data.message)
    await loadTab()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Usulan gagal diperbarui.')
  }
}

async function saveSettings() {
  try {
    const { data } = await api.put('/library/manage/settings', settings)
    toast.success(data.message)
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Pengaturan gagal disimpan.')
  }
}

async function checkoutVisit(visit: any) {
  try {
    const { data } = await api.post(`/library/manage/visits/${visit.id}/checkout`)
    toast.success(data.message)
    await loadTab()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Kunjungan gagal ditutup.')
  }
}

async function recordVisit() {
  try {
    const { data } = await api.post('/library/manage/visits', {
      user_id: visitForm.user_id ? Number(visitForm.user_id) : null,
      visitor_name: visitForm.visitor_name || null,
      purpose: visitForm.purpose || null,
    })
    toast.success(data.message)
    Object.assign(visitForm, { user_id: '', visitor_name: '', purpose: '' })
    await loadTab()
  } catch (error: any) {
    const firstError = error?.response?.data?.errors && Object.values(error.response.data.errors).flat()[0]
    toast.error(firstError ?? error?.response?.data?.message ?? 'Kunjungan gagal dicatat.')
  }
}

async function createAnnouncement() {
  try {
    const { data } = await api.post('/library/manage/announcements', {
      title: announcementTitle.value?.value,
      body: announcementBody.value?.value,
      is_published: true,
    })
    toast.success(data.message)
    if (announcementTitle.value) announcementTitle.value.value = ''
    if (announcementBody.value) announcementBody.value.value = ''
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Pengumuman gagal disimpan.')
  }
}

async function createLoan() {
  try {
    const { data } = await api.post('/library/manage/loans', {
      user_id: Number(checkoutForm.user_id),
      book_id: Number(checkoutForm.book_id),
    })
    toast.success(data.message)
    Object.assign(checkoutForm, { user_id: '', book_id: '' })
    await loadTab()
  } catch (error: any) {
    toast.error(error?.response?.data?.errors?.user_id?.[0] ?? error?.response?.data?.errors?.book_id?.[0] ?? error?.response?.data?.message ?? 'Peminjaman gagal.')
  }
}

async function loadCheckoutOptions() {
  try {
    const [userRes, bookRes] = await Promise.all([
      api.get('/user-list', { params: { search: checkoutSearch.value || undefined, per_page: 50 } }),
      api.get('/library/manage/books', { params: { search: checkoutSearch.value || undefined, status: 'published', per_page: 50 } }),
    ])
    checkoutUsers.value = userRes.data
    checkoutBooks.value = bookRes.data.data
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Pilihan anggota dan buku gagal dimuat.')
  }
}

async function downloadImportTemplate() {
  try {
    const { data } = await api.get('/library/manage/books/import-template', { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = 'template-impor-buku-digilib.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.error('Template gagal diunduh.')
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-5 sm:px-6 lg:px-8">
    <header class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-blue-100 bg-white p-5 shadow-sm sm:p-6">
      <div class="min-w-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">DIGITAL LIBRARY · PANEL PENGELOLA</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-gray-900">{{ settings.library_name || 'Perpustakaan Digital' }}</h1>
        <p class="mt-1 text-sm text-gray-500">Kelola koleksi, sirkulasi, pengadaan, dan layanan anggota dalam satu tempat.</p>
      </div>
      <div class="flex shrink-0 flex-wrap gap-2">
        <a href="/repository" class="rounded-xl border border-blue-200 px-4 py-2.5 text-sm font-medium text-blue-700 transition hover:bg-blue-50">Repository NURIS</a>
        <RouterLink to="/perpustakaan" class="rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">Buka Katalog</RouterLink>
      </div>
    </header>

    <div v-if="dashboard" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6 xl:gap-4">
      <article v-for="metric in [
        { label: 'Judul Buku', value: dashboard.books },
        { label: 'Eksemplar', value: dashboard.physical_copies },
        { label: 'Pinjaman Aktif', value: dashboard.active_loans },
        { label: 'Terlambat', value: dashboard.overdue_loans },
        { label: 'Reservasi', value: dashboard.pending_reservations },
        { label: 'Kunjungan Hari Ini', value: dashboard.today_visits },
      ]" :key="metric.label" class="min-w-0 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <p class="text-2xl font-bold tracking-tight text-blue-800">{{ metric.value }}</p>
        <p class="mt-1 text-xs leading-5 text-gray-500 sm:text-sm">{{ metric.label }}</p>
      </article>
    </div>

    <nav aria-label="Bagian pengelolaan perpustakaan" class="grid grid-cols-2 gap-2 rounded-2xl border border-gray-200 bg-white p-2 sm:grid-cols-3 xl:grid-cols-6">
      <button v-for="tab in tabs" :key="tab.id" class="min-w-0 rounded-xl px-2 py-3 text-center text-xs font-medium transition-colors sm:px-3 sm:text-sm"
        :class="activeTab === tab.id ? 'bg-blue-700 text-white shadow-sm' : 'text-gray-600 hover:bg-blue-50 hover:text-blue-800'"
        @click="selectTab(tab.id)">{{ tab.label }}</button>
    </nav>

    <section v-if="busy" class="rounded-xl border border-gray-200 bg-white py-12 text-center text-sm text-gray-500">Memuat data perpustakaan...</section>
    <template v-else>
      <section v-if="activeTab === 'books'" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="mr-auto font-semibold">Kelola Katalog Buku</h2>
          <input v-model="search" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="Cari judul/penulis/barcode" @input="loadTab" />
          <button class="inline-flex items-center gap-1 rounded-lg bg-blue-700 px-3 py-2 text-sm text-white" @click="openBook()"><PlusIcon class="h-4 w-4" /> Tambah Buku</button>
          <button class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm" @click="downloadImportTemplate"><ArrowDownTrayIcon class="h-4 w-4" /> Template CSV</button>
          <button class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm" :disabled="busy" @click="importInput?.click()"><ArrowUpTrayIcon class="h-4 w-4" /> Impor arsip</button>
          <input ref="importInput" type="file" accept=".zip,application/zip" class="hidden" @change="importBooks" />
        </div>
        <p class="text-xs text-gray-500">Impor hanya buku, sampul, dan PDF. Arsip ZIP berisi <code>books.csv</code> serta berkas pada folder <code>covers/</code> dan <code>books/</code>; akun dan transaksi Digilib lama tidak diimpor.</p>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[760px] text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500"><tr><th class="p-3">Koleksi</th><th class="p-3">Kategori</th><th class="p-3">Lokasi</th><th class="p-3">Eksemplar</th><th class="p-3">Status</th><th class="p-3"></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="book in books" :key="book.id">
                <td class="p-3"><p class="font-medium text-gray-900">{{ book.title }}</p><p class="text-xs text-gray-500">{{ book.author }} · {{ book.isbn || 'ISBN -' }}</p></td>
                <td class="p-3 text-gray-600">{{ book.category || '-' }}</td><td class="p-3 text-gray-600">{{ book.shelf || '-' }}</td>
                <td class="p-3 text-gray-600">{{ book.available_copies }}/{{ book.total_copies }}</td><td class="p-3 text-gray-600">{{ book.status }}</td>
                <td class="p-3 text-right"><button class="mr-2 text-blue-700" @click="openBook(book)">Edit</button><button class="text-red-600" @click="deleteBook(book)">Hapus</button></td>
              </tr>
              <tr v-if="!books.length"><td colspan="6" class="p-8 text-center text-gray-500">Belum ada buku dalam katalog.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-else-if="activeTab === 'circulation'" class="space-y-5">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
          <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">Pinjaman & pengembalian</h2><input v-model="search" class="rounded-lg border px-3 py-2 text-sm" placeholder="Cari anggota atau judul" @input="loadTab" /></div>
          <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500"><tr><th class="p-3">Anggota / Koleksi</th><th class="p-3">Kode</th><th class="p-3">Jatuh Tempo</th><th class="p-3">Status</th><th class="p-3">Denda / Lunas</th><th class="p-3"></th></tr></thead>
            <tbody class="divide-y divide-gray-100"><tr v-for="loan in loans" :key="loan.id">
              <td class="p-3"><p class="font-medium">{{ loan.user?.name }}</p><p class="text-xs text-gray-500">{{ loan.book?.title }}</p></td><td class="p-3">{{ loan.loan_code }}</td>
              <td class="p-3">{{ new Date(loan.due_at).toLocaleDateString('id-ID') }}</td><td class="p-3">{{ loan.status }}</td>
              <td class="p-3">Rp {{ Number(loan.fine_amount).toLocaleString('id-ID') }} / Rp {{ Number(loan.fine_paid).toLocaleString('id-ID') }}</td>
              <td class="p-3 text-right"><button v-if="Number(loan.fine_amount)>Number(loan.fine_paid)" class="mr-2 text-blue-700" @click="payFine(loan)">Bayar Denda</button><button v-if="['borrowed','overdue'].includes(loan.status)" class="mr-2 text-blue-700" @click="loanAction(loan,'return')">Kembali</button><button v-if="['borrowed','overdue'].includes(loan.status)" class="text-red-600" @click="loanAction(loan,'lost')">Hilang</button></td>
            </tr></tbody>
          </table></div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
          <h2 class="mb-3 font-semibold">Antrean Reservasi</h2>
          <div class="grid gap-2 md:grid-cols-2"><article v-for="reservation in reservations" :key="reservation.id" class="flex items-center justify-between rounded-lg border p-3">
            <div><p class="text-sm font-medium">{{ reservation.book?.title }}</p><p class="text-xs text-gray-500">{{ reservation.user?.name }} · Antrean {{ reservation.queue_no }} · {{ reservation.status }}</p></div>
            <button v-if="reservation.status === 'ready'" class="text-xs font-medium text-blue-700" @click="api.post(`/library/manage/reservations/${reservation.id}/collect`).then(({data})=>{toast.success(data.message);loadTab()}).catch(()=>toast.error('Reservasi gagal diproses.'))">Proses Pinjam</button>
          </article></div>
        </div>
        <form class="grid gap-2 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-4" @submit.prevent="createLoan">
          <h2 class="font-semibold sm:col-span-4">Catat Peminjaman</h2>
          <input v-model="checkoutSearch" class="rounded-lg border px-3 py-2 text-sm" placeholder="Cari anggota/buku" @input="loadCheckoutOptions" />
          <select v-model="checkoutForm.user_id" required class="rounded-lg border px-3 py-2 text-sm"><option value="" disabled>Pilih anggota NURIS</option><option v-for="user in checkoutUsers" :key="user.id" :value="String(user.id)">{{ user.name }} · {{ user.role_label }}</option></select>
          <select v-model="checkoutForm.book_id" required class="rounded-lg border px-3 py-2 text-sm"><option value="" disabled>Pilih buku</option><option v-for="book in checkoutBooks" :key="book.id" :value="String(book.id)">{{ book.title }} · tersedia {{ book.available_copies }}</option></select>
          <button class="rounded-lg bg-blue-700 px-3 py-2 text-sm text-white">Proses Pinjam</button>
        </form>
      </section>

      <section v-else-if="activeTab === 'procurement'" class="overflow-x-auto rounded-xl border border-gray-200 bg-white p-4">
        <h2 class="mb-3 font-semibold">Usulan Pengadaan Koleksi</h2>
        <table class="w-full min-w-[700px] text-left text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-500"><tr><th class="p-3">Usulan</th><th class="p-3">Pemohon</th><th class="p-3">Status</th><th class="p-3">Perbarui</th></tr></thead>
          <tbody class="divide-y divide-gray-100"><tr v-for="item in procurementRequests" :key="item.id"><td class="p-3"><p class="font-medium">{{ item.title }}</p><p class="text-xs text-gray-500">{{ item.author }} · {{ item.isbn }}</p></td><td class="p-3">{{ item.requester_name }}</td><td class="p-3">{{ requestStates[item.status] }}</td><td class="p-3"><select :value="item.status" class="rounded-lg border p-2 text-xs" @change="updateProcurement(item,($event.target as HTMLSelectElement).value)"><option v-for="(label,value) in requestStates" :key="value" :value="value">{{ label }}</option></select></td></tr></tbody>
        </table>
      </section>

      <section v-else-if="activeTab === 'visits'" class="overflow-x-auto rounded-xl border border-gray-200 bg-white p-4">
        <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">Kunjungan Perpustakaan</h2><p class="text-xs text-gray-500">Catatan 30 hari terakhir</p></div>
        <form class="mb-4 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-4" @submit.prevent="recordVisit">
          <select v-model="visitForm.user_id" class="rounded-lg border p-2 text-sm"><option value="">Pengunjung bukan anggota</option><option v-for="user in checkoutUsers" :key="user.id" :value="String(user.id)">{{ user.name }} · {{ user.role_label }}</option></select>
          <input v-model="visitForm.visitor_name" class="rounded-lg border p-2 text-sm" placeholder="Nama pengunjung (jika bukan anggota)" />
          <input v-model="visitForm.purpose" class="rounded-lg border p-2 text-sm" placeholder="Tujuan kunjungan" />
          <button class="rounded-lg bg-blue-700 px-3 py-2 text-sm text-white">Catat Masuk</button>
        </form>
        <table class="w-full min-w-[650px] text-left text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-500"><tr><th class="p-3">Pengunjung</th><th class="p-3">Tujuan</th><th class="p-3">Masuk</th><th class="p-3">Keluar</th><th class="p-3"></th></tr></thead>
          <tbody class="divide-y divide-gray-100"><tr v-for="visit in visits" :key="visit.id"><td class="p-3">{{ visit.member_name || visit.visitor_name }}</td><td class="p-3">{{ visit.purpose || '-' }}</td><td class="p-3">{{ new Date(visit.check_in_at).toLocaleString('id-ID') }}</td><td class="p-3">{{ visit.check_out_at ? new Date(visit.check_out_at).toLocaleString('id-ID') : 'Masih di perpustakaan' }}</td><td class="p-3 text-right"><button v-if="!visit.check_out_at" class="text-blue-700" @click="checkoutVisit(visit)">Catat Keluar</button></td></tr></tbody>
        </table>
      </section>

      <section v-else-if="activeTab === 'reports' && report" class="grid gap-4 md:grid-cols-2">
        <article class="space-y-2 rounded-xl border border-gray-200 bg-white p-4"><h2 class="font-semibold">Koleksi Buku · {{ report.year }}</h2><p>Jumlah judul buku: <b>{{ report.books.total }}</b></p><p>Buku terbit: <b>{{ report.books.published }}</b></p><p>Total pembacaan metadata: <b>{{ report.books.views }}</b></p></article>
        <article class="space-y-2 rounded-xl border border-gray-200 bg-white p-4"><h2 class="font-semibold">Sirkulasi · {{ report.year }}</h2><p>Peminjaman baru: <b>{{ report.circulation.borrowed }}</b></p><p>Pengembalian: <b>{{ report.circulation.returned }}</b></p><p>Terlambat: <b>{{ report.circulation.overdue }}</b></p><p>Denda tercatat: <b>Rp {{ Number(report.circulation.fine_assessed).toLocaleString('id-ID') }}</b></p><p>Denda dibayar: <b>Rp {{ Number(report.circulation.fine_paid).toLocaleString('id-ID') }}</b></p><p>Kunjungan: <b>{{ report.visits }}</b></p></article>
        <article class="rounded-xl border border-gray-200 bg-white p-4 md:col-span-2"><h2 class="mb-2 font-semibold">Koleksi Populer</h2><p v-for="book in report.top_books" :key="book.id" class="border-b py-2 text-sm">{{ book.title }} · {{ book.view_count }} dibaca</p></article>
      </section>

      <section v-else-if="activeTab === 'settings'" class="grid gap-5 lg:grid-cols-2">
        <form class="space-y-3 rounded-xl border border-gray-200 bg-white p-4" @submit.prevent="saveSettings">
          <h2 class="font-semibold">Kebijakan & Identitas</h2>
          <label class="block text-sm">Nama Perpustakaan<input v-model="settings.library_name" required class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="block text-sm">Tagline<input v-model="settings.tagline" class="mt-1 w-full rounded-lg border p-2" /></label>
          <div class="grid grid-cols-2 gap-3"><label class="text-sm">Masa pinjam (hari)<input v-model.number="settings.loan_days" type="number" min="1" max="90" class="mt-1 w-full rounded-lg border p-2" /></label><label class="text-sm">Batas pinjaman aktif<input v-model.number="settings.loan_limit" type="number" min="1" max="20" class="mt-1 w-full rounded-lg border p-2" /></label></div>
          <div class="grid grid-cols-2 gap-3"><label class="text-sm">Denda per hari<input v-model.number="settings.fine_per_day" type="number" min="0" class="mt-1 w-full rounded-lg border p-2" /></label><label class="text-sm">Tahan reservasi (jam)<input v-model.number="settings.reservation_hold_hours" type="number" min="1" max="168" class="mt-1 w-full rounded-lg border p-2" /></label></div>
          <button class="rounded-lg bg-blue-700 px-4 py-2 text-sm text-white">Simpan Kebijakan</button>
        </form>
        <form class="space-y-3 rounded-xl border border-gray-200 bg-white p-4" @submit.prevent="createAnnouncement">
          <h2 class="font-semibold">Buat Pengumuman</h2>
          <input ref="announcementTitle" required class="w-full rounded-lg border p-2 text-sm" placeholder="Judul pengumuman" />
          <textarea ref="announcementBody" required rows="5" class="w-full rounded-lg border p-2 text-sm" placeholder="Informasi untuk anggota" />
          <button class="rounded-lg bg-blue-700 px-4 py-2 text-sm text-white">Publikasikan</button>
          <p class="text-xs text-gray-500">Pengumuman yang dipublikasikan ditampilkan pada katalog Digital Library.</p>
        </form>
      </section>
    </template>

    <div v-if="bookModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="bookModal = false">
      <form class="max-h-[92vh] w-full max-w-3xl space-y-3 overflow-auto rounded-2xl bg-white p-5" @submit.prevent="saveBook">
        <h2 class="text-lg font-semibold">{{ editingId ? 'Perbarui Buku' : 'Tambah Buku' }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm">Judul *<input v-model="bookForm.title" required class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Penulis *<input v-model="bookForm.author" required class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Subjudul<input v-model="bookForm.subtitle" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">ISBN<input v-model="bookForm.isbn" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">No. Akses<input v-model="bookForm.accession_no" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Barcode<input v-model="bookForm.barcode" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Penerbit<input v-model="bookForm.publisher" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Tahun terbit<input v-model.number="bookForm.publish_year" type="number" min="1000" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Bahasa<input v-model="bookForm.language" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Kategori<input v-model="bookForm.category" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">ID program studi NURIS<input v-model="bookForm.study_program_id" type="number" min="1" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Klasifikasi<input v-model="bookForm.classification" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Rak<input v-model="bookForm.shelf" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Akses<select v-model="bookForm.access_type" class="mt-1 w-full rounded-lg border p-2"><option value="physical">Fisik</option><option value="digital">Digital</option><option value="both">Fisik dan digital</option></select></label>
          <label class="text-sm">Status<select v-model="bookForm.status" class="mt-1 w-full rounded-lg border p-2"><option value="published">Terbit</option><option value="draft">Draft</option><option value="archived">Arsip</option></select></label>
          <label class="text-sm">Total eksemplar<input v-model.number="bookForm.total_copies" type="number" min="0" class="mt-1 w-full rounded-lg border p-2" /></label>
          <label class="text-sm">Tersedia<input v-model.number="bookForm.available_copies" type="number" min="0" :max="bookForm.total_copies" class="mt-1 w-full rounded-lg border p-2" /></label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm">Sampul buku (JPG/PNG/WebP, maks. 5 MB)<input type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm" @change="bookCover = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
          <label class="text-sm">File digital (PDF, maks. 100 MB)<input type="file" accept="application/pdf" class="mt-1 block w-full text-sm" @change="bookDigitalFile = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
        </div>
        <label class="block text-sm">Deskripsi<textarea v-model="bookForm.description" rows="3" class="mt-1 w-full rounded-lg border p-2" /></label>
        <div class="flex justify-end gap-2"><button type="button" class="rounded-lg px-3 py-2 text-sm" @click="bookModal = false">Batal</button><button :disabled="busy" class="rounded-lg bg-blue-700 px-4 py-2 text-sm text-white">Simpan Buku</button></div>
      </form>
    </div>
  </div>
</template>
