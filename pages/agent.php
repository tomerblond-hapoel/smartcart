<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';

$page_title = $t['agent_title'];

require_login();
$user_id           = current_user_id();
$user              = get_current_user_data();
$has_prefs         = !empty($user['preferred_categories']);
$user_first_name   = htmlspecialchars(explode(' ', trim($_SESSION['user_name'] ?? 'there'))[0]);

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Override main wrapper so the chat fills full height ── */
main.main-content {
    padding: 0 !important;
    overflow: hidden !important;
}

/* ── Chat shell ─────────────────────────────────────────── */
.sc-chat {
    display: flex;
    flex-direction: column;
    height: calc(100dvh - var(--nav-height, 64px));
    max-width: 800px;
    margin: 0 auto;
    padding: 0;
    position: relative;
    overflow: hidden;
}

/* ── Header strip ───────────────────────────────────────── */
.sc-chat-header {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px 12px;
    background: white;
    border-bottom: 1px solid var(--border);
    box-shadow: 0 1px 0 var(--border);
}
.sc-chat-avatar {
    width: 40px;
    height: 40px;
    border-radius: 14px;
    background: var(--primary-gradient);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(var(--primary-rgb),.30);
}
.sc-chat-header-text h2 {
    font-size: 15px;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1.2;
}
.sc-chat-header-text p {
    font-size: 12px;
    color: var(--green);
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 4px;
}
.sc-chat-header-text p::before {
    content: '';
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 0 2px rgba(26,199,133,.25);
}

/* ── Messages area ──────────────────────────────────────── */
.sc-messages {
    flex: 1;
    overflow-y: auto;
    padding: 24px 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    scroll-behavior: smooth;
}
.sc-messages::-webkit-scrollbar { width: 4px; }
.sc-messages::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }

/* ── Message rows ───────────────────────────────────────── */
.sc-msg {
    display: flex;
    gap: 10px;
    max-width: 100%;
    animation: sc-in .28s cubic-bezier(.22,.61,.36,1) both;
}
.sc-msg + .sc-msg { margin-top: 8px; }
.sc-msg.user { flex-direction: row-reverse; }
.sc-msg.group-tail { margin-top: 2px !important; }

@keyframes sc-in {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Avatars */
.sc-av {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    margin-top: 2px;
    align-self: flex-end;
}
.sc-msg.ai   .sc-av { background: var(--primary-gradient); box-shadow: 0 2px 6px rgba(var(--primary-rgb),.25); }
.sc-msg.user .sc-av { background: var(--gray-200); color: var(--gray-600); font-size: 13px; }
.sc-msg.group-tail .sc-av { visibility: hidden; }

/* Bubble wrappers */
.sc-bubble-wrap { max-width: min(75%, 560px); display: flex; flex-direction: column; gap: 6px; }
.sc-msg.user .sc-bubble-wrap { align-items: flex-end; }

/* AI bubble */
.sc-bubble-ai {
    background: white;
    border: 1px solid var(--border);
    border-radius: 4px 18px 18px 18px;
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.65;
    color: var(--gray-900);
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}

/* User bubble */
.sc-bubble-user {
    background: var(--primary-gradient);
    color: white;
    border-radius: 18px 4px 18px 18px;
    padding: 11px 15px;
    font-size: 14px;
    line-height: 1.5;
    display: inline-block;
    box-shadow: 0 2px 8px rgba(var(--primary-rgb),.25);
}

/* Time label */
.sc-time {
    font-size: 10.5px;
    color: var(--gray-400);
    padding: 0 4px;
    align-self: flex-end;
}

/* ── Typing indicator ────────────────────────────────────── */
.sc-typing .sc-bubble-ai {
    padding: 13px 18px;
    display: inline-flex;
    align-items: center;
}
.sc-typing-dots {
    display: flex;
    gap: 5px;
    align-items: center;
}
.sc-typing-dots span {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--primary);
    opacity: .35;
    animation: sc-bounce 1.3s infinite ease-in-out;
}
.sc-typing-dots span:nth-child(2) { animation-delay: .18s; }
.sc-typing-dots span:nth-child(3) { animation-delay: .36s; }
@keyframes sc-bounce {
    0%,60%,100% { transform: translateY(0);    opacity: .35; }
    30%          { transform: translateY(-7px); opacity: 1;   }
}

