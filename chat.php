<?php
session_start();
require_once __DIR__ . '/koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=chat');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? 'pelanggan';
$currentUserName = $_SESSION['nama'] ?? 'Pengguna';

$pesananId = (int)($_GET['pesanan_id'] ?? 0);
if ($pesananId <= 0) {
    header('Location: ' . ($currentUserRole === 'driver' ? 'driver.php' : 'pesanan_saya.php'));
    exit;
}

// Ambil rincian pesanan
$stmtPesanan = $pdo->prepare("
    SELECT 
        p.id, p.user_id, p.driver_id, p.layanan, p.status, p.alamat, p.catatan, p.total_tarif, p.metode_bayar, p.created_at,
        p.pickup_lat, p.pickup_lng, p.dest_lat, p.dest_lng,
        u_cust.nama AS nama_pelanggan, u_cust.no_wa AS no_wa_pelanggan, u_cust.email AS email_pelanggan,
        u_drv.nama AS nama_driver, u_drv.email AS email_driver,
        d.no_hp AS no_hp_driver
    FROM pesanan p
    JOIN users u_cust ON p.user_id = u_cust.id
    LEFT JOIN users u_drv ON p.driver_id = u_drv.id
    LEFT JOIN drivers d ON d.user_id = u_drv.id
    WHERE p.id = :id
    LIMIT 1
");
$stmtPesanan->execute(['id' => $pesananId]);
$order = $stmtPesanan->fetch();

if (!$order) {
    header('Location: ' . ($currentUserRole === 'driver' ? 'driver.php' : 'pesanan_saya.php'));
    exit;
}

$isCustomer = ((int)$order['user_id'] === $currentUserId);
$isDriver   = (!empty($order['driver_id']) && (int)$order['driver_id'] === $currentUserId);
$isAdmin    = ($currentUserRole === 'admin');

if (!$isCustomer && !$isDriver && !$isAdmin) {
    die("Akses ditolak. Anda bukan pelanggan atau driver pada pesanan ini.");
}

$backUrl = $isDriver ? 'driver.php' : 'pesanan_saya.php?id=' . $pesananId;

if ($isCustomer) {
    $opponentName = $order['nama_driver'] ?: 'Driver Sedang Menunggu';
    $opponentRole = 'Driver Penanggung Jawab';
    $opponentInitial = strtoupper(substr($opponentName, 0, 1));
    $rawHp = $order['no_hp_driver'] ?? '';
    $waNumber = preg_replace('/\D/', '', $rawHp);
    if (str_starts_with($waNumber, '0')) $waNumber = '62' . substr($waNumber, 1);
    $waUrl = $waNumber ? "https://wa.me/{$waNumber}?text=" . urlencode("Halo, saya pelanggan pesanan #PESANAN-{$order['id']} ({$order['layanan']}).") : '';
} else {
    $opponentName = $order['nama_pelanggan'] ?: 'Pelanggan';
    $opponentRole = 'Pelanggan Pemesan';
    $opponentInitial = strtoupper(substr($opponentName, 0, 1));
    $rawHp = $order['no_wa_pelanggan'] ?? '';
    $waNumber = preg_replace('/\D/', '', $rawHp);
    if (str_starts_with($waNumber, '0')) $waNumber = '62' . substr($waNumber, 1);
    $waUrl = $waNumber ? "https://wa.me/{$waNumber}?text=" . urlencode("Halo {$opponentName}, saya driver pesanan #PESANAN-{$order['id']} ({$order['layanan']}).") : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Chat Pesanan #<?= (int)$order['id'] ?> - <?= htmlspecialchars($opponentName) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="page-chat">

<div class="chat-app-wrap">

    <!-- HEADER ROOM -->
    <div class="chat-header">
        <div class="header-left">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn-back-chat" title="Kembali">
                &#8592;
            </a>
            <div class="chat-avatar">
                <?= $opponentInitial ?>
            </div>
            <div class="chat-user-meta">
                <h2 class="chat-user-name"><?= htmlspecialchars($opponentName) ?></h2>
                <div class="chat-user-role">
                    <span class="online-dot"></span>
                    <span><?= htmlspecialchars($opponentRole) ?></span>
                </div>
            </div>
        </div>

        <div class="header-actions">
            <button type="button" id="btnSoundToggle" class="btn-sound-toggle" title="Notifikasi Suara: Aktif">
                <svg id="soundIconOn" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                </svg>
                <svg id="soundIconOff" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <line x1="23" y1="9" x2="17" y2="15"></line>
                    <line x1="17" y1="9" x2="23" y2="15"></line>
                </svg>
            </button>
            <?php if (!empty($waUrl)): ?>
                <a href="<?= $waUrl ?>" target="_blank" rel="noopener noreferrer" class="btn-wa-shortcut">
                    WhatsApp
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- PINNED ORDER INFO BAR -->
    <div class="chat-order-bar">
        <div class="chat-order-info">
            <strong>#PESANAN-<?= (int)$order['id'] ?></strong> &bull; <?= htmlspecialchars($order['layanan']) ?>
            <?php if (!empty($order['total_tarif'])): ?>
                &bull; Rp <?= number_format((int)$order['total_tarif'], 0, ',', '.') ?>
            <?php else: ?>
                &bull; <em>Tarif Nego</em>
            <?php endif; ?>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <?php if (!empty($order['dest_lat'])): ?>
                <?php 
                    $cPlat = (!empty($order['pickup_lat']) && (float)$order['pickup_lat'] != 0) ? $order['pickup_lat'] : '-6.4018000';
                    $cPlng = (!empty($order['pickup_lng']) && (float)$order['pickup_lng'] != 0) ? $order['pickup_lng'] : '108.2825000';
                ?>
                <a href="https://www.google.com/maps/dir/?api=1&origin=<?= $cPlat ?>,<?= $cPlng ?>&destination=<?= $order['dest_lat'] ?>,<?= $order['dest_lng'] ?>" target="_blank" rel="noopener noreferrer" style="font-size: 11px; font-weight: 700; color: #1e40af; background: #e0f2fe; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;">
                    Rute
                </a>
            <?php endif; ?>
            <span class="chat-order-status"><?= htmlspecialchars($order['status']) ?></span>
        </div>
    </div>

    <!-- AREA DAFTAR PESAN -->
    <div id="chatMessagesArea" class="chat-messages-area">
        <div class="chat-empty" id="chatEmptyState">
            
            <div class="chat-empty-title">Ruang Chat Terbuka</div>
            <div class="chat-empty-desc">
                Kirim pesan untuk berkomunikasi langsung mengenai pesanan ini secara real-time.
            </div>
        </div>
    </div>

    <!-- QUICK REPLIES CHIPS -->
    <div class="quick-replies-wrap">
        <?php if ($isDriver): ?>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Halo kak, pesanan sedang diproses ya! 🛵')">🛵 Sedang Diproses</button>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Saya sedang menuju ke lokasi Anda kak 📍')">📍 Menuju Lokasi</button>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Saya sudah sampai di depan ya kak! 👋')">👋 Sudah Sampai</button>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Oke siap kak, terima kasih!')">👍 Oke Siap</button>
        <?php else: ?>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Halo kak, tolong dibantu ya pesanan saya 🙏')">🙏 Tolong Dibantu</button>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Sudah saya bayar via QRIS kak, tolong dicek ya 💳')">💳 Cek Pembayaran</button>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Saya tunggu di depan gerbang ya kak 🏠')">🏠 Tunggu di Depan</button>
            <button type="button" class="quick-chip" onclick="sendQuickReply('Terima kasih banyak kak! 👍')">👍 Terima Kasih</button>
        <?php endif; ?>
    </div>

    <!-- INPUT BAR -->
    <form id="chatForm" class="chat-input-bar">
        <textarea 
            id="chatInput" 
            class="chat-textarea" 
            placeholder="Ketik pesan..." 
            rows="1" 
            required
        ></textarea>
        <button type="submit" id="btnSend" class="btn-send-chat" title="Kirim Pesan">
            Kirim
        </button>
    </form>

