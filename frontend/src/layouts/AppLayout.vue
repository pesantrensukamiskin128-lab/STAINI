<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterView, useRouter, useRoute } from 'vue-router'
import AppSidebar from '@/components/AppSidebar.vue'
import AppHeader from '@/components/AppHeader.vue'
import { useInstitutionStore } from '@/stores/institution'

const router = useRouter()
const route = useRoute()
const sidebarOpen   = ref(typeof window !== 'undefined' && window.innerWidth >= 1024)
const institutionStore = useInstitutionStore()

const FAB_SIZE = 56
const FAB_KEY = 'asc_scan_fab_pos'
const fabPos = ref<{ x: number; y: number } | null>(null)
let dragMoved = false
let dragOffset = { x: 0, y: 0 }

function clampPos(x: number, y: number) {
  return {
    x: Math.min(Math.max(0, x), window.innerWidth - FAB_SIZE),
    y: Math.min(Math.max(0, y), window.innerHeight - FAB_SIZE),
  }
}

function onFabDown(event: PointerEvent) {
  const el = event.currentTarget as HTMLElement
  const rect = el.getBoundingClientRect()
  dragOffset = { x: event.clientX - rect.left, y: event.clientY - rect.top }
  dragMoved = false
  el.setPointerCapture(event.pointerId)
}

function onFabMove(event: PointerEvent) {
  if (!(event.currentTarget as HTMLElement).hasPointerCapture(event.pointerId)) return
  const next = clampPos(event.clientX - dragOffset.x, event.clientY - dragOffset.y)
  if (!dragMoved && fabPos.value && Math.hypot(next.x - fabPos.value.x, next.y - fabPos.value.y) < 4) return
  dragMoved = true
  fabPos.value = next
}

function onFabUp(event: PointerEvent) {
  const el = event.currentTarget as HTMLElement
  if (el.hasPointerCapture(event.pointerId)) el.releasePointerCapture(event.pointerId)
  if (dragMoved && fabPos.value) {
    localStorage.setItem(FAB_KEY, JSON.stringify(fabPos.value))
  } else {
    router.push('/scan-qr')
  }
}

function onResize() {
  if (fabPos.value) fabPos.value = clampPos(fabPos.value.x, fabPos.value.y)
}

onMounted(() => {
  institutionStore.fetch()
  try {
    const saved = JSON.parse(localStorage.getItem(FAB_KEY) || 'null')
    if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) fabPos.value = clampPos(saved.x, saved.y)
  } catch { /* posisi default */ }
  window.addEventListener('resize', onResize)
})
onBeforeUnmount(() => window.removeEventListener('resize', onResize))
</script>

<template>
  <div class="flex h-screen bg-gray-50 overflow-hidden">
    <!-- Sidebar -->
    <AppSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <!-- Main content -->
    <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
      <AppHeader @toggle-sidebar="sidebarOpen = !sidebarOpen" />

      <main class="flex-1 overflow-y-auto p-6">
        <RouterView />
      </main>
    </div>

    <!-- Floating Scan QR Button -->
    <button
      v-if="route.path === '/dashboard'"
      @pointerdown="onFabDown"
      @pointermove="onFabMove"
      @pointerup="onFabUp"
      :style="fabPos ? { left: fabPos.x + 'px', top: fabPos.y + 'px', right: 'auto', bottom: 'auto' } : undefined"
      class="fixed bottom-6 right-6 z-40 w-14 h-14 touch-none select-none cursor-grab active:cursor-grabbing bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg hover:shadow-xl flex items-center justify-center group"
      title="Scan QR Code (geser untuk memindahkan)"
    >
      <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h4V3H2v5h1V4zM20 4h-4V3h5v5h-1V4zM4 20v-4H3v5h5v-1H4zM20 20v-4h1v5h-5v-1h4z"/>
        <rect x="7" y="7" width="4" height="4" rx="0.5" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="13" y="7" width="4" height="4" rx="0.5" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="7" y="13" width="4" height="4" rx="0.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M14 14h1.5m1.5 0h1m-4 3h4"/>
      </svg>
      <!-- Tooltip -->
      <span class="absolute bottom-full right-0 mb-2 px-3 py-1.5 bg-gray-900 text-white text-xs rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none shadow-md">
        Scan QR Code
      </span>
    </button>
  </div>
</template>
