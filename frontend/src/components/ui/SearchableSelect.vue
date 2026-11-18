<script setup lang="ts">
import { computed, ref, watch } from 'vue'

interface Option {
  value: string | number
  label: string
  search?: string
}

const props = withDefaults(defineProps<{
  modelValue: string | number
  options: Option[]
  placeholder?: string
  searchPlaceholder?: string
  disabled?: boolean
}>(), {
  placeholder: '-- Pilih --',
  searchPlaceholder: 'Cari...',
  disabled: false,
})

const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>()
const open = ref(false)
const search = ref('')

const selected = computed(() => props.options.find((option) => String(option.value) === String(props.modelValue)))
const filteredOptions = computed(() => {
  const query = search.value.trim().toLowerCase()
  if (!query) return props.options
  return props.options.filter((option) => `${option.label} ${option.search ?? ''}`.toLowerCase().includes(query))
})

function select(option: Option) {
  emit('update:modelValue', option.value)
  open.value = false
  search.value = ''
}

function close() {
  open.value = false
  search.value = ''
}

watch(() => props.disabled, (disabled) => {
  if (disabled) close()
})
</script>

<template>
  <div class="relative">
    <button
      type="button"
      :disabled="disabled"
      class="w-full flex items-center justify-between gap-2 px-3 py-2 border border-gray-300 rounded-lg text-sm text-left focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-400"
      @click="open = !open"
    >
      <span :class="selected ? 'text-gray-800' : 'text-gray-400'">{{ selected?.label ?? placeholder }}</span>
      <span class="text-gray-400">▾</span>
    </button>
    <div v-if="open" class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
      <div class="p-2 border-b border-gray-100">
        <input
          v-model="search"
          type="search"
          :placeholder="searchPlaceholder"
          class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          autofocus
          @keydown.esc="close"
        />
      </div>
      <div class="max-h-56 overflow-y-auto py-1">
        <button
          v-for="option in filteredOptions"
          :key="String(option.value)"
          type="button"
          class="w-full px-3 py-2 text-left text-sm hover:bg-blue-50"
          :class="String(option.value) === String(modelValue) ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700'"
          @click="select(option)"
        >
          {{ option.label }}
        </button>
        <p v-if="filteredOptions.length === 0" class="px-3 py-4 text-center text-xs text-gray-400">Data tidak ditemukan.</p>
      </div>
    </div>
  </div>
</template>