</div>

<script>
const pesananId = <?= (int)$order['id'] ?>;
const myUserId  = <?= (int)$currentUserId ?>;
let lastMessageId = 0;
let isPolling = false;

const messagesArea = document.getElementById('chatMessagesArea');
const chatEmptyState = document.getElementById('chatEmptyState');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatInput');
const btnSend = document.getElementById('btnSend');

// ==========================================
// NOTIFIKASI SUARA (AUDIO ENGINE)
// ==========================================
let soundEnabled = localStorage.getItem('chat_sound_enabled') !== 'false';
const btnSoundToggle = document.getElementById('btnSoundToggle');
const soundIconOn = document.getElementById('soundIconOn');
const soundIconOff = document.getElementById('soundIconOff');

function updateSoundUI() {
    if (!btnSoundToggle) return;
    if (soundEnabled) {
        btnSoundToggle.classList.remove('muted');
        btnSoundToggle.title = 'Notifikasi Suara: Aktif (Klik untuk senyap)';
        soundIconOn.style.display = 'block';
        soundIconOff.style.display = 'none';
    } else {
        btnSoundToggle.classList.add('muted');
        btnSoundToggle.title = 'Notifikasi Suara: Senyap (Klik untuk aktifkan)';
        soundIconOn.style.display = 'none';
        soundIconOff.style.display = 'block';
    }
}
updateSoundUI();

