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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Archivo+Black&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/home.css?v=<?= filemtime(__DIR__ . '/assets/css/home.css') ?>">
</head>
<body class="ps-auth-body ps-register-page">

    <div class="ps-auth">
        <!-- PANEL KIRI: SAMA PERSIS SEPERTI LOGIN -->
        <aside class="ps-auth-side">
            <a href="index.php" class="ps-auth-logo ps-logo ps-reg-brand">
    <span class="ps-reg-brand-badge">PS</span>
    <span class="ps-reg-brand-text">Polindra Suruh</span>
</a>

            <div class="ps-auth-welcome">
                <span class="ps-pill">DAFTAR AKUN</span>
                <h1 class="ps-auth-title">
                    <span class="ps-line"><span class="ps-word" style="--d:.05s">AYO,</span></span>
                    <span class="ps-line"><span class="ps-word ps-word-hl" style="--d:.14s">GABUNG</span></span>
                    <span class="ps-line"><span class="ps-word" style="--d:.22s">SEKARANG!</span></span>
                </h1>
                <div class="ps-auth-rotator">
                    <span>Mau</span>
                    <b id="psRotatorText">suruh apa</b>
                    <span>?</span>
                </div>
            </div>

            <!-- Maskot mengikuti struktur CSS auth -->
            <div class="ps-auth-mascot" id="authMascot">
                <div class="ps-auth-bubble">YUK DAFTAR!</div>
                <div class="ps-auth-sticker">DAFTAR DULU<br>BARU SURUH!</div>
                <div class="ps-auth-blob"></div>
                <div class="ps-auth-tilt" id="authMascotTilt">
                    <img src="assets/img/mascot.png?v=<?= file_exists(__DIR__ . '/assets/img/mascot.png') ? filemtime(__DIR__ . '/assets/img/mascot.png') : time() ?>"
                         alt="Maskot Polindra Suruh"
                         class="ps-auth-mascot-img">
                </div>
            </div>
        </aside>

        <!-- PANEL KANAN: KARTU FORM REGISTER & OTP -->
        <main class="ps-auth-main">
            <div class="ps-auth-card">

                <!-- ========== STEP 1: DATA PENDAFTARAN ========== -->
                <div id="step1" class="ps-step active">
                    <div class="ps-auth-head">
                        <h2>DAFTAR</h2>
                        <p>Verifikasi nomor WhatsApp untuk mendaftar</p>
                    </div>

                    <div id="alertStep1" class="ps-alert ps-alert-error" style="display: none;"></div>

                    <form id="formStep1" class="ps-auth-form" autocomplete="off">
                        <!-- Anti-bot honeypot -->
                        <div style="display:none;" aria-hidden="true">
                            <input type="text" name="website_trap" tabindex="-1" autocomplete="off">
                        </div>

                        <label class="ps-field">
                            <span>Nomor WhatsApp</span>
                            <input type="tel"
                                   name="no_wa"
                                   id="inputNoWa"
                                   class="ps-input"
                                   placeholder="Contoh: 081234567890"
                                   inputmode="tel"
                                   maxlength="20"
                                   required
                                   autofocus>
                        </label>

                        <label class="ps-field">
                            <span>Username</span>
                            <input type="text"
                                   name="username"
                                   id="inputUsername"
                                   class="ps-input"
                                   placeholder="Username (tanpa spasi)"
                                   maxlength="30"
                                   required
                                   autocomplete="username">
                        </label>

                        <label class="ps-field">
                            <span>Password</span>
                            <div class="ps-input-wrap">
                                <input type="password"
                                       name="password"
                                       id="inputPassword"
                                       class="ps-input"
                                       placeholder="Buat Password (minimal 6 karakter)"
                                       minlength="6"
                                       required
                                       autocomplete="new-password">
                                <button type="button" class="ps-eye" id="togglePassword" onclick="togglePassVisibility()">Lihat</button>
                            </div>
                        </label>

                        <button type="submit" class="ps-reg-submit" id="btnSendOtp">
                            <span id="btnSendOtpText">Kirim Kode OTP →</span>
                            <span id="btnSendOtpLoading" style="display: none;">Mengirim OTP...</span>
                        </button>
                    </form>

                    <div class="ps-auth-foot">
                        Sudah mempunyai akun? <a href="login.php">Login</a>
                    </div>
                    <div class="ps-auth-back">
                        <a href="index.php">← Kembali ke Beranda</a>
                    </div>
                </div>

                <!-- ========== STEP 2: VERIFIKASI OTP ========== -->
                <div id="step2" class="ps-step">
                    <div class="ps-auth-head">
                        <h2>VERIFIKASI OTP</h2>
                        <p>Masukkan 6 digit kode yang dikirim ke</p>
                        <p class="ps-otp-target-wa" id="displayMaskedWa"></p>
                    </div>

                    <div id="alertStep2" class="ps-alert ps-alert-error" style="display: none;"></div>

                    <!-- DEV MODE banner -->
                    <div id="devModeBanner" class="ps-dev-banner" style="display: none;">
                        <strong>🛠️ MODE TESTING</strong>
                        <div>Kode OTP Anda: <strong id="devOtpDisplay"></strong></div>
                    </div>

                    <div class="ps-otp-wrap">
                        <input type="text" inputmode="numeric" maxlength="1" class="ps-otp-input" data-index="0" autocomplete="one-time-code">
                        <input type="text" inputmode="numeric" maxlength="1" class="ps-otp-input" data-index="1">
                        <input type="text" inputmode="numeric" maxlength="1" class="ps-otp-input" data-index="2">
                        <input type="text" inputmode="numeric" maxlength="1" class="ps-otp-input" data-index="3">
                        <input type="text" inputmode="numeric" maxlength="1" class="ps-otp-input" data-index="4">
                        <input type="text" inputmode="numeric" maxlength="1" class="ps-otp-input" data-index="5">
                    </div>

                    <div class="ps-otp-timer" id="otpTimerWrap">
                        <span id="otpTimerText">Kirim ulang dalam <strong id="countdown">60</strong> detik</span>
                        <button type="button" id="btnResend" class="ps-otp-resend" style="display: none;" onclick="resendOTP()">Kirim Ulang OTP</button>
                    </div>

                    <button type="button" class="ps-reg-submit" id="btnVerify" onclick="verifyOTP()">
                        <span id="btnVerifyText">Verifikasi &amp; Daftar →</span>
                        <span id="btnVerifyLoading" style="display: none;">Memverifikasi...</span>
                    </button>

                    <div class="ps-otp-back">
                        <button type="button" class="ps-link-btn" onclick="backToStep1()">← Ubah nomor / data</button>
                    </div>
                </div>

            </div>
        </main>
    </div>

