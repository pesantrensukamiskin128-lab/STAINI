<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useToast } from 'vue-toastification'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'

const props = defineProps<{ open: boolean; payment: any }>()
const emit = defineEmits<{ close: []; saved: [data: any] }>()
const toast = useToast(); const saving = ref(false)
const form = reactive({ amount: 0, payment_method: '', payment_date: '', reference_number: '', bank_name: '', account_number: '', account_name: '', note: '' })
watch(() => props.payment, (payment) => {
  if (!payment) return
  Object.assign(form, { amount: Number(payment.amount ?? 0), payment_method: payment.payment_method ?? '', payment_date: payment.payment_date?.slice(0, 10) ?? '', reference_number: payment.reference_number ?? '', bank_name: payment.bank_name ?? '', account_number: payment.account_number ?? '', account_name: payment.account_name ?? '', note: payment.note ?? '' })
}, { immediate: true })
async function save() {
  saving.value = true
  try { const { data } = await api.put(`/finance/payments/${props.payment.id}`, form); toast.success('Pembayaran berhasil diperbarui.'); emit('saved', data.data); emit('close') }
  catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal memperbarui pembayaran.') }
  finally { saving.value = false }
}
</script>
<template>
  <BaseModal :open="open" title="Edit Pembayaran" size="lg" @close="emit('close')">
    <div v-if="payment" class="grid grid-cols-2 gap-3">
      <label class="text-sm">Jumlah<input v-model.number="form.amount" type="number" min="1" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      <label class="text-sm">Tanggal<input v-model="form.payment_date" type="date" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      <label class="text-sm">Metode<input v-model="form.payment_method" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      <label class="text-sm">No. Referensi<input v-model="form.reference_number" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      <label class="text-sm">Nama Bank<input v-model="form.bank_name" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      <label class="text-sm">No. Rekening<input v-model="form.account_number" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      <label class="text-sm">Nama Rekening<input v-model="form.account_name" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      <label class="text-sm">Catatan<textarea v-model="form.note" rows="1" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
    </div>
    <template #footer><button class="px-4 py-2 text-sm text-gray-600" @click="emit('close')">Batal</button><button :disabled="saving" class="px-4 py-2 bg-blue-600 text-white rounded-lg" @click="save">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button></template>
  </BaseModal>
</template>
