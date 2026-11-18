<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from 'vue-toastification'
import { extractErrorMessage } from '@/composables/useCrud'
import { toDateInput } from '@/utils/formValue'

type ParentInfo = {
  name: string
  occupation: string
  phone: string
  email: string
  education: string
  income: string
  is_alive: boolean
}

const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const parentKeys = ['father', 'mother', 'guardian'] as const
const parentLabels = { father: 'Ayah', mother: 'Ibu', guardian: 'Wali' }
const form = reactive({
  student: {
    name: '', gender: '', birth_place: '', birth_date: '', email: '', phone: '', home_phone: '',
  },
  profile: { religion: '', nik: '', nisn: '', blood_type: '', nationality: 'Indonesia' },
  address: {
    address: '', village: '', district: '', city: '', province: '', postal_code: '', rt: '', rw: '', hamlet: '',
  },
  parents: {
    father: { name: '', occupation: '', phone: '', email: '', education: '', income: '', is_alive: true } as ParentInfo,
    mother: { name: '', occupation: '', phone: '', email: '', education: '', income: '', is_alive: true } as ParentInfo,
    guardian: { name: '', occupation: '', phone: '', email: '', education: '', income: '', is_alive: true } as ParentInfo,
  },
  education: {
    institution_name: '', institution_address: '', major: '', graduation_year: '', diploma_number: '',
  },
  documents: { diploma_link: '', family_card_link: '', identity_link: '' },
})

async function loadProfile() {
  loading.value = true
  try {
    const { data } = await api.get('/students/me/profile')
    Object.assign(form.student, data.student ?? {})
    form.student.birth_date = toDateInput(data.student?.birth_date)
    Object.assign(form.profile, data.profile ?? {})
    Object.assign(form.address, data.address ?? {})
    Object.assign(form.education, data.education ?? {})
    Object.assign(form.documents, data.documents ?? {})
    for (const key of parentKeys) {
      if (data.parents?.[key]) Object.assign(form.parents[key], data.parents[key])
    }
  } catch (error) {
    toast.error(extractErrorMessage(error))
  } finally {
    loading.value = false
  }
}

async function saveProfile() {
  saving.value = true
  try {
    const { data } = await api.put('/students/me/profile', form)
    toast.success(data.message || 'Biodata berhasil diperbarui.')
    await loadProfile()
  } catch (error) {
    toast.error(extractErrorMessage(error))
  } finally {
    saving.value = false
  }
}

onMounted(loadProfile)
</script>