/* ── Product cards inside chat ──────────────────────────── */
.sc-cards {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    padding: 8px 0 4px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin-top: 6px;
    max-width: calc(min(75vw, 560px) + 20px);
}
.sc-cards::-webkit-scrollbar { display: none; }
.sc-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    min-width: 185px;
    max-width: 200px;
    flex-shrink: 0;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    transition: transform .18s, box-shadow .18s;
    display: flex;
    flex-direction: column;
}
.sc-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.10); }
.sc-card-img {
    width: 100%;
    height: 100px;
    object-fit: cover;
    display: block;
}
.sc-card-img-placeholder {
    width: 100%;
    height: 80px;
    background: var(--primary-50);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
}
.sc-card-body { padding: 10px 12px 12px; flex: 1; display: flex; flex-direction: column; }
.sc-card-title {
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.3;
    color: var(--gray-900);
    margin-bottom: 3px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.sc-card-biz { font-size: 11px; color: var(--gray-500); margin-bottom: 5px; }
.sc-card-reason {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: var(--primary-50);
    color: var(--primary);
    border: 1px solid var(--primary-light);
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 10.5px;
    font-weight: 600;
    margin-bottom: 7px;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sc-card-price-row {
    display: flex;
    align-items: baseline;
    gap: 5px;
    margin-bottom: 7px;
}
.sc-card-price { font-size: 16px; font-weight: 800; color: var(--primary); }
.sc-card-orig  { font-size: 11px; color: var(--gray-400); text-decoration: line-through; }
.sc-card-badge {
    background: #dcfce7;
    color: #166534;
    border-radius: 6px;
    padding: 2px 6px;
    font-size: 10.5px;
    font-weight: 700;
    margin-left: auto;
    white-space: nowrap;
}
.sc-card-fill { margin-bottom: 8px; }
.sc-card-fill-bar {
    height: 3px;
    background: var(--gray-200);
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 3px;
}
.sc-card-fill-bar-inner { height: 100%; border-radius: 999px; }
.sc-card-fill-meta {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: var(--gray-400);
}
.sc-card-btn {
    display: block;
    background: var(--primary-gradient);
    color: white;
    text-align: center;
    padding: 7px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    text-decoration: none;
    margin-top: auto;
    transition: opacity .15s;
}
.sc-card-btn:hover { opacity: .9; color: white; }
/* "Start a group" variant — outlined, fills on hover */
.sc-card-btn-start {
    background: white;
    color: var(--primary);
    border: 1.5px solid var(--primary);
}
.sc-card-btn-start:hover { background: var(--primary-gradient); color: white; border-color: transparent; opacity: 1; }

/* ── Action chips (links under AI message) ───────────────── */
.sc-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    margin-top: 8px;
}
.sc-action {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--primary-50);
    color: var(--primary);
    border: 1px solid var(--primary-light);
    border-radius: 20px;
    padding: 5px 13px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all .14s;
}
.sc-action:hover { background: var(--primary); color: white; border-color: var(--primary); }

/* ── Input zone ─────────────────────────────────────────── */
.sc-input-zone {
    flex-shrink: 0;
    padding: 10px 16px;
    padding-bottom: max(16px, env(safe-area-inset-bottom, 16px));
    background: linear-gradient(to top, var(--gray-50) 80%, transparent);
}
@media (max-width: 768px) {
    .sc-input-zone { padding-bottom: 75px; }
}

/* Suggested prompts */
.sc-prompts {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    margin-bottom: 10px;
    transition: opacity .25s;
}
.sc-prompt {
    background: white;
    border: 1.5px solid var(--border);
    border-radius: 20px;
    padding: 6px 13px;
    font-size: 12.5px;
    cursor: pointer;
    color: var(--gray-700);
    white-space: nowrap;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    transition: all .15s;
    font-family: var(--font);
}
.sc-prompt:hover {
    background: var(--primary-50);
    border-color: var(--primary);
    color: var(--primary);
    box-shadow: 0 1px 6px rgba(var(--primary-rgb),.15);
}

