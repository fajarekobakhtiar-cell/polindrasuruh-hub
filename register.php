<?php
session_start();
require_once "koneksi.php";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar - Polindra Suruh</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body class="page-auth page-santo-auth page-register">

    <div class="santo-auth-wrapper">
        <!-- TOP: HERO BANNER -->
        <div class="santo-hero-banner">
            <div class="santo-hero-glow-aura"></div>
            <div class="santo-hero-overlay">
                <div class="santo-hero-mascot-wrap">
                    <img src="assets/img/mascot-motor.png?v=<?= filemtime(__DIR__ . '/assets/img/mascot-motor.png') ?>" alt="Polindra Suruh" class="santo-hero-mascot">
                </div>
                <h1 class="santo-hero-tagline">Mau suruh apa hari ini?</h1>
            </div>
        </div>

        <!-- BOTTOM: FORM CARD -->
        <div class="santo-form-card">

            <!-- ========== STEP 1: DATA PENDAFTARAN ========== -->
            <div id="step1" class="santo-step active">
                <div class="santo-form-header">
                    <h2>Daftar Akun</h2>
                    <p>Verifikasi nomor WhatsApp untuk mendaftar</p>
                </div>

                <div id="alertStep1" class="alert alert-error" style="display: none;"></div>

                <form id="formStep1" class="santo-form" autocomplete="off">
                    <!-- Anti-bot honeypot -->
                    <div style="display:none;" aria-hidden="true">
                        <input type="text" name="website_trap" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="santo-input-group">
                        <input type="tel"
                               name="no_wa"
                               id="inputNoWa"
                               class="santo-input"
                               placeholder="Nomor WhatsApp (Contoh: 081234567890)"
                               inputmode="tel"
                               maxlength="20"
                               required
                               autofocus>
                    </div>

                    <div class="santo-input-group">
                        <input type="text"
                               name="username"
                               id="inputUsername"
                               class="santo-input"
                               placeholder="Username (tanpa spasi)"
                               maxlength="30"
                               required
                               autocomplete="username">
                    </div>

                    <div class="santo-input-group">
                        <input type="password"
                               name="password"
                               id="inputPassword"
                               class="santo-input"
                               placeholder="Buat Password (minimal 6 karakter)"
                               minlength="6"
                               required
                               autocomplete="new-password">
                    </div>

                    <button type="submit" class="santo-btn-submit" id="btnSendOtp">
                        <span id="btnSendOtpText">Kirim Kode OTP</span>
                        <span id="btnSendOtpLoading" style="display: none;">Mengirim OTP...</span>
                    </button>
                </form>

                <div class="santo-auth-footer">
                    Sudah mempunyai akun? <a href="login.php">Login</a>
                </div>
                <div class="santo-back-home">
                    <a href="index.php">← Kembali ke Beranda</a>
                </div>
            </div>

            <!-- ========== STEP 2: VERIFIKASI OTP ========== -->
            <div id="step2" class="santo-step">
                <div class="santo-form-header">
                    <h2>Verifikasi OTP</h2>
                    <p>Masukkan 6 digit kode yang dikirim ke</p>
                    <p class="santo-otp-target-wa" id="displayMaskedWa"></p>
                </div>

                <div id="alertStep2" class="alert alert-error" style="display: none;"></div>

                <!-- DEV MODE banner -->
                <div id="devModeBanner" class="santo-dev-banner" style="display: none;">
                    <strong>🛠️ MODE TESTING</strong>
                    <div>Kode OTP Anda: <strong id="devOtpDisplay"></strong></div>
                </div>

                <div class="santo-otp-wrap">
                    <input type="text" inputmode="numeric" maxlength="1" class="santo-otp-input" data-index="0" autocomplete="one-time-code">
                    <input type="text" inputmode="numeric" maxlength="1" class="santo-otp-input" data-index="1">
                    <input type="text" inputmode="numeric" maxlength="1" class="santo-otp-input" data-index="2">
                    <input type="text" inputmode="numeric" maxlength="1" class="santo-otp-input" data-index="3">
                    <input type="text" inputmode="numeric" maxlength="1" class="santo-otp-input" data-index="4">
                    <input type="text" inputmode="numeric" maxlength="1" class="santo-otp-input" data-index="5">
                </div>

                <div class="santo-otp-timer" id="otpTimerWrap">
                    <span id="otpTimerText">Kirim ulang dalam <strong id="countdown">60</strong> detik</span>
                    <button type="button" id="btnResend" class="santo-otp-resend" style="display: none;" onclick="resendOTP()">Kirim Ulang OTP</button>
                </div>

                <button type="button" class="santo-btn-submit" id="btnVerify" onclick="verifyOTP()">
                    <span id="btnVerifyText">Verifikasi & Daftar</span>
                    <span id="btnVerifyLoading" style="display: none;">Memverifikasi...</span>
                </button>

                <div class="santo-otp-back">
                    <button type="button" class="santo-link-btn" onclick="backToStep1()">← Ubah nomor / data</button>
                </div>
            </div>

        </div>
    </div>

