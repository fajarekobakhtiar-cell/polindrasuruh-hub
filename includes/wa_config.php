<?php
// ===========================================================================
// KONFIGURASI WHATSAPP API - POLINDRA SURUH
// ===========================================================================
// Nomor Resmi: +6282234506655
//
// Untuk mengirim OTP via WhatsApp otomatis, Anda perlu:
// 1. Daftar akun di salah satu provider WhatsApp API (rekomendasi: Fonnte.com)
// 2. Hubungkan nomor +6282234506655 ke provider tersebut
// 3. Salin API Token ke WA_API_TOKEN di bawah ini
// 4. Set WA_DEV_MODE = false untuk mode produksi
//
// Provider yang didukung:
//   'fonnte'  → https://fonnte.com (Populer di Indonesia, ada free tier)
//   'wablas'  → https://wablas.com
//   'manual'  → Tanpa API (OTP hanya ditampilkan di layar, untuk testing)
// ===========================================================================

// --- MODE DEVELOPMENT ---
// true  = OTP ditampilkan di response (tidak dikirim ke WhatsApp, untuk testing)
// false = OTP dikirim ke WhatsApp secara nyata (production)
define('WA_DEV_MODE', false);

// --- PROVIDER WHATSAPP API ---
define('WA_PROVIDER', 'fonnte'); // 'fonnte', 'wablas', atau 'manual'

// --- API TOKEN ---
// Dapatkan token dari provider pilihan Anda
define('WA_API_TOKEN', 'gmfLmS57oK2qyECNqtju');

// --- NOMOR PENGIRIM RESMI ---
define('WA_SENDER_NUMBER', '6282234506655');

// --- PENGATURAN OTP ---
define('OTP_LENGTH', 6);               // Jumlah digit OTP
define('OTP_EXPIRY_MINUTES', 5);       // OTP berlaku 5 menit
define('OTP_MAX_ATTEMPTS', 5);         // Maks percobaan verifikasi per OTP
define('OTP_MAX_SEND_PER_HOUR', 5);    // Maks kirim OTP per nomor per jam
define('OTP_COOLDOWN_SECONDS', 60);    // Cooldown antar pengiriman OTP (detik)