/* Input bar */
.sc-input-bar {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    background: white;
    border: 1.5px solid var(--border);
    border-radius: 16px;
    padding: 10px 10px 10px 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
    transition: border-color .2s, box-shadow .2s;
}
.sc-input-bar:focus-within {
    border-color: var(--primary);
    box-shadow: 0 4px 20px rgba(var(--primary-rgb),.12);
}
.sc-input-bar textarea {
    flex: 1;
    border: none;
    outline: none;
    resize: none;
    font-family: var(--font);
    font-size: 14px;
    line-height: 1.5;
    max-height: 120px;
    min-height: 22px;
    color: var(--text);
    background: transparent;
}
.sc-input-bar textarea::placeholder { color: var(--gray-400); }
.sc-send {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: var(--primary-gradient);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(var(--primary-rgb),.30);
    transition: transform .15s, box-shadow .15s, opacity .15s;
}
.sc-send:hover  { transform: scale(1.06); box-shadow: 0 4px 12px rgba(var(--primary-rgb),.35); }
.sc-send:active { transform: scale(.94); }
.sc-send:disabled { opacity: .45; cursor: not-allowed; transform: none !important; box-shadow: none; }
.sc-send svg { color: white; display: block; }

/* Input hint */
.sc-input-hint {
    text-align: center;
    font-size: 11px;
    color: var(--gray-400);
    margin-top: 7px;
}
/* Links inside AI bubbles */
.sc-link {
    color: var(--primary);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
}
</style>

<div class="sc-chat">

    <!-- Header -->
    <div class="sc-chat-header">
        <div class="sc-chat-avatar">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 3l1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3z"/>
                <path d="M5 3l.5 2 2 .5-2 .5-.5 2-.5-2-2-.5 2-.5.5-2z"/>
                <path d="M19 16l.5 2 2 .5-2 .5-.5 2-.5-2-2-.5 2-.5.5-2z"/>
            </svg>
        </div>
        <div class="sc-chat-header-text">
            <h2>SmartCart AI Assistant</h2>
            <p>Online — ready to help</p>
        </div>
    </div>

    <!-- Messages -->
    <div class="sc-messages" id="sc-messages"></div>

    <!-- Input zone -->
    <div class="sc-input-zone">
        <div class="sc-prompts" id="sc-prompts">
            <button class="sc-prompt" data-prompt="Looking for JBL speakers">🎵 JBL speakers</button>
            <button class="sc-prompt" data-prompt="Find me iPhone deals">📱 iPhone deals</button>
            <button class="sc-prompt" data-prompt="Student laptop deals">💻 Student laptops</button>
            <button class="sc-prompt" data-prompt="Best electronics discounts">⚡ Electronics</button>
            <button class="sc-prompt" data-prompt="Show me all open groups">🛍️ All open groups</button>
        </div>
        <div class="sc-input-bar">
            <textarea id="sc-input" rows="1"
                      placeholder="Ask me anything — e.g. 'Find JBL speakers' or 'iPhone deals'…"></textarea>
            <button class="sc-send" id="sc-send" title="Send message">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </div>
        <p class="sc-input-hint">Press <kbd style="background:var(--gray-100);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px;">Enter</kbd> to send &nbsp;·&nbsp; <kbd style="background:var(--gray-100);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px;">Shift+Enter</kbd> for new line</p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
/* ── Constants ───────────────────────────────────────────── */
const SC_USER_ID  = <?= $user_id ?>;
const SC_APP_URL  = '<?= APP_URL ?>';
const SC_NAME     = '<?= addslashes($user_first_name) ?>';
const SC_HAS_PREF = <?= $has_prefs ? 'true' : 'false' ?>;

/* ── State ───────────────────────────────────────────────── */
let chatHistory   = [];    // [{role:'user'|'assistant', content:'...'}]
let pendingAction = null;  // {type:'create_group', product_id, product_name, min_participants, target?, step}
let userLat = null, userLng = null;
let isBusy = false;