<script>
// ===========================================================================
// MASCOT TILT & ROTATOR INTERACTION
// ===========================================================================
(function() {
    const mascotArea = document.getElementById('authMascot');
    const mascotTilt = document.getElementById('authMascotTilt');
    if (mascotArea && mascotTilt) {
        mascotArea.addEventListener('mousemove', function(e) {
            const rect = mascotArea.getBoundingClientRect();
            const nx = ((e.clientX - rect.left) / rect.width - 0.5) * 2;
            const ny = ((e.clientY - rect.top) / rect.height - 0.5) * 2;
            mascotTilt.style.transform = `translate3d(${nx * 8}px, ${ny * 6}px, 0) rotate(${nx * 2.5}deg)`;
        });
        mascotArea.addEventListener('mouseleave', function() {
            mascotTilt.style.transform = '';
        });
    }

    const rotatorEl = document.getElementById('psRotatorText');
    const phrases = ['suruh apa', 'belanja apa', 'titip apa'];
    let pIdx = 0;
    if (rotatorEl) {
        setInterval(() => {
            rotatorEl.classList.add('out');
            setTimeout(() => {
                pIdx = (pIdx + 1) % phrases.length;
                rotatorEl.textContent = phrases[pIdx];
                rotatorEl.classList.remove('out');
            }, 260);
        }, 2600);
    }
})();

function togglePassVisibility() {
    const input = document.getElementById('inputPassword');
    const btn = document.getElementById('togglePassword');
    if (!input || !btn) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Sembunyi';
    } else {
        input.type = 'password';
        btn.textContent = 'Lihat';
    }
}

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
                document.querySelector('.ps-otp-input[data-index="0"]').focus();

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
    const otpInputs = document.querySelectorAll('.ps-otp-input');
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
                showAlert('alertStep2', data.message, 'success');
                document.getElementById('btnVerify').disabled = true;

                setTimeout(() => {
                    window.location.href = data.redirect || 'login.php?daftar=berhasil';
                }, 1500);
            } else {
                showAlert('alertStep2', data.message);

                if (data.expired) {
                    document.getElementById('otpTimerText').style.display = 'none';
                    document.getElementById('btnResend').style.display = 'inline';
                }

                const wrap = document.querySelector('.ps-otp-wrap');
                wrap.classList.add('shake');
                setTimeout(() => wrap.classList.remove('shake'), 500);
                clearOtpInputs();
                document.querySelector('.ps-otp-input[data-index="0"]').focus();
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
                document.querySelector('.ps-otp-input[data-index="0"]').focus();
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
const otpInputs = document.querySelectorAll('.ps-otp-input');

otpInputs.forEach((input, idx) => {
    input.addEventListener('input', function() {
        const val = this.value.replace(/\D/g, '');
        this.value = val.slice(0, 1);
        if (val && idx < otpInputs.length - 1) {
            otpInputs[idx + 1].focus();
        }
        if (idx === otpInputs.length - 1 && val) {
            let full = '';
            otpInputs.forEach(i => full += i.value);
            if (full.length === 6) {
                setTimeout(verifyOTP, 150);
            }
        }
    });

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
    el.className = 'ps-alert ' + (type === 'success' ? 'ps-alert-success' : 'ps-alert-error');
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