<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useToast } from 'vue-toastification'
import api from '@/services/api'

const toast = useToast()
const activeTab = ref<'loans' | 'reservations' | 'favorites'>('loans')
const loans = ref<any[]>([])
const reservations = ref<any[]>([])
const favorites = ref<any[]>([])
const loading = ref(false)
const memberTabs = [
  { id: 'loans', label: 'Pinjaman' },
  { id: 'reservations', label: 'Reservasi' },
  { id: 'favorites', label: 'Favorit' },
] as const

onMounted(loadData)

async function loadData() {
  loading.value = true
  try {
    const [loanRes, reservationRes, favoriteRes] = await Promise.all([
      api.get('/library/me/loans'),
      api.get('/library/me/reservations'),
      api.get('/library/me/favorites'),
    ])
    loans.value = loanRes.data.data
    reservations.value = reservationRes.data
    favorites.value = favoriteRes.data.data
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Layanan perpustakaan gagal dimuat.')
  } finally {
    loading.value = false
  }
}

async function renew(loan: any) {
  try {
    const { data } = await api.post(`/library/me/loans/${loan.id}/renew`)
    toast.success(data.message)
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.errors?.loan?.[0] ?? error?.response?.data?.message ?? 'Pinjaman gagal diperpanjang.')
  }
}

async function cancelReservation(reservation: any) {
  try {
    const { data } = await api.delete(`/library/me/reservations/${reservation.id}`)
    toast.success(data.message)
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Reservasi gagal dibatalkan.')
  }
}

async function collectReservation(reservation: any) {
  try {
    const { data } = await api.post(`/library/me/reservations/${reservation.id}/collect`)
    toast.success(data.message)
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Reservasi gagal diproses.')
  }
}

async function toggleFavorite(book: any) {
  try {
    const { data } = await api.post(`/library/me/books/${book.id}/favorite`)
    toast.success(data.favorited ? 'Buku disimpan ke favorit.' : 'Buku dihapus dari favorit.')
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Favorit gagal diperbarui.')
  }
}

function selectTab(tab: typeof memberTabs[number]['id']) {
  activeTab.value = tab
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5 p-4 sm:p-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">DIGITAL LIBRARY</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900">Layanan Perpustakaan Saya</h1>
        <p class="text-sm text-gray-500">Pantau peminjaman, reservasi, dan buku favorit Anda.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="/repository" class="rounded-lg border border-blue-200 px-3 py-2 text-sm text-blue-700">Repository ASC</a>
        <RouterLink to="/perpustakaan" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700">Jelajahi Katalog</RouterLink>
      </div>
    </header>

    <nav class="flex flex-wrap gap-2">
      <button v-for="tab in memberTabs" :key="tab.id" class="rounded-lg px-4 py-2 text-sm font-medium"
        :class="activeTab === tab.id ? 'bg-blue-700 text-white' : 'border border-gray-200 bg-white text-gray-600'"
        @click="selectTab(tab.id)">
        {{ tab.label }} ({{ tab.id === 'loans' ? loans.length : tab.id === 'reservations' ? reservations.length : favorites.length }})
      </button>
    </nav>

    <section v-if="loading" class="rounded-xl border bg-white p-10 text-center text-sm text-gray-500">Memuat layanan anggota...</section>
    <section v-else-if="activeTab === 'loans'" class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
      <table class="w-full min-w-[700px] text-left text-sm">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500"><tr><th class="p-4">Buku</th><th class="p-4">Kode</th><th class="p-4">Dipinjam</th><th class="p-4">Jatuh Tempo</th><th class="p-4">Status / Denda</th><th class="p-4"></th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="loan in loans" :key="loan.id">
            <td class="p-4"><p class="font-medium">{{ loan.book?.title }}</p><p class="text-xs text-gray-500">{{ loan.book?.author }}</p></td>
            <td class="p-4">{{ loan.loan_code }}</td><td class="p-4">{{ new Date(loan.borrowed_at).toLocaleDateString('id-ID') }}</td>
            <td class="p-4">{{ new Date(loan.due_at).toLocaleDateString('id-ID') }}</td>
            <td class="p-4"><p>{{ loan.status }}</p><p v-if="Number(loan.fine_amount)>0" class="text-xs text-amber-700">Denda Rp {{ Number(loan.fine_amount).toLocaleString('id-ID') }} · terbayar Rp {{ Number(loan.fine_paid).toLocaleString('id-ID') }}</p></td>
            <td class="p-4 text-right"><button v-if="['borrowed','overdue'].includes(loan.status) && loan.renewal_count < 1" class="text-sm font-medium text-blue-700" @click="renew(loan)">Perpanjang</button></td>
          </tr>
          <tr v-if="!loans.length"><td colspan="6" class="p-10 text-center text-gray-500">Belum ada riwayat peminjaman.</td></tr>
        </tbody>
      </table>
    </section>
    <section v-else-if="activeTab === 'reservations'" class="grid gap-3 md:grid-cols-2">
      <article v-for="reservation in reservations" :key="reservation.id" class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4">
        <div><p class="font-semibold">{{ reservation.book?.title }}</p><p class="text-sm text-gray-500">{{ reservation.book?.author }} · antrean {{ reservation.queue_no }}</p><p class="mt-1 text-xs text-blue-700">{{ reservation.status }}<span v-if="reservation.expires_at"> · berlaku sampai {{ new Date(reservation.expires_at).toLocaleString('id-ID') }}</span></p></div>
        <div class="flex shrink-0 flex-col gap-2 text-right">
          <button v-if="reservation.status === 'ready'" class="text-sm font-medium text-blue-700" @click="collectReservation(reservation)">Proses Pinjam</button>
          <button v-if="['waiting','ready'].includes(reservation.status)" class="text-sm text-red-600" @click="cancelReservation(reservation)">Batalkan</button>
        </div>
      </article>
      <p v-if="!reservations.length" class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-500 md:col-span-2">Belum ada reservasi aktif.</p>
    </section>
    <section v-else-if="activeTab === 'favorites'" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <article v-for="book in favorites" :key="book.id" class="flex justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4">
        <div><p class="font-semibold text-blue-800">{{ book.title }}</p><p class="mt-1 text-sm text-gray-500">{{ book.author }}</p><p class="mt-1 text-xs text-gray-400">{{ book.category || 'Tanpa kategori' }}</p></div>
        <button class="shrink-0 text-sm text-red-600" @click="toggleFavorite(book)">Hapus</button>
      </article>
      <p v-if="!favorites.length" class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-500 sm:col-span-2 lg:col-span-3">Belum ada buku favorit.</p>
    </section>
  </div>
</template>