/* ── Helpers ─────────────────────────────────────────────── */
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function md(t) {
    return String(t)
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/g, '<a href="$2" class="sc-link">$1</a>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/\n/g, '<br>');
}
function imgSrc(url) {
    if (!url) return '';
    return (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('//'))
        ? url : SC_APP_URL + url;
}
function catEmoji(c) {
    return {electronics:'💻',home:'🏠',fashion:'👗',food:'🍎',sports:'⚽',beauty:'💄',toys:'🧸',books:'📚',automotive:'🚗'}[c] || '📦';
}
function nowTime() { return new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}); }
function scrollEnd() { const el = document.getElementById('sc-messages'); if (el) el.scrollTop = el.scrollHeight + 999; }
function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

/* ── Message row ─────────────────────────────────────────── */
function addMsg(role, html, opts = {}) {
    const wrap = document.getElementById('sc-messages');
    const row  = document.createElement('div');
    row.className = 'sc-msg ' + role + (opts.tail ? ' group-tail' : '');
    const avHTML     = role === 'ai' ? `<div class="sc-av">✦</div>` : `<div class="sc-av">👤</div>`;
    const bubbleHTML = role === 'ai'
        ? `<div class="sc-bubble-ai">${html}</div>`
        : `<div class="sc-bubble-user">${esc(html)}</div>`;
    row.innerHTML = avHTML + `<div class="sc-bubble-wrap">${bubbleHTML}<span class="sc-time">${nowTime()}</span></div>`;
    wrap.appendChild(row);
    scrollEnd();
    return row;
}
function showTyping() {
    const wrap = document.getElementById('sc-messages');
    const row  = document.createElement('div');
    row.className = 'sc-msg ai sc-typing';
    row.id = 'sc-typing';
    row.innerHTML = `<div class="sc-av">✦</div>
        <div class="sc-bubble-wrap"><div class="sc-bubble-ai">
            <div class="sc-typing-dots"><span></span><span></span><span></span></div>
        </div></div>`;
    wrap.appendChild(row);
    scrollEnd();
}
function hideTyping() { const el = document.getElementById('sc-typing'); if (el) el.remove(); }

/* ── Group cards — open groups with in-chat Join button ──── */
function renderGroupCards(groups) {
    if (!groups || !groups.length) return '';
    const items = groups.slice(0, 5).map(g => {
        const fill      = g.fill_percent || 0;
        const fillColor = fill >= 75 ? 'var(--green)' : fill >= 40 ? 'var(--orange)' : 'var(--primary)';
        const spots     = g.target_participants - g.current_participants;
        const img       = g.product_image
            ? `<img class="sc-card-img" src="${imgSrc(g.product_image)}" alt="${esc(g.product_name)}" loading="lazy">`
            : `<div class="sc-card-img-placeholder">${catEmoji(g.category)}</div>`;
        return `<div class="sc-card">
            ${img}
            <div class="sc-card-body">
                <div class="sc-card-title">${esc(g.product_name)}</div>
                <div class="sc-card-biz">${esc(g.business_name)}${g.city ? ' · ' + esc(g.city) : ''}</div>
                <div class="sc-card-price-row">
                    <span class="sc-card-price">₪${parseFloat(g.group_price_ils).toLocaleString()}</span>
                    <span class="sc-card-orig">₪${parseFloat(g.price_ils).toLocaleString()}</span>
                    <span class="sc-card-badge">-${g.discount_percent}%</span>
                </div>
                <div class="sc-card-fill">
                    <div class="sc-card-fill-bar">
                        <div class="sc-card-fill-bar-inner" style="width:${fill}%;background:${fillColor};"></div>
                    </div>
                    <div class="sc-card-fill-meta">
                        <span>${g.current_participants}/${g.target_participants} joined · ${spots} spot${spots !== 1 ? 's' : ''} left</span>
                        <span>${g.countdown}</span>
                    </div>
                </div>
                <button class="sc-card-btn sc-join-btn"
                        data-group-id="${g.group_id}"
                        data-product-name="${esc(g.product_name)}">
                    Join Group →
                </button>
            </div>
        </div>`;
    }).join('');
    return `<div class="sc-cards">${items}</div>`;
}