<script>
// ===========================================================================
// REGISTRATION OTP FLOW - POLINDRA SURUH
// ===========================================================================
let otpToken = '';
let cooldownTimer = null;
let countdownSeconds = 60;

// ----- STEP 1: KIRIM OTP -----
document.getElementById('formStep1').addEventListener('submit', function(e) {
    e.preventDefault();

    // Honeypot check
    if (document.querySelector('[name="website_trap"]').value !== '') return;

    const noWa     = document.getElementById('inputNoWa').value.trim();
    const username = document.getElementById('inputUsername').value.trim();
    const password = document.getElementById('inputPassword').value;

    // Client-side validations
    if (!noWa || !username || !password) {
        showAlert('alertStep1', 'Semua kolom wajib diisi.');
        return;
    }

    const digits = noWa.replace(/\D/g, '');
    if (digits.length < 10 || digits.length > 15) {
        showAlert('alertStep1', 'Nomor WhatsApp tidak valid. Masukkan 10-15 digit.');
        return;
    }

    if (!/^[a-zA-Z0-9_]{3,30}$/.test(username)) {
        showAlert('alertStep1', 'Username hanya boleh huruf, angka, dan underscore (3-30 karakter).');
        return;
    }

    if (password.length < 6) {
        showAlert('alertStep1', 'Password minimal 6 karakter.');
        return;
    }

    // Kirim ke API
    setLoading('btnSendOtp', 'btnSendOtpText', 'btnSendOtpLoading', true);
    hideAlert('alertStep1');

    const formData = new FormData();
    formData.append('no_wa', noWa);
    formData.append('username', username);
    formData.append('password', password);

    fetch('api/send_otp.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            setLoading('btnSendOtp', 'btnSendOtpText', 'btnSendOtpLoading', false);

            if (data.success) {
                otpToken = data.token;
                try { sessionStorage.setItem('polindra_otp_token', data.token); } catch(e){}
                document.getElementById('displayMaskedWa').textContent = data.masked_wa || noWa;
                countdownSeconds = data.cooldown || 60;

                // Dev mode: show OTP on screen
                if (data.dev_otp) {
                    document.getElementById('devOtpDisplay').textContent = data.dev_otp;
                    document.getElementById('devModeBanner').style.display = 'block';
                } else {
                    document.getElementById('devModeBanner').style.display = 'none';
                }

                // Switch to Step 2
                document.getElementById('step1').classList.remove('active');
                document.getElementById('step2').classList.add('active');

                // Focus first OTP input
                clearOtpInputs();
                document.querySelector('.santo-otp-input[data-index="0"]').focus();

                // Start countdown
                startCountdown(countdownSeconds);
            } else {
                showAlert('alertStep1', data.message);
            }
        })
        .catch(() => {
            setLoading('btnSendOtp', 'btnSendOtpText', 'btnSendOtpLoading', false);
            showAlert('alertStep1', 'Terjadi kesalahan jaringan. Silakan coba lagi.');
        });
});

// ----- STEP 2: VERIFIKASI OTP -----
function verifyOTP() {
    const otpInputs = document.querySelectorAll('.santo-otp-input');
    let otpCode = '';
    otpInputs.forEach(inp => otpCode += inp.value);

    if (otpCode.length !== 6 || !/^\d{6}$/.test(otpCode)) {
        showAlert('alertStep2', 'Masukkan 6 digit kode OTP.');
        return;
    }

    if (!otpToken) {
        try { otpToken = sessionStorage.getItem('polindra_otp_token') || ''; } catch(e){}
    }

    setLoading('btnVerify', 'btnVerifyText', 'btnVerifyLoading', true);
    hideAlert('alertStep2');

    const formData = new FormData();
    formData.append('otp_token', otpToken);
    formData.append('otp_code', otpCode);

    fetch('api/verify_otp.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            setLoading('btnVerify', 'btnVerifyText', 'btnVerifyLoading', false);

            if (data.success) {
                // Registrasi berhasil!
                showAlert('alertStep2', data.message, 'success');
                document.getElementById('btnVerify').disabled = true;

                setTimeout(() => {
                    window.location.href = data.redirect || 'login.php?daftar=berhasil';
                }, 1500);
            } else {
                showAlert('alertStep2', data.message);

                if (data.expired) {
                    // OTP expired: tampilkan tombol kirim ulang
                    document.getElementById('otpTimerText').style.display = 'none';
                    document.getElementById('btnResend').style.display = 'inline';
                }

                // Shake & clear OTP inputs
                const wrap = document.querySelector('.santo-otp-wrap');
                wrap.classList.add('shake');
                setTimeout(() => wrap.classList.remove('shake'), 500);
                clearOtpInputs();
                document.querySelector('.santo-otp-input[data-index="0"]').focus();
            }
        })
        .catch(() => {
            setLoading('btnVerify', 'btnVerifyText', 'btnVerifyLoading', false);
            showAlert('alertStep2', 'Terjadi kesalahan jaringan. Silakan coba lagi.');
        });
}

