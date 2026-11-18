<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useToast } from 'vue-toastification'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'

const props = defineProps<{ open: boolean; invoice: any }>()
const emit = defineEmits<{ close: []; saved: [data: any] }>()
const toast = useToast()
const saving = ref(false)
const feeTypes = ref<any[]>([])
const form = reactive({ due_date: '', discount_amount: 0, scholarship_amount: 0, note: '', items: [] as any[] })

watch(() => props.invoice, (invoice) => {
  if (!invoice) return
  form.due_date = invoice.due_date?.slice(0, 10) ?? ''
  form.discount_amount = Number(invoice.discount_amount ?? 0)
  form.scholarship_amount = Number(invoice.scholarship_amount ?? 0)
  form.note = invoice.note ?? ''
  form.items = (invoice.items ?? []).map((item: any) => ({ fee_type_id: item.fee_type_id, description: item.description ?? '', amount: Number(item.amount ?? 0), label: item.fee_type?.name }))
}, { immediate: true })

watch(() => props.open, async (open) => {
  if (open && !feeTypes.value.length) {
    try { feeTypes.value = (await api.get('/finance/fee-types')).data }
    catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal memuat jenis tagihan.') }
  }
})

function addItem() { form.items.push({ fee_type_id: '', description: '', amount: 0, label: '' }) }
function removeItem(index: number) { if (form.items.length > 1) form.items.splice(index, 1) }

async function save() {
  if (!form.items.length || form.items.some(item => !item.fee_type_id || Number(item.amount) < 0)) {
    toast.error('Lengkapi item tagihan.'); return
  }
  saving.value = true
  try {
    const { data } = await api.put(`/finance/invoices/${props.invoice.id}`, form)
    toast.success('Tagihan berhasil diperbarui.')
    emit('saved', data.data); emit('close')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal memperbarui tagihan.') }
  finally { saving.value = false }
}
</script>

<template>
  <BaseModal :open="open" title="Edit Tagihan" size="lg" @close="emit('close')">
    <div v-if="invoice" class="space-y-4">
      <p class="text-sm text-gray-500 font-mono">{{ invoice.invoice_number }}</p>
      <div class="grid grid-cols-2 gap-3">
        <label class="text-sm">Jatuh Tempo<input v-model="form.due_date" type="date" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
        <label class="text-sm">Catatan<textarea v-model="form.note" rows="1" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      </div>
      <div class="flex items-center justify-between"><span class="text-sm font-medium">Item Tagihan</span><button type="button" class="text-xs text-blue-600" @click="addItem">+ Tambah item</button></div>
      <div v-for="(item, index) in form.items" :key="index" class="grid grid-cols-12 gap-2">
        <select v-model="item.fee_type_id" class="col-span-4 px-3 py-2 border rounded-lg text-sm">
          <option value="">Jenis tagihan</option><option v-for="feeType in feeTypes" :key="feeType.id" :value="feeType.id">{{ feeType.name }}</option>
        </select>
        <input v-model="item.description" class="col-span-4 px-3 py-2 border rounded-lg text-sm" placeholder="Keterangan" />
        <input v-model.number="item.amount" type="number" min="0" class="col-span-3 px-3 py-2 border rounded-lg text-sm" />
        <button v-if="form.items.length > 1" type="button" class="col-span-1 text-red-500" @click="removeItem(index)">×</button>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <label class="text-sm">Diskon<input v-model.number="form.discount_amount" type="number" min="0" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
        <label class="text-sm">Beasiswa<input v-model.number="form.scholarship_amount" type="number" min="0" class="mt-1 w-full px-3 py-2 border rounded-lg" /></label>
      </div>
    </div>
    <template #footer><button class="px-4 py-2 text-sm text-gray-600" @click="emit('close')">Batal</button><button :disabled="saving" class="px-4 py-2 bg-blue-600 text-white rounded-lg" @click="save">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button></template>
  </BaseModal>
</template>