/* ── Product cards — no open group, offer to start one ───── */
function renderProductCards(products) {
    if (!products || !products.length) return '';
    const items = products.slice(0, 4).map(p => {
        const disc = parseInt(p.discount_percent) || 0;
        const img  = p.product_image
            ? `<img class="sc-card-img" src="${imgSrc(p.product_image)}" alt="${esc(p.product_name)}" loading="lazy">`
            : `<div class="sc-card-img-placeholder">${catEmoji(p.category)}</div>`;
        return `<div class="sc-card">
            ${img}
            <div class="sc-card-body">
                <div class="sc-card-title">${esc(p.product_name)}</div>
                <div class="sc-card-biz">${esc(p.business_name)}</div>
                <div class="sc-card-price-row">
                    <span class="sc-card-price">₪${parseFloat(p.group_price_ils).toLocaleString()}</span>
                    <span class="sc-card-orig">₪${parseFloat(p.price_ils).toLocaleString()}</span>
                    ${disc > 0 ? `<span class="sc-card-badge">-${disc}%</span>` : ''}
                </div>
                <div style="font-size:11px;color:var(--gray-500);margin:4px 0 8px;">
                    Min ${p.min_participants} members to activate
                </div>
                <button class="sc-card-btn sc-card-btn-start sc-create-btn"
                        data-product-id="${p.id}"
                        data-product-name="${esc(p.product_name)}"
                        data-min="${p.min_participants}">
                    ✦ Start a Group
                </button>
            </div>
        </div>`;
    }).join('');
    return `<div class="sc-cards">${items}</div>`;
}