<template>
  <div class="p-5 sm:p-6">
    <div class="mb-5">
      <h2 class="text-base font-semibold text-gray-900">Biodata Mahasiswa</h2>
      <p class="mt-1 text-sm text-gray-500">
        Perbarui data pribadi, alamat, keluarga, pendidikan sebelumnya, dan tautan dokumen.
        NIM, program studi, dosen wali, status, serta data akademik dikelola oleh kampus.
      </p>
    </div>

    <div v-if="loading" class="py-10 text-center text-sm text-gray-500">Memuat biodata...</div>
    <form v-else class="space-y-7" @submit.prevent="saveProfile">
      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Data Pribadi</h3>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="field sm:col-span-2">Nama Lengkap *
            <input v-model="form.student.name" required maxlength="255" />
          </label>
          <label class="field">Jenis Kelamin
            <select v-model="form.student.gender">
              <option value="">Pilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option>
            </select>
          </label>
          <label class="field">Agama
            <input v-model="form.profile.religion" maxlength="50" />
          </label>
          <label class="field">Tempat Lahir
            <input v-model="form.student.birth_place" maxlength="100" />
          </label>
          <label class="field">Tanggal Lahir
            <input v-model="form.student.birth_date" type="date" />
          </label>
          <label class="field">Email *
            <input v-model="form.student.email" required type="email" maxlength="255" />
          </label>
          <label class="field">Nomor HP
            <input v-model="form.student.phone" type="tel" maxlength="20" />
          </label>
          <label class="field">Telepon Rumah
            <input v-model="form.student.home_phone" type="tel" maxlength="30" />
          </label>
          <label class="field">NIK
            <input v-model="form.profile.nik" maxlength="20" />
          </label>
          <label class="field">NISN
            <input v-model="form.profile.nisn" maxlength="20" />
          </label>
          <label class="field">Golongan Darah
            <select v-model="form.profile.blood_type">
              <option value="">Pilih</option><option v-for="blood in ['A', 'B', 'AB', 'O']" :key="blood">{{ blood }}</option>
            </select>
          </label>
          <label class="field">Kewarganegaraan
            <input v-model="form.profile.nationality" maxlength="50" />
          </label>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Alamat Domisili</h3>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="field sm:col-span-2">Alamat
            <textarea v-model="form.address.address" rows="2" maxlength="2000" />
          </label>
          <label class="field">Dusun
            <input v-model="form.address.hamlet" maxlength="100" />
          </label>
          <label class="field">RT
            <input v-model="form.address.rt" maxlength="5" />
          </label>
          <label class="field">RW
            <input v-model="form.address.rw" maxlength="5" />
          </label>
          <label class="field">Desa/Kelurahan
            <input v-model="form.address.village" maxlength="100" />
          </label>
          <label class="field">Kecamatan
            <input v-model="form.address.district" maxlength="100" />
          </label>
          <label class="field">Kota/Kabupaten
            <input v-model="form.address.city" maxlength="100" />
          </label>
          <label class="field">Provinsi
            <input v-model="form.address.province" maxlength="100" />
          </label>
          <label class="field">Kode Pos
            <input v-model="form.address.postal_code" maxlength="10" />
          </label>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Orang Tua dan Wali</h3>
        <div class="space-y-5">
          <fieldset v-for="key in parentKeys" :key="key" class="grid gap-4 rounded-lg border border-gray-200 p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium text-gray-700">{{ parentLabels[key] }}</legend>
            <label class="field">Nama
              <input v-model="form.parents[key].name" maxlength="255" />
            </label>
            <label class="field">Pekerjaan
              <input v-model="form.parents[key].occupation" maxlength="100" />
            </label>
            <label class="field">Nomor HP
              <input v-model="form.parents[key].phone" type="tel" maxlength="20" />
            </label>
            <label class="field">Email
              <input v-model="form.parents[key].email" type="email" maxlength="255" />
            </label>
            <label class="field">Pendidikan Terakhir
              <input v-model="form.parents[key].education" maxlength="50" />
            </label>
            <label class="field">Penghasilan
              <input v-model="form.parents[key].income" maxlength="50" />
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input v-model="form.parents[key].is_alive" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
              Masih hidup
            </label>
          </fieldset>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Pendidikan Sebelumnya</h3>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="field">Nama Sekolah (SMA/MA/SMK)
            <input v-model="form.education.institution_name" maxlength="255" />
          </label>
          <label class="field">Jurusan
            <input v-model="form.education.major" maxlength="255" />
          </label>
          <label class="field sm:col-span-2">Alamat Sekolah
            <input v-model="form.education.institution_address" maxlength="255" />
          </label>
          <label class="field">Tahun Lulus
            <input v-model="form.education.graduation_year" type="number" min="1900" :max="new Date().getFullYear()" />
          </label>
          <label class="field">Nomor Ijazah
            <input v-model="form.education.diploma_number" maxlength="100" />
          </label>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Tautan Dokumen</h3>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="field">Tautan Ijazah
            <input v-model="form.documents.diploma_link" type="url" maxlength="255" placeholder="https://" />
          </label>
          <label class="field">Tautan Kartu Keluarga
            <input v-model="form.documents.family_card_link" type="url" maxlength="255" placeholder="https://" />
          </label>
          <label class="field">Tautan KTP/Identitas
            <input v-model="form.documents.identity_link" type="url" maxlength="255" placeholder="https://" />
          </label>
        </div>
        <p class="mt-2 text-xs text-gray-500">Gunakan tautan dokumen yang dapat dibuka oleh petugas kampus.</p>
      </section>

      <div class="flex justify-end border-t pt-4">
        <button
          type="submit"
          :disabled="saving"
          class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ saving ? 'Menyimpan...' : 'Simpan Biodata' }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
}
.field :is(input, select, textarea) {
  width: 100%;
  border: 1px solid #d1d5db;
  border-radius: 0.5rem;
  padding: 0.625rem 0.75rem;
  font-size: 0.875rem;
  font-weight: 400;
  color: #111827;
}
.field :is(input, select, textarea):focus {
  outline: 2px solid transparent;
  outline-offset: 2px;
  border-color: transparent;
  box-shadow: 0 0 0 2px #3b82f6;
}
</style>
