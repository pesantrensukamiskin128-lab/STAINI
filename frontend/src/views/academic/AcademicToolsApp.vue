<script setup lang="ts">
import AdvisorAssignmentView from './AdvisorAssignmentView.vue'
import KrsProdiReviewView from './KrsProdiReviewView.vue'

defineProps<{ page: 'advisor' | 'krs' }>()
</script>

<template>
  <AdvisorAssignmentView v-if="page === 'advisor'" />
  <KrsProdiReviewView v-else />
</template>