/* ── In-chat button actions (Join + Start a Group) ────────── */
document.getElementById('sc-messages').addEventListener('click', async function (e) {

    /* ── Join group ── */
    const joinBtn = e.target.closest('.sc-join-btn');
    if (joinBtn && !joinBtn.disabled) {
        e.preventDefault();
        const groupId  = joinBtn.dataset.groupId;
        const prodName = joinBtn.dataset.productName;
        joinBtn.disabled = true;
        joinBtn.textContent = 'Joining…';

        try {
            const res  = await fetch(`${SC_APP_URL}/api/groups.php?action=join&group_id=${groupId}`, {
                method: 'POST', credentials: 'same-origin',
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Failed to join');

            joinBtn.textContent = '✓ Joined!';
            joinBtn.style.background = 'var(--green)';

            if (data.group_filled) {
                /* Group is full — show Pay Now button immediately */
                const payEl = document.createElement('a');
                payEl.href  = `${SC_APP_URL}/pages/group.php?id=${groupId}`;
                payEl.className = 'sc-card-btn';
                payEl.style.cssText = 'display:block;margin-top:6px;background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff;text-align:center;text-decoration:none;border-radius:10px;padding:9px;font-size:13px;font-weight:600;';
                payEl.textContent = '💳 Pay Now →';
                joinBtn.parentElement.appendChild(payEl);
                addMsg('ai', md(
                    `🎉 **The group is full, ${SC_NAME}!** Your spot is reserved.\n\n`
                    + `**You have 24 hours to complete payment.** Click "Pay Now" on the card above to secure your order.`
                ));
            } else {
                const rem = data.target - data.current;
                addMsg('ai', md(
                    `✅ **You joined "${prodName}"!** The group now has **${data.current}/${data.target} members** — `
                    + `${rem} more spot${rem !== 1 ? 's' : ''} needed.\n\n`
                    + `You'll get a notification when the group fills up and payment is due.`
                ));
            }
        } catch (err) {
            joinBtn.disabled = false;
            joinBtn.textContent = 'Join Group →';
            addMsg('ai', md(`Sorry — couldn't join: **${esc(err.message)}**`));
        }
        scrollEnd();
        return;
    }

    /* ── Start a Group (opens create-group step machine) ── */
    const createBtn = e.target.closest('.sc-create-btn');
    if (createBtn && !createBtn.disabled) {
        e.preventDefault();
        createBtn.disabled    = true;
        createBtn.textContent = '⏳ Starting…';

        pendingAction = {
            type:             'create_group',
            product_id:       parseInt(createBtn.dataset.productId),
            product_name:     createBtn.dataset.productName,
            min_participants: parseInt(createBtn.dataset.min) || 2,
            step:             'ask_target',
        };

        await delay(250);
        addMsg('ai', md(
            `Let's set up a group for **${pendingAction.product_name}**! 🎉\n\n`
            + `How many members should the group have? *(minimum: ${pendingAction.min_participants})*`
        ));
        scrollEnd();
    }
});

/* ── Create group step machine ───────────────────────────── */
async function handlePendingAction(text) {
    const action = pendingAction;
    if (!action || action.type !== 'create_group') { pendingAction = null; return; }

    /* Step 1: ask how many members */
    if (action.step === 'ask_target') {
        const n = parseInt(text.replace(/\D/g, ''));
        if (!n || n < action.min_participants) {
            addMsg('ai', md(`Please enter a number **≥ ${action.min_participants}** (the minimum for this product).`));
            return;
        }
        pendingAction.target = n;
        pendingAction.step   = 'ask_deadline';
        addMsg('ai', md(
            `**${n} members** — perfect! ⏱️\n\n`
            + `When should the group close? You can say:\n`
            + `• *"in 7 days"*  • *"in 2 weeks"*  • *"in a month"*`
        ));
        return;
    }

    /* Step 2: parse deadline, then create the group */
    if (action.step === 'ask_deadline') {
        const lower  = text.toLowerCase();
        let days = null;
        const mD = lower.match(/(\d+)\s*(day|יום)/i);
        const mW = lower.match(/(\d+)\s*(week|שבוע)/i);
        const mM = lower.match(/(\d+)\s*(month|חודש)/i);
        if (mD)                                 days = parseInt(mD[1]);
        else if (mW)                            days = parseInt(mW[1]) * 7;
        else if (mM)                            days = parseInt(mM[1]) * 30;
        else if (/tomorrow|מחר/.test(lower))   days = 1;
        else if (/\bweek\b|שבוע/.test(lower))  days = 7;
        else if (/\bmonth\b|חודש/.test(lower)) days = 30;

        if (!days || days < 1 || days > 180) {
            addMsg('ai', md('Please say something like **"in 7 days"** or **"in 2 weeks"** (1–180 days).'));
            return;
        }

        pendingAction.step = 'creating';
        showTyping();

        const dl = new Date();
        dl.setDate(dl.getDate() + days);
        const deadlineStr = dl.toISOString().slice(0, 19).replace('T', ' ');

        try {
            const res  = await fetch(`${SC_APP_URL}/api/groups.php?action=create`, {
                method:      'POST',
                credentials: 'same-origin',
                headers:     {'Content-Type': 'application/json'},
                body:        JSON.stringify({
                    product_id:          action.product_id,
                    target_participants: action.target,
                    deadline:            deadlineStr,
                }),
            });
            const data = await res.json();
            hideTyping();
            if (!res.ok) throw new Error(data.error || 'Could not create group');

            pendingAction = null;
            addMsg('ai', md(
                `🎉 **Group created!** You're the first member (${action.target - 1} more spot${action.target - 1 !== 1 ? 's' : ''} to go).\n\n`
                + `Once ${action.target} people join, everyone pays together and unlocks the group discount!\n\n`
                + `[**View your group →**](${SC_APP_URL}/pages/group.php?id=${data.group_id})`
            ));
        } catch (err) {
            hideTyping();
            pendingAction = null;
            addMsg('ai', `Sorry, I couldn't create the group: ${esc(err.message)}`);
        }
        scrollEnd();
    }
}

/* ── Send ────────────────────────────────────────────────── */
async function send(text) {
    text = text.trim();
    if (!text) return;

    /* Route to create-group step machine when active */
    if (pendingAction) {
        addMsg('user', text);
        await delay(120);
        await handlePendingAction(text);
        return;
    }

    if (isBusy) return;
    isBusy = true;

    /* Hide quick-prompt chips after first real send */
    const prompts = document.getElementById('sc-prompts');
    if (prompts) { prompts.style.opacity = '0'; setTimeout(() => prompts.style.display = 'none', 250); }

    addMsg('user', text);

    const inp = document.getElementById('sc-input');
    const btn = document.getElementById('sc-send');
    inp.value = '';
    inp.style.height = 'auto';
    btn.disabled = true;

    /* Track in conversation history (user turn first) */
    chatHistory.push({role: 'user', content: text});
    if (chatHistory.length > 20) chatHistory.shift();

    await delay(280);
    showTyping();

    try {
        const res = await fetch(`${SC_APP_URL}/api/agent_chat.php`, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     {'Content-Type': 'application/json'},
            body:        JSON.stringify({
                message:  text,
                history:  chatHistory.slice(0, -1), // history before current turn
                user_lat: userLat,
                user_lng: userLng,
            }),
        });

        const data = await res.json();
        await delay(350);
        hideTyping();

        if (!res.ok) throw new Error(data.error || `Server error ${res.status}`);

        const replyText = data.message  || '';
        const intent    = data.intent   || 'other';
        const groups    = data.groups   || [];
        const products  = data.products || [];

        let html = md(replyText);

        if (intent !== 'off_topic') {
            if (intent === 'create' && products.length) {
                /* Create intent: show product cards (to start a group) */
                html += renderProductCards(products);
            } else if (groups.length) {
                /* Search found open groups: show group cards with Join button */
                html += renderGroupCards(groups);
            } else if (products.length) {
                /* No open groups, but products exist: offer to start a group */
                html += renderProductCards(products);
            }
        }

        addMsg('ai', html);

        /* Track assistant response in history */
        chatHistory.push({role: 'assistant', content: replyText});
        if (chatHistory.length > 20) chatHistory.shift();

    } catch (e) {
        hideTyping();
        addMsg('ai', 'Oops, something went wrong connecting to the server. Please try again! 🙁');
    } finally {
        btn.disabled = false;
        isBusy = false;
        inp.focus();
    }
}

/* ── Input bar events ────────────────────────────────────── */
const inp = document.getElementById('sc-input');
const btn = document.getElementById('sc-send');

inp.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});
inp.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(this.value); }
});
btn.addEventListener('click', () => send(inp.value));

