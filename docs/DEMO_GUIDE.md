# Panduan demo DSMS (kira-kira 20 minit)

Akaun penuh: `LOGIN_ACCOUNTS.txt`. Guna dua tetingkap pelayar (satu biasa, satu incognito) supaya boleh bertukar peranan dengan cepat.
Sebelum mula: import `database.sql` (data demo segar). Tukar bahasa dengan butang **EN | BM** di bahagian atas.

| Peranan | Nama pengguna | Kata laluan |
|---|---|---|
| Staf | `stf001` | `staff001@uis` |
| Ketua Jabatan (Fakulti Pendidikan) | `hos002` | `hos002@uis` |
| Pembantu Pentadbiran | `admin` | `admin123` |
| Ketua Pentadbiran | `head001` | `head001@uis` |
| Pemandu | `drv001` | `driver001@uis` |

## 1. Permohonan kenderaan (aliran utama)
1. **Staf** (`stf001`): *Request a Vehicle* → jenis **Car**, kuantiti 1, tujuan Official, surat sokongan (PDF), tarikh lebih 2 hari → Submit. Cuba tarikh kurang 2 hari: permohonan ditanda **Urgent**.
2. **Ketua Jabatan** (`hos002`): *Vehicle Approvals* → Approve (status "Approved by Head of Department").
3. **Pembantu Pentadbiran** (`admin`): *Vehicle Requests* → Assign driver. Pilih kenderaan, lihat cadangan pemandu (tugas bulan ini, hujung minggu, pengalaman). Pilih pemandu yang bukan teratas untuk tunjuk **amaran + Confirm**.
4. **Ketua Pentadbiran** (`head001`): *Approve Assignments* → Approve. Staf dan pemandu dimaklumkan (loceng + e-mel dalam `email_log`).
5. **Pemandu** (`drv001`): *My Tasks* → selepas tugasan bermula, **Create Report** (perbatuan, bahan api, masalah).

Tunjuk juga: kuantiti 2 kenderaan (Assign driver ada "Other vehicles"), **Pay and Claim**, **Personal Rental** (lori, RM3/km) dengan cetakan surat kelulusan di *My Requests*.

## 2. Tugasan dalaman
`admin`: *Internal Tasks* → Add (tiada pemandu, status **Waiting for Driver**, kenderaan ditempah) → Assign driver → `head001` Approve (status **Approved**, pemandu baru dimaklumkan).

## 3. Tugas kebajikan mingguan
`admin`: *Welfare Duty* → Create schedule (pilih minggu, satu kereta/van setiap hari) → Assign drivers (tujuh hari) → Submit. `head001`: *Approve Assignments* → Approve. Pemandu nampak harinya di *My Tasks*.

## 4. Akaun dan kenderaan
- `admin`: *Drivers* → **Register driver** (kata laluan lalai dijana dan dihantar; lihat `email_log` jika e-mel mati). *Users* untuk cari akaun, kemas kini jabatan/peranan dan nyahaktif.
- `admin`: *Vehicles* → Add (kelas lesen, perbatuan, bahan api wajib) → Edit: hanya bahan api, perbatuan dan status Active/Under Maintenance (ditolak jika ada tempahan akan datang).
- Daftar sendiri staf: `register.php` (hanya e-mel `@uis.edu.my`, kod pengesahan 6 digit; kod dalam `email_log`). Lupa kata laluan: `forgot_password.php`.

## 5. Senarai dan laporan
- *My Requests* (penapis status/tarikh), *Department Requests* (`hos002`), *All Request* (`admin`/`head001`, tab "All Request" + penapis jabatan).
- *Calendar*/*Reports*: tiga jenis laporan (Driver Workload, Driver Replacement, Vehicle Usage; eksport PDF dan Excel).
- Pemandu: *Schedule Change* (tarikh tidak tersedia / tarik diri) → `head001` Approve.

## Nota untuk madam
- Satu kegagalan ujian sedia ada (`agentv_test`, "welfare duty blocks deactivation") bergantung pada tarikh; ia tidak menjejas ciri.
- WhatsApp dimatikan (tidak dalam SRS 10 Nov). E-mel benar-benar dihantar hanya jika `config/mail.php` dihidupkan.
