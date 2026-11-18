<script setup lang="ts">
import { ref,onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
const router=useRouter(), items=ref<any[]>([]), loading=ref(true), search=ref('')
const auth=useAuthStore()
async function load(){loading.value=true;try{const {data}=await api.get('/meeting-minutes',{params:{search:search.value,per_page:50}});items.value=data.data??data}catch{}finally{loading.value=false}}
onMounted(load)
const labels:any={DRAFT:'Draft',MENUNGGU_PEMERIKSA:'Menunggu Pemeriksa',MENUNGGU_PENANDATANGAN:'Menunggu TTD',REVISI_PEMERIKSA:'Revisi',REVISI_PENANDATANGAN:'Revisi',DITANDATANGANI:'Ditandatangani'}
</script>
<template><div class="space-y-5"><div class="flex justify-between items-center"><div><h1 class="text-xl font-bold">{{auth.hasRole('DOSEN') && !auth.hasPermission('notulen.create') ? 'Tanda Tangan Notulen' : 'Notulen Rapat'}}</h1><p class="text-sm text-gray-500">Dokumentasi rapat dan persetujuan tanda tangan</p></div><button v-if="auth.hasPermission('notulen.create')" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm" @click="router.push('/agenda/notulen/buat')">+ Buat Notulen</button></div><input v-model="search" @input="load" placeholder="Cari judul rapat..." class="px-3 py-2 border rounded-lg text-sm w-64"><div v-if="loading" class="text-center py-10 text-gray-400">Memuat...</div><div v-else class="bg-white rounded-xl border overflow-hidden"><table class="w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="p-3">Nomor</th><th class="p-3">Judul</th><th class="p-3">Tanggal</th><th class="p-3">Status</th><th class="p-3"></th></tr></thead><tbody><tr v-for="m in items" :key="m.id" class="border-t"><td class="p-3 font-mono text-xs">{{m.number||'Draft'}}</td><td class="p-3 font-medium">{{m.title}}</td><td class="p-3">{{m.meeting_date}}</td><td class="p-3"><span class="px-2 py-1 rounded bg-gray-100 text-xs">{{labels[m.status]||m.status}}</span></td><td class="p-3 text-right"><button class="text-blue-600" @click="router.push(`/agenda/notulen/${m.id}`)">Lihat</button></td></tr></tbody></table><p v-if="!items.length" class="p-8 text-center text-gray-400">Belum ada notulen.</p></div></div></template>