// Pre-load audio effects
const audioReceive = new Audio('assets/sound/notification.wav');
const audioSend = new Audio('assets/sound/send.wav');
audioReceive.preload = 'auto';
audioSend.preload = 'auto';

// Web Audio API Synthesizer (Fallback & Instant response)
let audioCtx = null;
function getAudioContext() {
    if (!audioCtx) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (AudioContextClass) audioCtx = new AudioContextClass();
    }
    if (audioCtx && audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
    return audioCtx;
}

// Buka kunci AudioContext saat ada interaksi pengguna
function unlockAudio() {
    getAudioContext();
    if (audioReceive) audioReceive.load();
    if (audioSend) audioSend.load();
}
['click', 'touchstart', 'keydown'].forEach(evt => {
    document.addEventListener(evt, unlockAudio, { once: true, passive: true });
});

// Suara notifikasi pesan masuk (Harmonious Chime)
function playReceiveSound() {
    if (!soundEnabled) return;
    try {
        audioReceive.currentTime = 0;
        const playPromise = audioReceive.play();
        if (playPromise !== undefined) {
            playPromise.catch(() => {
                playWebAudioChime();
            });
        }
    } catch (e) {
        playWebAudioChime();
    }
}

// Web Audio Chime Synthesizer
function playWebAudioChime() {
    try {
        const ctx = getAudioContext();
        if (!ctx) return;
        const now = ctx.currentTime;

        // Nada 1: 587.33 Hz (D5)
        const osc1 = ctx.createOscillator();
        const gain1 = ctx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(587.33, now);
        gain1.gain.setValueAtTime(0.001, now);
        gain1.gain.exponentialRampToValueAtTime(0.25, now + 0.02);
        gain1.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(now);
        osc1.stop(now + 0.19);

        // Nada 2: 880 Hz (A5)
        const osc2 = ctx.createOscillator();
        const gain2 = ctx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(880, now + 0.08);
        gain2.gain.setValueAtTime(0.001, now + 0.08);
        gain2.gain.exponentialRampToValueAtTime(0.3, now + 0.10);
        gain2.gain.exponentialRampToValueAtTime(0.0001, now + 0.38);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(now + 0.08);
        osc2.stop(now + 0.40);
    } catch (err) {
        console.warn('Web Audio error:', err);
    }
}

// Suara halus saat pesan terkirim
function playSendSound() {
    if (!soundEnabled) return;
    try {
        audioSend.currentTime = 0;
        const p = audioSend.play();
        if (p !== undefined) p.catch(() => {});
    } catch (e) {}
}

// Toggle mute suara
if (btnSoundToggle) {
    btnSoundToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        soundEnabled = !soundEnabled;
        localStorage.setItem('chat_sound_enabled', soundEnabled ? 'true' : 'false');
        updateSoundUI();
        if (soundEnabled) {
            unlockAudio();
            playReceiveSound(); // Tes nada konfirmasi
        }
    });
}

