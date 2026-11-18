const labels: Record<string, string> = {
  DRAFT: 'Draf',
  SUBMITTED: 'Diajukan',
  APPROVED: 'Disetujui Dosen Wali',
  DISETUJUI_PRODI: 'Disetujui Prodi',
  REJECTED: 'Ditolak',
  DITOLAK_PRODI: 'Ditolak Prodi',
  CANCELLED: 'Dibatalkan',
  DITANDATANGANI: 'Ditandatangani',
  BELUM_ISI: 'Belum Diisi',
}

export function getKrsStatusLabel(status?: string, signedAt?: string | null): string {
  const displayStatus = signedAt ? 'DITANDATANGANI' : status ?? ''
  return labels[displayStatus] ?? displayStatus.replace(/_/g, ' ')
}

export function getKrsStatusColor(status?: string, signedAt?: string | null): string {
  const displayStatus = signedAt ? 'DITANDATANGANI' : status
  if (displayStatus === 'SUBMITTED') return 'bg-yellow-100 text-yellow-700'
  if (displayStatus === 'APPROVED') return 'bg-amber-100 text-amber-800'
  if (displayStatus === 'DISETUJUI_PRODI') return 'bg-indigo-100 text-indigo-700'
  if (displayStatus === 'DITANDATANGANI') return 'bg-green-100 text-green-700'
  if (displayStatus === 'DITOLAK_PRODI') return 'bg-rose-100 text-rose-700'
  if (displayStatus === 'REJECTED') return 'bg-red-100 text-red-600'
  return 'bg-gray-100 text-gray-600'
}