// ----- KIRIM ULANG OTP -----
function resendOTP() {
    const noWa     = document.getElementById('inputNoWa').value.trim();
    const username = document.getElementById('inputUsername').value.trim();
    const password = document.getElementById('inputPassword').value;

    document.getElementById('btnResend').style.display = 'none';
    document.getElementById('otpTimerText').style.display = 'inline';
    hideAlert('alertStep2');
    document.getElementById('devModeBanner').style.display = 'none';

    const formData = new FormData();
    formData.append('no_wa', noWa);
    formData.append('username', username);
    formData.append('password', password);

    fetch('api/send_otp.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                otpToken = data.token;
                try { sessionStorage.setItem('polindra_otp_token', data.token); } catch(e){}
                countdownSeconds = data.cooldown || 60;
                startCountdown(countdownSeconds);
                clearOtpInputs();
                document.querySelector('.santo-otp-input[data-index="0"]').focus();
                showAlert('alertStep2', 'Kode OTP baru telah dikirim!', 'success');

                if (data.dev_otp) {
                    document.getElementById('devOtpDisplay').textContent = data.dev_otp;
                    document.getElementById('devModeBanner').style.display = 'block';
                }
            } else {
                showAlert('alertStep2', data.message);
                document.getElementById('btnResend').style.display = 'inline';
                document.getElementById('otpTimerText').style.display = 'none';
            }
        })
        .catch(() => {
            showAlert('alertStep2', 'Gagal mengirim ulang OTP.');
            document.getElementById('btnResend').style.display = 'inline';
            document.getElementById('otpTimerText').style.display = 'none';
        });
}

// ----- KEMBALI KE STEP 1 -----
function backToStep1() {
    if (cooldownTimer) clearInterval(cooldownTimer);
    document.getElementById('step2').classList.remove('active');
    document.getElementById('step1').classList.add('active');
    hideAlert('alertStep1');
    hideAlert('alertStep2');
    document.getElementById('devModeBanner').style.display = 'none';
}

// ===========================================================================
// OTP INPUT UX (Auto-advance, paste, backspace)
// ===========================================================================
const otpInputs = document.querySelectorAll('.santo-otp-input');

otpInputs.forEach((input, idx) => {
    // Ketik angka → pindah ke kotak berikutnya
    input.addEventListener('input', function(e) {
        const val = this.value.replace(/\D/g, '');
        this.value = val.slice(0, 1);
        if (val && idx < otpInputs.length - 1) {
            otpInputs[idx + 1].focus();
        }
        // Auto-verify ketika 6 digit terisi
        if (idx === otpInputs.length - 1 && val) {
            let full = '';
            otpInputs.forEach(i => full += i.value);
            if (full.length === 6) {
                setTimeout(verifyOTP, 150);
            }
        }
    });

    // Backspace → pindah ke kotak sebelumnya
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !this.value && idx > 0) {
            otpInputs[idx - 1].focus();
            otpInputs[idx - 1].value = '';
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            verifyOTP();
        }
    });

    // Paste support: paste 6 digit sekaligus
    input.addEventListener('paste', function(e) {
        e.preventDefault();
        const pasted = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
        if (pasted.length > 0) {
            for (let i = 0; i < 6; i++) {
                otpInputs[i].value = pasted[i] || '';
            }
            if (pasted.length === 6) {
                otpInputs[5].focus();
                setTimeout(verifyOTP, 150);
            } else {
                otpInputs[Math.min(pasted.length, 5)].focus();
            }
        }
    });

    // Select all on focus
    input.addEventListener('focus', function() {
        this.select();
    });
});

// ===========================================================================
// HELPERS
// ===========================================================================
function clearOtpInputs() {
    otpInputs.forEach(i => i.value = '');
}

function startCountdown(seconds) {
    if (cooldownTimer) clearInterval(cooldownTimer);
    let remaining = seconds;
    const el = document.getElementById('countdown');
    const timerText = document.getElementById('otpTimerText');
    const btnResend = document.getElementById('btnResend');

    el.textContent = remaining;
    timerText.style.display = 'inline';
    btnResend.style.display = 'none';

    cooldownTimer = setInterval(() => {
        remaining--;
        el.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(cooldownTimer);
            timerText.style.display = 'none';
            btnResend.style.display = 'inline';
        }
    }, 1000);
}

function showAlert(id, msg, type) {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-error');
    el.style.display = 'block';
}

function hideAlert(id) {
    document.getElementById(id).style.display = 'none';
}

function setLoading(btnId, textId, loadingId, loading) {
    const btn = document.getElementById(btnId);
    document.getElementById(textId).style.display = loading ? 'none' : 'inline';
    document.getElementById(loadingId).style.display = loading ? 'inline' : 'none';
    btn.disabled = loading;
    btn.style.opacity = loading ? '0.7' : '1';
}
</script>

</body>
</html>
