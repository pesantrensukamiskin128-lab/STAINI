<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useToast } from 'vue-toastification'
import api from '@/services/api'
import HonorManagementView from './HonorManagementView.vue'
import LectureJournalReviewView from './LectureJournalReviewView.vue'
import MyHonorView from './MyHonorView.vue'

const props = defineProps<{ honorPage: 'manage' | 'mine' | 'review' }>()

const toast = useToast()
const loading = ref(true)
const errorMessage = ref('')
const roles = ref<string[]>([])

const canManage = computed(() => roles.value.some(role => ['SUPER_ADMIN', 'ADMIN_KEUANGAN'].includes(role)))
const canViewOwn = computed(() => roles.value.some(role => [
  'SUPER_ADMIN',
  'ADMIN_KEUANGAN',
  'DOSEN',
  'ADMIN_UMUM',
  'ADMIN_AKADEMIK',
  'ADMIN_PMB',
  'ADMIN_DIGILIB',
].includes(role)))
const hasPageAccess = computed(() => props.honorPage === 'mine' ? canViewOwn.value : canManage.value)

onMounted(async () => {
  try {
    const { data } = await api.get('/auth/me')
    roles.value = data.roles ?? []
    if (!hasPageAccess.value) errorMessage.value = 'Akun ini tidak memiliki akses ke halaman honor.'
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message ?? 'Akses honor gagal diverifikasi.'
    toast.error(errorMessage.value)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-5">
    <div v-if="loading" class="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
      Memeriksa sesi dan akses honor...
    </div>
    <div v-else-if="errorMessage" class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
      {{ errorMessage }}
    </div>
    <HonorManagementView v-else-if="honorPage === 'manage' && canManage" />
    <MyHonorView v-else-if="honorPage === 'mine' && canViewOwn" />
    <LectureJournalReviewView v-else-if="honorPage === 'review' && canManage" />
  </div>
</template>
