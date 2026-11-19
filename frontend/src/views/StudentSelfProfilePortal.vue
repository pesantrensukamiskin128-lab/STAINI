<script setup lang="ts">
import StudentSelfProfileView from './StudentSelfProfileView.vue'
</script>

<template>
  <main class="min-h-screen bg-gray-50 px-4 py-8 sm:px-6">
    <div class="mx-auto max-w-4xl">
      <header class="mb-6 flex items-center justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-blue-700">Nurul Iman Academic Integrated System</p>
          <h1 class="mt-1 text-2xl font-bold text-gray-900">Biodata Mahasiswa</h1>
        </div>
        <a
          href="/"
          class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Kembali ke NURIS
        </a>
      </header>
      <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <StudentSelfProfileView />
      </section>
    </div>
  </main>
</template>
