<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     local_assign_ai
 * @category    string
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Tindakan';
$string['ai_response_language'] = 'Bahasa respons AI';
$string['ai_response_language_help'] = 'Pilih bahasa yang akan digunakan AI untuk merespons saat meninjau tugas ini.';
$string['aiconfigheader'] = 'Datacurso Tugas AI';
$string['aiprompt'] = 'Berikan instruksi ke AI';
$string['aiprompt_help'] = 'Instruksi tambahan yang dikirim ke AI melalui field "prompt".';
$string['aistatus'] = 'Status AI';
$string['aistatus_initial_help'] = 'Kirim kiriman ke AI untuk menghasilkan usulan.';
$string['aistatus_initial_short'] = 'Menunggu tinjauan AI';
$string['aistatus_pending_help'] = 'Usulan AI sudah siap. Buka detail untuk mengedit atau menyetujuinya.';
$string['aistatus_pending_short'] = 'Menunggu persetujuan';
$string['aistatus_processing_help'] = 'AI sedang memproses kiriman ini. Ini mungkin memerlukan waktu.';
$string['aistatus_queued_help'] = 'Kiriman ini telah dimasukkan ke antrean dan akan segera diproses.';
$string['aistatus_queued_short'] = 'dalam antrean';
$string['aitaskdone'] = 'Pemrosesan AI selesai. Total kiriman yang diproses: {$a}';
$string['aitaskstart'] = 'Memproses kiriman AI untuk kursus: {$a}';
$string['aitaskuserqueued'] = 'Kiriman dalam antrean untuk pengguna dengan ID {$a->id} ({$a->name})';
$string['altlogo'] = 'Logo Datacurso';
$string['approveall'] = 'Setujui semua';
$string['assign_ai:changestatus'] = 'Ubah status persetujuan AI';
$string['assign_ai:review'] = 'Tinjau saran AI untuk tugas';
$string['assign_ai:viewdetails'] = 'Lihat detail komentar AI';
$string['attemptnumber'] = 'Percobaan';
$string['autograde'] = 'Setujui otomatis umpan balik AI';
$string['autograde_help'] = 'Jika diaktifkan, nilai dan komentar yang dihasilkan AI diterapkan otomatis ke kiriman siswa tanpa perlu persetujuan manual.';
$string['autogradegrader'] = 'Penilai tercatat untuk persetujuan otomatis';
$string['autogradegrader_help'] = 'Pilih pengguna yang akan dicatat sebagai penilai setiap kali umpan balik AI disetujui otomatis. Hanya pengguna yang dapat menilai tugas di kursus ini yang ditampilkan.';
$string['backtocourse'] = 'Kembali ke kursus';
$string['backtoreview'] = 'Kembali ke tinjauan AI';
$string['confirm_approve_all'] = 'Setujui semua usulan AI yang saat ini menunggu dan terapkan nilainya/komentarnya ke siswa. Lanjutkan?';
$string['confirm_cancel_review'] = 'Batalkan tinjauan AI ini? Statusnya akan kembali menjadi tertunda agar Anda dapat mencobanya lagi.';
$string['confirm_review_all'] = 'Kirim semua kiriman yang ditandai "Menunggu tinjauan AI" ke AI dan mulai pemrosesan. Ini mungkin memerlukan beberapa menit. Lanjutkan?';
$string['current'] = 'Saat ini';
$string['defaultautograde'] = 'Setujui otomatis umpan balik AI secara default';
$string['defaultautograde_desc'] = 'Menentukan nilai default untuk tugas baru.';
$string['defaultdelayminutes'] = 'Waktu tunggu default (menit)';
$string['defaultdelayminutes_desc'] = 'Waktu tunggu default saat peninjauan tertunda diaktifkan.';
$string['defaultenableai'] = 'Aktifkan AI';
$string['defaultenableai_desc'] = 'Mengontrol ketersediaan AI secara global untuk tugas. Jika dinonaktifkan, AI dimatikan untuk semua tugas yang sudah ada dan tidak dapat diaktifkan per tugas sampai diaktifkan kembali secara global.';
$string['defaultprompt'] = 'Berikan instruksi ke AI secara default';
$string['defaultprompt_desc'] = 'Teks ini digunakan sebagai default dan dikirim pada field "prompt". Bisa dioverride per tugas.';
$string['defaultusedelay'] = 'Gunakan peninjauan tertunda secara default';
$string['defaultusedelay_desc'] = 'Menentukan apakah peninjauan tertunda aktif secara default pada tugas baru.';
$string['delayminutes'] = 'Waktu tunggu (menit)';
$string['delayminutes_help'] = 'Jumlah menit yang harus ditunggu setelah siswa memposting sebelum menjalankan peninjauan AI.';
$string['downloadlog'] = 'Unduh log';
$string['edited'] = 'Diedit';
$string['editgrade'] = 'Ubah nilai';
$string['email'] = 'Surel';
$string['enableai'] = 'Aktifkan AI';
$string['enableai_global_disabled_notice'] = 'Aktivasi AI untuk tugas ini tidak tersedia karena telah dinonaktifkan secara global oleh administrator.';
$string['enableai_help'] = 'Jika dinonaktifkan, opsi lain di bagian ini tidak ditampilkan untuk tugas ini.';
$string['enableassignai'] = 'Aktifkan Tugas AI';
$string['enableassignai_desc'] = 'Jika dinonaktifkan, bagian "Datacurso Assign AI" disembunyikan dari pengaturan aktivitas tugas dan pemrosesan otomatis dijeda.';
$string['error_advancedresponsemissing'] = 'Tugas ini dinilai dengan metode lanjutan ({$a}) tetapi respons AI tidak berisi data untuk metode tersebut. Nilai tidak diterapkan.';
$string['error_airequest'] = 'Kesalahan saat berkomunikasi dengan layanan AI: {$a}';
$string['error_guidemismatch'] = 'Respons AI tidak cocok dengan panduan penilaian tugas ini. Nilai tidak diterapkan. Kriteria yang tidak cocok: {$a}';
$string['error_processing_timeout'] = 'Pemrosesan melebihi batas waktu tanpa respons; silakan coba lagi.';
$string['error_rubricmismatch'] = 'Respons AI tidak cocok dengan rubrik tugas ini. Nilai tidak diterapkan. Kriteria yang tidak cocok: {$a}';
$string['errorparsingguide'] = 'Kesalahan saat mengurai respons panduan penilaian: {$a}';
$string['errorparsingrubric'] = 'Kesalahan saat mengurai respons rubrik: {$a}';
$string['feedbackcomments'] = 'Komentar';
$string['feedbackcommentsfull'] = 'Komentar umpan balik';
$string['fullname'] = 'Nama lengkap';
$string['grade'] = 'Nilai';
$string['gradesuccess'] = 'Nilai berhasil dimasukkan';
$string['gradingfailed_body'] = 'Penilaian AI untuk tugas "{$a->assignment}" (siswa: {$a->student}) gagal dan percobaan ulang otomatis telah habis. Kesalahan terakhir: {$a->error}';
$string['gradingfailed_subject'] = 'Penilaian AI gagal: {$a}';
$string['lastmodified'] = 'Terakhir diubah';
$string['log'] = 'Log';
$string['logdetails'] = 'Detail log AI';
$string['logerror'] = 'Kesalahan';
$string['logfailed'] = 'Gagal';
$string['logsuccess'] = 'Berhasil';
$string['manytasksreviewed'] = '{$a} tugas telah ditinjau';
$string['messageprovider:gradingfailed'] = 'Notifikasi kegagalan penilaian AI';
$string['missingtaskparams'] = 'Parameter tugas hilang. Pemrosesan batch AI tidak dapat dimulai.';
$string['modaltitle'] = 'Umpan Balik AI';
$string['nopermissiontochangestatus'] = 'Anda tidak memiliki izin untuk menyimpan atau menyetujui perubahan pada tinjauan AI. Anda hanya dapat melihat detailnya.';
$string['norecords'] = 'Tidak ada catatan ditemukan';
$string['nostatus'] = 'Tidak ada umpan balik';
$string['nosubmissions'] = 'Tidak ada kiriman yang ditemukan untuk diproses.';
$string['notasksfound'] = 'Tidak ada tugas untuk ditinjau';
$string['onetaskreviewed'] = '1 tugas telah ditinjau';
$string['pluginname'] = 'Assignment AI';
$string['privacy:metadata:local_assign_ai_pending'] = 'Menyimpan umpan balik AI yang menunggu persetujuan.';
$string['privacy:metadata:local_assign_ai_pending:approval_token'] = 'Token unik untuk pelacakan persetujuan.';
$string['privacy:metadata:local_assign_ai_pending:assignmentid'] = 'Tugas yang terkait dengan umpan balik AI ini.';
$string['privacy:metadata:local_assign_ai_pending:attemptnumber'] = 'Nomor percobaan kiriman yang terkait dengan umpan balik AI ini.';
$string['privacy:metadata:local_assign_ai_pending:courseid'] = 'Kursus yang terkait dengan umpan balik ini.';
$string['privacy:metadata:local_assign_ai_pending:edited'] = 'Apakah evaluasi ini mencerminkan suntingan kiriman oleh siswa.';
$string['privacy:metadata:local_assign_ai_pending:errormessage'] = 'Kesalahan yang dilaporkan saat pemrosesan AI gagal.';
$string['privacy:metadata:local_assign_ai_pending:grade'] = 'Nilai yang diusulkan oleh AI.';
$string['privacy:metadata:local_assign_ai_pending:message'] = 'Pesan umpan balik yang dihasilkan oleh AI.';
$string['privacy:metadata:local_assign_ai_pending:rubric_response'] = 'Umpan balik rubrik yang dihasilkan oleh AI.';
$string['privacy:metadata:local_assign_ai_pending:status'] = 'Status persetujuan umpan balik.';
$string['privacy:metadata:local_assign_ai_pending:submissionid'] = 'Percobaan kiriman yang menjadi dasar pembuatan umpan balik AI ini.';
$string['privacy:metadata:local_assign_ai_pending:submissionmodified'] = 'Waktu modifikasi kiriman yang dicatat saat umpan balik AI ini dibuat.';
$string['privacy:metadata:local_assign_ai_pending:title'] = 'Judul umpan balik yang dihasilkan.';
$string['privacy:metadata:local_assign_ai_pending:userid'] = 'Pengguna yang menerima umpan balik AI.';
$string['processed'] = '{$a} kiriman berhasil diproses.';
$string['processing'] = 'Memproses';
$string['processingerror'] = 'Terjadi kesalahan saat memproses tinjauan AI.';
$string['promptdefaulttext'] = 'Jawablah dengan nada empatik dan memotivasi';
$string['qualify'] = 'Menilai';
$string['queued'] = 'Semua kiriman telah dikirim ke antrean untuk ditinjau oleh AI. Akan segera diproses.';
$string['reloadpage'] = 'Muat ulang halaman untuk melihat hasil terbaru.';
$string['require_approval'] = 'Tinjau jawaban AI';
$string['retry'] = 'Coba lagi';
$string['retryallfailed'] = 'Coba lagi yang gagal';
$string['retryallqueued'] = '{$a} tinjauan yang gagal telah diantrekan ulang.';
$string['retryqueued'] = 'Tinjauan diantrekan ulang; akan segera diproses.';
$string['review'] = 'Tinjau';
$string['reviewaidisabled'] = 'Tinjauan AI dinonaktifkan untuk tugas ini.';
$string['reviewall'] = 'Tinjau semua';
$string['reviewcancelled'] = 'Tinjauan AI dibatalkan.';
$string['reviewhistory'] = 'Riwayat tinjauan AI';
$string['reviewnotsubmitted'] = 'Percobaan kiriman tidak dalam status terkirim, sehingga tidak dapat ditinjau oleh AI.';
$string['reviewwithai'] = 'Tinjauan dengan AI';
$string['rubricfailed'] = 'Gagal menyuntikkan rubrik setelah 20 percobaan';
$string['rubricmustarray'] = 'Respons rubrik harus berupa array.';
$string['rubricsuccess'] = 'Rubrik berhasil disuntikkan';
$string['save'] = 'Simpan';
$string['saveapprove'] = 'Simpan dan Setujui';
$string['status'] = 'Status';
$string['statusapprove'] = 'Disetujui';
$string['statuserror'] = 'Kesalahan';
$string['statuspending'] = 'Tertunda';
$string['statusrejected'] = 'Ditolak';
$string['statussuperseded'] = 'Digantikan (percobaan sebelumnya)';
$string['submission_draft'] = 'Draf';
$string['submission_new'] = 'Baru';
$string['submission_none'] = 'Tidak ada kiriman';
$string['submission_submitted'] = 'Dikirim';
$string['submittedfiles'] = 'Berkas dikirim';
$string['superseded'] = 'Digantikan';
$string['task_process_ai_queue'] = 'Proses antrean tertunda Assign AI';
$string['task_reap_stuck'] = 'Bersihkan kiriman AI yang macet';
$string['task_retry_failed'] = 'Coba lagi kiriman AI yang gagal';
$string['unexpectederror'] = 'Terjadi kesalahan tak terduga: {$a}';
$string['usedelay'] = 'Gunakan peninjauan tertunda';
$string['usedelay_help'] = 'Jika diaktifkan, peninjauan AI akan dijalankan setelah waktu tunggu yang dapat dikonfigurasi, bukan dijalankan segera.';
$string['validity'] = 'Keberlakuan';
$string['viewaifeedback'] = 'Lihat umpan balik AI';
$string['viewdetails'] = 'Lihat detail';
$string['viewlog'] = 'Lihat log';