// Ubah title tab saat ada pesan masuk dan browser sedang tidak aktif/di tab lain
const originalDocTitle = document.title;
let titleBlinkInterval = null;

function notifyBackgroundTab(senderName) {
    if (document.hidden) {
        clearInterval(titleBlinkInterval);
        let toggle = false;
        titleBlinkInterval = setInterval(() => {
            document.title = toggle ? `Pesan Baru dari ${senderName}!` : originalDocTitle;
            toggle = !toggle;
        }, 1200);
    }
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        clearInterval(titleBlinkInterval);
        document.title = originalDocTitle;
    }
});

// Scroll ke pesan paling bawah
function scrollToBottom(smooth = true) {
    messagesArea.scrollTo({
        top: messagesArea.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto'
    });
}

// Render satu pesan
function appendMessage(msg) {
    if (chatEmptyState) {
        chatEmptyState.style.display = 'none';
    }

    // Cek apakah pesan dengan ID ini sudah pernah di-render
    if (document.getElementById('msg-' + msg.id)) {
        return;
    }

    const row = document.createElement('div');
    row.id = 'msg-' + msg.id;
    row.className = 'message-row ' + (msg.is_me ? 'is-me' : 'is-other');

    const bubble = document.createElement('div');
    bubble.className = 'message-bubble';
    bubble.textContent = msg.pesan;

    const time = document.createElement('div');
    time.className = 'message-time-stamp';
    time.textContent = (msg.is_me ? 'Anda • ' : (msg.sender_nama + ' • ')) + (msg.time || '');

    row.appendChild(bubble);
    row.appendChild(time);

    messagesArea.appendChild(row);

    if (msg.id > lastMessageId) {
        lastMessageId = msg.id;
    }
}

// Fetch pesan dari server
function fetchMessages(isInitial = false) {
    if (isPolling) return;
    isPolling = true;

    fetch(`api/chat.php?pesanan_id=${pesananId}&last_id=${lastMessageId}`)
        .then(res => res.json())
        .then(res => {
            isPolling = false;
            if (res.status === 'success' && res.data && res.data.messages) {
                const list = res.data.messages;
                if (list.length > 0) {
                    let hasIncoming = false;
                    let lastSender = 'Pengguna';

                    list.forEach(m => {
                        appendMessage(m);
                        if (!m.is_me) {
                            hasIncoming = true;
                            lastSender = m.sender_nama || 'Pengguna';
                        }
                    });
                    scrollToBottom(!isInitial);

                    // Bunyikan notifikasi suara jika ada pesan baru dari lawan bicara (bukan saat pertama buka halaman)
                    if (!isInitial && hasIncoming) {
                        playReceiveSound();
                        notifyBackgroundTab(lastSender);
                    }
                }
            }
        })
        .catch(err => {
            isPolling = false;
            console.error('Chat poll error:', err);
        });
}

// Kirim pesan
function sendMessage(text) {
    const pesan = text.trim();
    if (!pesan) return;

    btnSend.disabled = true;

    fetch('api/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            pesanan_id: pesananId,
            pesan: pesan
        })
    })
    .then(res => res.json())
    .then(res => {
        btnSend.disabled = false;
        if (res.status === 'success' && res.data) {
            appendMessage(res.data);
            scrollToBottom(true);
            playSendSound();
            chatInput.value = '';
            chatInput.style.height = 'auto';
            chatInput.focus();
        } else {
            alert('Gagal mengirim pesan: ' + (res.message || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        btnSend.disabled = false;
        console.error('Send error:', err);
        alert('Gagal mengirim pesan. Periksa koneksi Anda.');
    });
}

// Quick reply chip click
function sendQuickReply(text) {
    sendMessage(text);
}

// Event submit form chat
chatForm.addEventListener('submit', function(e) {
    e.preventDefault();
    sendMessage(chatInput.value);
});

// Kirim via Enter (Shift+Enter untuk baris baru)
chatInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        chatForm.dispatchEvent(new Event('submit'));
    }
});

// Auto-expand textarea
chatInput.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
});

// Inisialisasi awal
fetchMessages(true);

// Realtime polling setiap 2.5 detik
setInterval(() => {
    fetchMessages(false);
}, 2500);
</script>

</body>
</html>

