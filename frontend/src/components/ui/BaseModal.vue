<script setup lang="ts">
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

defineProps<{
  open: boolean
  title: string
  size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl'
}>()

defineEmits<{ close: [] }>()

const sizeClass: Record<string, string> = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-lg',
  xl: 'max-w-2xl',
  '2xl': 'max-w-6xl',
}
</script>

<template>
  <TransitionRoot :show="open" as="template">
    <Dialog class="relative z-50" @close="$emit('close')">
      <!-- Backdrop -->
      <TransitionChild
        enter="ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100"
        leave="ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/40" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-2 sm:p-4">
          <TransitionChild
            enter="ease-out duration-200" enter-from="opacity-0 scale-95" enter-to="opacity-100 scale-100"
            leave="ease-in duration-150" leave-from="opacity-100 scale-100" leave-to="opacity-0 scale-95"
          >
            <DialogPanel
              :class="['w-full bg-white rounded-2xl shadow-xl flex flex-col max-h-[90vh]', sizeClass[size ?? 'md']]"
            >
              <!-- Header (fixed) -->
              <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 shrink-0">
                <DialogTitle class="text-base font-semibold text-gray-900">{{ title }}</DialogTitle>
                <button
                  class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                  @click="$emit('close')"
                >
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>

              <!-- Content (scrollable) -->
              <div class="flex-1 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
                <slot />
              </div>

              <!-- Footer (fixed) -->
              <div v-if="$slots.footer" class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50 shrink-0">
                <slot name="footer" />
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