document.querySelectorAll('.sc-prompt').forEach(el => {
    el.addEventListener('click', () => send(el.dataset.prompt));
});

/* ── Init ────────────────────────────────────────────────── */
(function init() {
    const greet = SC_HAS_PREF
        ? `Hi **${SC_NAME}**! 👋 I'm your **SmartCart AI Assistant**.\n\nI can find open group deals or show picks based on your saved preferences.\n\nWhat are you shopping for today?`
        : `Hi **${SC_NAME}**! 👋 I'm your **SmartCart AI Assistant**.\n\nI help you find group purchases so you can save money together. Tell me what you're looking for — like *"JBL speaker"* or *"iPhone deals"* — and I'll search all open groups!\n\nTap a suggestion below or type your own.`;

    addMsg('ai', md(greet));

    /* Auto-load profile picks (uses existing api/agent.php, profile mode) */
    if (SC_HAS_PREF) {
        delay(1300).then(async () => {
            showTyping();
            try {
                const res     = await fetch(`${SC_APP_URL}/api/agent.php?user_id=${SC_USER_ID}&limit=6`);
                const data    = await res.json();
                const results = Array.isArray(data) ? data : (data.results || []);
                await delay(600);
                hideTyping();
                if (results.length) {
                    const n = results.length;
                    addMsg('ai',
                        md(`Based on your preferences, here are **${n} open group${n > 1 ? 's' : ''}** you might like! 🎉`)
                        + renderGroupCards(results)
                    );
                }
            } catch(e) { hideTyping(); }
        });
    }

    /* Silently request geolocation for distance-based ranking */
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => { userLat = pos.coords.latitude; userLng = pos.coords.longitude; },
            () => {},
            {timeout: 5000}
        );
    }
})();
</script>
