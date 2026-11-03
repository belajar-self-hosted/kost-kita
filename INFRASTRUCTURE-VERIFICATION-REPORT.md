# Infrastructure Verification Report

Tanggal: 3 November 2026 WIB; pembaruan read-only sampai pukul 21:37 WIB. Cakupan: workstation, GitHub connector, browser, HTTP publik dan status rute Tailscale. Tidak ada perubahan workflow GitHub, infrastruktur, deployment, migrasi atau restart. `VERIFIED` berarti bukti langsung mendukung klaim tersebut; `MISMATCH` berarti konfigurasi yang dibandingkan berbeda; `UNVERIFIED` berarti bukti belum tersedia; `BLOCKED` berarti akses untuk pemeriksaan tidak tersedia. Status ini tidak dengan sendirinya menyatakan production sehat.

## Hasil

| Pemeriksaan                                                           | Status                      | Bukti teknis dan batas                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| --------------------------------------------------------------------- | --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GitHub `main`                                                         | VERIFIED                    | GitHub connector dan `git ls-remote origin refs/heads/main` menunjukkan SHA `9b4fa27a0126254cdfe3ba41bf2d5403520f0b8a`. Branch remediation `codex/cicd-production-remediation` dibuat dari parent SHA itu dan belum dipush; perubahan local/commit pada branch ini tidak mengubah remote `main`.                                                                                                                                                                                                                                                                                                           |
| Branch protection                                                     | MISMATCH                    | GitHub melaporkan `protected: false` untuk `main`, padahal rencana mengharuskan branch terlindungi. Ruleset tambahan tidak terbaca (API 403), jadi mungkin ada aturan lain yang belum diverifikasi.                                                                                                                                                                                                                                                                                                                                                                                                        |
| Workflow remote vs remediation lokal                                  | MISMATCH                    | Remote `.github/workflows/deploy.yml` pada parent SHA memiliki blob `6db6824b7c75aadab41a3c35a5ad3010bbd52c68`, workflow ID `405389177`, state `active`. Workflow lama masih memiliki `workflow_dispatch` yang melewati CI, mutasi source in-place, `migrate --force`, `compose up`, dan prune global; concurrency berbeda per ref. Workflow lokal sudah menghapus dispatch, membangun dari SHA teruji, memakai satu target concurrency group dengan `queue: max`, serta mewajibkan deployment variable eksplisit. Perubahan lokal belum dipush dan tidak mengubah remote.                                 |
| Workflow ID dan run aktif/queued saat ini                             | BLOCKED                     | Snapshot Actions pukul 20:21 WIB menunjukkan workflow deploy ID `405389177` aktif, workflow `tests` ID `405373892`, runner ID 2 `kost-kita` online/busy=false, dan tidak ada run deployment nonterminal/queued pada status yang diperiksa. Pemeriksaan ulang pukul 21:37 WIB terhalang: GitHub UI menampilkan “Page not found”; konektor tidak menyediakan daftar run/operasi disable, dan `gh` tidak tersedia. Status run serta state workflow terbaru tidak diketahui. Workflow tidak dinonaktifkan dan tidak ada job dibatalkan.                                                                        |
| Riwayat pipeline                                                      | VERIFIED (historis)         | [CI run `41630702100`](https://github.com/belajar-self-hosted/kost-kita/actions/runs/41630702100) dan [deploy run `41630886365`](https://github.com/belajar-self-hosted/kost-kita/actions/runs/41630886365) berhasil pada 3 November 2026 sekitar 18:51–18:54 WIB untuk SHA `9b4fa27`. Job `125899847827` memakai runner ID 2 `kost-kita` dan melaporkan build, migration, launch, health. Bukti ini tidak menunjukkan keadaan sekarang, lokasi runner, atau SHA runtime live.                                                                                                                             |
| Keamanan Actions/repository                                           | MISMATCH / BLOCKED          | `main` terlapor `protected:false`; rulesets API mengembalikan 403; jumlah environment terbaca 0; runner ID 2 `kost-kita` online dengan labels `[self-hosted, linux, x64, kostkita]`; workflow deploy lama aktif. Default workflow permissions, required checks, runner groups, siapa yang boleh mengubah workflow/dispatch, dan konfigurasi proteksi belum terverifikasi. Workflow lokal menggunakan `contents: read`, tidak menyediakan `workflow_dispatch`, dan default-off sampai `KOSTKITA_DEPLOYMENT_ENABLED=true`; proteksi environment tetap harus dibuat dan diverifikasi sebelum flag diaktifkan. |
| Lokasi runner, Proxmox node/LXC dan identitas target                  | BLOCKED                     | Tidak ada shell PVE/LXC atau bukti yang memetakan runner ID 2 ke node/container. Target dokumen—PVE Server Mamay, LXC `10026`, hostname/IP, `/opt/kostkita/src`, port 8080—adalah nilai rencana, bukan observasi runtime. Host workstation `mamay.local` adalah Darwin dengan Docker Desktop, bukan bukti node PVE.                                                                                                                                                                                                                                                                                        |
| Tailscale/VPN menuju subnet homelab                                   | VERIFIED (rute saja)        | Tailscale lokal `BackendState=Running`, workstation online, satu peer online mengiklankan `192.168.0.0/24`, dan route ke IP aplikasi memakai `utun6`. HTTP `192.168.0.26:8080/up` tetap timeout (HTTP 000). Ini membuktikan route VPN terpasang, bukan keterjangkauan LXC atau status servicenya. Jangan mengubah jaringan untuk mengatasi timeout.                                                                                                                                                                                                                                                        |
| Docker Compose, service, kapasitas, env, storage dan commit SHA aktif | BLOCKED                     | Docker context yang tersedia adalah Docker Desktop lokal `desktop-linux`, bukan daemon LXC. Status service, image ID, resource CPU/RAM/disk, permission, database, scheduler, worker dan release SHA produksi belum diamati. Jangan mencetak `.env` atau Compose config terinterpolasi.                                                                                                                                                                                                                                                                                                                    |
| Database migration/schema compatibility                               | BLOCKED                     | Schema dan migration history produksi tidak terbaca. Script lokal menolak migrations pending dari image aktif dan kandidat serta mengulanginya di bawah lock; ia tidak menjalankan migration. `migrate:status` hanya memeriksa nama migration. Migration `2026_11_03_000022` diubah pada repository history commit `15e70ef`, jadi status nama tidak membuktikan isi SQL yang pernah diterapkan. Perlu cocokkan schema/history aktual sebelum deployment atau perubahan database.                                                                                                                          |
| Endpoint publik                                                       | VERIFIED (cakupan terbatas) | Pemeriksaan ulang 19:57 WIB ke `https://kostkita.sequein.my.id/up` menghasilkan HTTP 200, TLS verify `0`, edge IP `172.67.218.194`. `/release.txt` menghasilkan HTTP 404. Ini membuktikan respons publik melalui Cloudflare, bukan origin, database, runtime SHA, asset, scheduler atau route internal.                                                                                                                                                                                                                                                                                                    |
| Traefik dan Cloudflare Tunnel                                         | BLOCKED                     | Tidak ada akses config gateway atau Cloudflare. Target terdokumentasi adalah gateway LXC `10080` / `192.168.0.80` dan aplikasi `192.168.0.26:8080`; hostname route, connector, service, IP dan port aktif belum dibuktikan.                                                                                                                                                                                                                                                                                                                                                                                |
| Inventaris backup                                                     | BLOCKED                     | Storage/backup viewer tidak tersedia; lokasi, cakupan, jadwal, waktu berhasil terakhir, integritas dan retensi belum diamati.                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Keberhasilan restore                                                  | UNVERIFIED                  | Belum ada bukti rehearsal restore pada environment terisolasi. Keberadaan file backup saja tidak membuktikan pemulihan. Jangan melakukan restore ke production.                                                                                                                                                                                                                                                                                                                                                                                                                                            |

## Akses yang dibutuhkan dan pemeriksaan read-only

Jangan mengirim kredensial, token, `.env`, backup, konfigurasi terinterpolasi, atau log mentah yang mengandung data pengguna. Jalankan perintah dari host yang tepat dan berikan hasil metadata yang sudah disanitasi beserta waktu dan hostname asal.

### GitHub Actions dan kontrol repository

Dibutuhkan sesi GitHub yang dapat membaca Actions metadata/runs, branch rules/rulesets, runners dan environments. Snapshot sebelumnya dapat membaca Actions, tetapi konektor saat ini tidak menyediakan daftar run atau operasi workflow disable; `gh` tidak terpasang. Pemeriksaan ulang UI pada pukul 21:37 WIB menampilkan “Page not found”, sehingga akses repository tidak terbukti dan status terbaru tidak diketahui. Fine-grained token memerlukan repo permission `Actions: read` untuk runs dan `Actions: write` untuk disable API. Jangan kirim token ke chat. Gunakan integrasi GitHub yang secara aman memiliki izin tersebut atau UI pemilik repo; bagikan hanya output metadata yang disanitasi. Pada mesin yang sudah memiliki `gh` dan autentikasi lokal, perintah read-only berikut tidak menampilkan token:

```bash
gh auth status
check_legacy_deploy_runs() {
  WORKFLOW_ID=$(gh api repos/belajar-self-hosted/kost-kita/actions/workflows --paginate \
    --jq '.workflows[] | select(.path==".github/workflows/deploy.yml") | .id') || return 1
  if ! [[ "$WORKFLOW_ID" =~ ^[0-9]+$ ]]; then
    echo 'Expected exactly one numeric workflow ID' >&2
    return 1
  fi
  gh api "repos/belajar-self-hosted/kost-kita/actions/workflows/${WORKFLOW_ID}" \
    --jq '{id,name,path,state}' || return 1
  for RUN_STATUS in queued in_progress waiting requested pending; do
    RUNS=$(gh api "repos/belajar-self-hosted/kost-kita/actions/workflows/${WORKFLOW_ID}/runs?status=${RUN_STATUS}&per_page=100" --paginate \
      --jq '.workflow_runs[] | {id,workflow_id,name,status,event,created_at,head_sha}') || {
        echo "Could not verify workflow runs with status ${RUN_STATUS}" >&2
        return 1
      }
    if [[ -n "$RUNS" ]]; then
      printf '%s\n' "$RUNS"
      echo "Nonterminal runs exist for status ${RUN_STATUS}; do not disable or cancel" >&2
      return 1
    fi
  done
  echo 'Verified: no nonterminal deployment runs in queued/in-progress/waiting/requested/pending states.'
}
check_legacy_deploy_runs
```

Untuk kontrol repository lain, jalankan setiap query secara independen. Jika satu endpoint 403/404, tetap jalankan query lain dan catat endpoint gagal sebagai `BLOCKED`; jangan gabungkan pemeriksaan ini ke script strict no-run check.

```bash
gh api repos/belajar-self-hosted/kost-kita/branches/main/protection
gh api 'repos/belajar-self-hosted/kost-kita/rulesets?includes_parents=true'
gh api repos/belajar-self-hosted/kost-kita/actions/permissions
gh api repos/belajar-self-hosted/kost-kita/actions/permissions/workflow
gh api repos/belajar-self-hosted/kost-kita/actions/runners --jq '.runners[] | {id,name,status,labels:[.labels[].name]}'
gh api repos/belajar-self-hosted/kost-kita/environments
```

Output workflow yang telah dibaca menunjuk deploy workflow ID `405389177`, path `.github/workflows/deploy.yml`, nama `Local In-LXC CI/CD Deployment`, state `active`; workflow CI `tests` ID `405373892`. Snapshot run pada 20:21 WIB kosong untuk status nonterminal yang diperiksa. Sebelum disable, ulangi pemeriksaan tepat saat tindakan akan dilakukan; jika ada run baru/hasil ambigu, jangan disable dan jangan cancel. `branches/main/protection` semestinya menampilkan required checks/restrictions; 403/404 berarti belum diverifikasi. Environment output perlu menunjukkan reviewer/branch restriction sebelum deployment flag diaktifkan. GitHub mensyaratkan `Actions: read` untuk membaca runs privat dan `Actions: write` untuk disable API; lihat [GitHub workflow-runs API](https://docs.github.com/en/rest/actions/workflow-runs#list-workflow-runs-for-a-workflow) dan [workflow disable API](https://docs.github.com/en/rest/actions/workflows#disable-a-workflow).

Jika API/CLI tidak tersedia, pemilik repo dapat membuka **Actions → Local In-LXC CI/CD Deployment (workflow ID 405389177)**, memeriksa run terbaru dan memastikan tidak ada deployment queued/in progress tepat sebelum mitigasi. Jika daftar run tidak dapat dibuktikan kosong, jangan disable. Jika state sudah disabled, cukup verifikasi dan catat; jika masih active dan daftar run kosong, pilih menu `…` → **Disable workflow**. Jangan klik **Cancel**, jangan dispatch, dan jangan ubah workflow CI. Setelah tindakan, pastikan UI menyatakan Disabled atau API menunjukkan `state=disabled_manually`. Konektor yang tersedia tidak memiliki operasi disable, sehingga mitigasi remote masih belum dilakukan.

### Proxmox dan LXC

Dibutuhkan alamat node PVE yang bisa dijangkau melalui Tailscale/VPN dan akun inspeksi PVE. Route `192.168.0.0/24` saat ini sudah tersedia melalui Tailscale, tetapi target app timeout. Jangan mengubah route atau firewall. Di node PVE yang identitasnya telah dipastikan:

```bash
hostname
pveversion
pct status 10026
pct config 10026
pct status 10080
pct config 10080
```

Expected output: PVE version/hostname cocok dengan node yang diotorisasi; app LXC `10026` berstatus `running`; config `net0` menunjukkan IP/bridge yang diharapkan. Periksa `10080` secara terpisah; jangan menganggap ID itu gateway sebelum config-nya membuktikan identitas dan IP ingress.

Jalankan perintah berikut hanya setelah `pct status/config 10026` menunjukkan LXC target yang benar dan berstatus `running`:

```bash
pct exec 10026 -- hostname
pct exec 10026 -- ip -br addr
```

Hasil di dalam LXC harus memuat hostname yang sama dengan variable target dan interface `192.168.0.26`. Hanya jika pemeriksaan `pct status/config 10080` membuktikan LXC `10080` adalah gateway ingress yang dimaksud, jalankan:

```bash
pct exec 10080 -- hostname
pct exec 10080 -- ip -br addr
```

Hasil LXC ingress harus cocok dengan alamat dan nama ingress yang diharapkan. Cocokkan identitas PVE, container, runner host, dan GitHub metadata sebelum memberi status `VERIFIED`.

Untuk LXC aplikasi, dibutuhkan shell read-only serta akses membaca metadata Docker dan service runner (akses Docker socket tetap berprivilege tinggi walaupun perintah berikut read-only):

```bash
id
pwd
hostname
ip -br addr
df -h
df -i
free -h
nproc
systemctl list-units --type=service --all 'actions.runner*'
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
stat -c '%U %G %a %n' /opt/kostkita /opt/kostkita/src /opt/kostkita/.env
curl --max-time 5 -fsS -o /dev/null -w '%{http_code}\n' http://192.168.0.26:8080/up
```

Expected output: hostname/IP sama dengan hasil PVE; resource report tidak menunjukkan disk/inode/RAM kritis; daftar Compose berisi app, web, database dan scheduler yang diharapkan dengan status berjalan/healthy; `stat` hanya menampilkan owner/mode/path, tanpa isi env. Bandingkan unit runner, ID/labels dan lokasi kerja pada host dengan runner metadata GitHub; nama `kost-kita` dan label `kostkita` saja bukan bukti lokasi fisik. SHA runtime perlu dibuktikan dari image/container revision metadata yang telah disanitasi dan dicocokkan antar service.

### Ingress dan backup

Dibutuhkan akses viewer read-only ke LXC gateway dan Cloudflare Zero Trust untuk mencocokkan public hostname, tunnel connector, router/service Traefik, origin IP/port dan trusted proxy—tanpa membuka token tunnel. Dari gateway yang dipastikan identitasnya, periksa `hostname`, `hostname -I`, `docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'`, dan field konfigurasi router/service yang telah disanitasi. Kemudian uji target origin dan publik serta cocokkan release/asset hanya bila endpoint tersedia.

Dibutuhkan akses metadata read-only ke storage backup: lokasi off-node, scope PostgreSQL dan file privat, waktu backup terakhir yang berhasil, retensi, integrity check, dan job result. Restore belum terverifikasi; operator harus menjalankan rehearsal hanya pada environment terisolasi, bukan production, lalu mencatat hasil, RPO/RTO, waktu, dan cakupan. Jangan menyalin backup atau APP_KEY ke chat.

Expected backup output: lokasi/storage tier, timestamp backup terakhir berstatus sukses, database dan private file coverage, hasil integrity check, retensi, serta RPO/RTO yang ditetapkan. Expected restore evidence: sukses pada environment terisolasi, database dapat di-query, cakupan file privat terverifikasi, durasi pemulihan tercatat, dan tidak ada koneksi atau perubahan terhadap production.

## Implementasi lokal dan kesimpulan

Workflow yang disiapkan pada branch kerja menjalankan push `main` → workflow `tests` → workflow deployment pada runner berlabel KostKita → source checkout pada tested SHA → local Docker Compose build → deployment dan health/SHA verification, tanpa GHCR. Policy dan safety tests, Compose config dan workflow syntax validator tersedia di repository. Guard local meminta hostname yang diharapkan dan ambang disk/inode terukur, tetapi variable/host belum dikonfigurasi atau dibuktikan; automatic database migrations dan global prune sengaja tidak dijalankan. Rollback hanya memulihkan image di atas Compose config kandidat; perubahan konfigurasi tidak dipulihkan. Uji di daemon target dan tetapkan prosedur operator untuk config rollback.

Remote workflow legacy ID `405389177` active pada snapshot 20:21 WIB; pada saat itu tidak ada run nonterminal. Pemeriksaan ulang 21:37 WIB menampilkan “Page not found”, sehingga state run/workflow terkini tidak diketahui. Akses untuk no-run read dan `Actions: write` tidak tersedia. Identitas runner/PVE/LXC, runtime, ingress, backup dan restore tetap belum diverifikasi. Docker daemon workstation tidak tersedia untuk image build dan PostgreSQL acceptance. Production tetap **BLOCKED**, meskipun perubahan repository lokal dapat lulus validasi. Lihat [Production Readiness Checklist](PRODUCTION-READINESS-CHECKLIST.md), [Production Impact Assessment](PRODUCTION-IMPACT-ASSESSMENT.md), dan [Deployment & Rollback Plan](DEPLOYMENT-ROLLBACK-PLAN.md).
