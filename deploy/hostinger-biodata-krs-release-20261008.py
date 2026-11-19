from pathlib import Path
import hashlib
import re

root = Path("/home/u273554645/asc-backend")
public = Path("/home/u273554645/domains/stainibandung.ac.id/public_html/asc")
changes = {}


def replace_once(relative_path, old, new):
    path = root / relative_path
    content = changes.get(path, path.read_text())
    count = content.count(old)
    if count != 1:
        raise RuntimeError(f"{relative_path}: expected one anchor, found {count}")
    changes[path] = content.replace(old, new, 1)


replace_once(
    "app/Models/Krs.php",
    "use Illuminate\\Database\\Eloquent\\Model;",
    "use Illuminate\\Database\\Eloquent\\Builder;\nuse Illuminate\\Database\\Eloquent\\Model;",
)
replace_once(
    "app/Models/Krs.php",
    "    protected $table = 'krs';\n",
    "    protected $table = 'krs';\n\n"
    "    protected $appends = ['display_status', 'display_status_label'];\n",
)
replace_once(
    "app/Models/Krs.php",
    "    public function recalculateCredits(): void\n",
    "    public function getDisplayStatusAttribute(): string\n"
    "    {\n"
    "        return $this->signed_by_kaprodi_at ? 'DITANDATANGANI' : $this->status;\n"
    "    }\n\n"
    "    public function getDisplayStatusLabelAttribute(): string\n"
    "    {\n"
    "        return match ($this->display_status) {\n"
    "            'DRAFT' => 'Draf',\n"
    "            'SUBMITTED' => 'Diajukan',\n"
    "            'APPROVED' => 'Disetujui Dosen Wali',\n"
    "            'REJECTED' => 'Ditolak',\n"
    "            'CANCELLED' => 'Dibatalkan',\n"
    "            'DITANDATANGANI' => 'Ditandatangani',\n"
    "            default => str($this->display_status)->replace('_', ' ')->toString(),\n"
    "        };\n"
    "    }\n\n"
    "    public function scopeWhereDisplayStatus(Builder $query, string $status): Builder\n"
    "    {\n"
    "        if ($status === 'DITANDATANGANI') {\n"
    "            return $query->whereNotNull('signed_by_kaprodi_at');\n"
    "        }\n\n"
    "        $query->where('status', $status);\n\n"
    "        return $status === 'APPROVED'\n"
    "            ? $query->whereNull('signed_by_kaprodi_at')\n"
    "            : $query;\n"
    "    }\n\n"
    "    public function recalculateCredits(): void\n",
)
replace_once(
    "app/Http/Controllers/Api/KrsController.php",
    "->when($request->status, fn ($q) => $q->where('status', $request->status))",
    "->when($request->status, fn ($q) => $q->whereDisplayStatus($request->status))",
)
replace_once(
    "app/Http/Controllers/Api/KrsController.php",
    'throw new RuntimeException("KRS {$nim} sudah berstatus {$krs->status} dan tidak dapat diubah.");',
    'throw new RuntimeException("KRS {$nim} sudah berstatus {$krs->display_status_label} dan tidak dapat diubah.");',
)
replace_once(
    "app/Exports/KrsExport.php",
    "->when($this->status, fn (Builder $query) => $query->where('status', $this->status))",
    "->when($this->status, fn (Builder $query) => $query->whereDisplayStatus($this->status))",
)
replace_once(
    "app/Exports/KrsExport.php",
    "                    $krs->status,\n",
    "                    $krs->display_status_label,\n",
)
replace_once(
    "app/Http/Controllers/Api/DashboardController.php",
    "'krs_status' => $activeKrs?->status ?? 'BELUM_ISI',",
    "'krs_status' => $activeKrs?->display_status ?? 'BELUM_ISI',",
)
replace_once(
    "app/Http/Controllers/Api/StudentController.php",
    "            $student->profile()->updateOrCreate(\n"
    "                ['student_id' => $student->id],\n"
    "                array_merge([\n"
    "                    'religion' => null,\n"
    "                    'nik' => null,\n"
    "                    'nisn' => null,\n"
    "                    'blood_type' => null,\n"
    "                    'nationality' => 'Indonesia',\n"
    "                ], $validated['profile'] ?? [])\n"
    "            );",
    "            $profileData = $validated['profile'] ?? [];\n"
    "            $profileData['nationality'] = ($profileData['nationality'] ?? null) ?: 'Indonesia';\n\n"
    "            $student->profile()->updateOrCreate(\n"
    "                ['student_id' => $student->id],\n"
    "                array_merge([\n"
    "                    'religion' => null,\n"
    "                    'nik' => null,\n"
    "                    'nisn' => null,\n"
    "                    'blood_type' => null,\n"
    "                ], $profileData)\n"
    "            );",
)

index_path = public / "index.html"
index = index_path.read_text()
script_tag = '    <script defer src="/student-krs-status-labels-v1.js"></script>'
if index.count(script_tag) == 0:
    anchor = '    <script defer src="/student-krs-class-details-v1.js"></script>'
    if index.count(anchor) != 1:
        raise RuntimeError("index.html: expected one KRS class-details script anchor")
    index = index.replace(anchor, anchor + "\n" + script_tag, 1)
elif index.count(script_tag) != 1:
    raise RuntimeError("index.html: duplicate KRS status script references")

asset_path = public / "student-krs-status-labels-v1.js"
revision = lambda content: hashlib.md5(content.encode("utf-8")).hexdigest()
index_revision = revision(index)
asset_revision = hashlib.md5(asset_path.read_bytes()).hexdigest()

sw_path = public / "sw-asc-v3.js"
sw = sw_path.read_text()
sw, index_count = re.subn(
    r'\{url:"index\.html",revision:"[^"]+"\}',
    '{url:"index.html",revision:"' + index_revision + '"}',
    sw,
)
if index_count != 1:
    raise RuntimeError(f"sw-asc-v3.js: expected one index entry, found {index_count}")

asset_entry = '{url:"student-krs-status-labels-v1.js",revision:"' + asset_revision + '"}'
asset_pattern = r'\{url:"student-krs-status-labels-v1\.js",revision:"[^"]+"\}'
sw, asset_count = re.subn(asset_pattern, asset_entry, sw)
if asset_count == 0:
    index_entry = '{url:"index.html",revision:"' + index_revision + '"}'
    if sw.count(index_entry) != 1:
        raise RuntimeError("sw-asc-v3.js: updated index entry could not be located")
    sw = sw.replace(index_entry, index_entry + "," + asset_entry, 1)
elif asset_count != 1:
    raise RuntimeError(f"sw-asc-v3.js: duplicate status adapter entries found: {asset_count}")

changes[index_path] = index
changes[sw_path] = sw
for path, content in changes.items():
    path.write_text(content)

print("PATCHED_FILES=" + ",".join(str(path) for path in changes))
print("INDEX_REVISION=" + index_revision)
print("STATUS_ADAPTER_REVISION=" + asset_revision)
