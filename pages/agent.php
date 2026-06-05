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
/* ── Constants ─────────────────────────────────────────── */
const SC_USER_ID  = <?= $user_id ?>;
const SC_APP_URL  = '<?= APP_URL ?>';
const SC_NAME     = '<?= addslashes($user_first_name) ?>';
const SC_HAS_PREF = <?= $has_prefs ? 'true' : 'false' ?>;

/* ── Helpers ────────────────────────────────────────────── */
function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function md(t) {
    return String(t)
        .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
        .replace(/\*(.+?)\*/g,'<em>$1</em>')
        .replace(/\n/g,'<br>');
}
/* Returns a safe image src whether the DB stores a full URL or a relative path */
function imgSrc(url) {
    if (!url) return '';
    return (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('//'))
        ? url
        : SC_APP_URL + url;
}
function catEmoji(c) {
    return {electronics:'💻',home:'🏠',fashion:'👗',food:'🍎',sports:'⚽',beauty:'💄',toys:'🧸',books:'📚',automotive:'🚗'}[c] || '📦';
}
function nowTime() {
    return new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
}
function scrollEnd() {
    const el = document.getElementById('sc-messages');
    if (el) el.scrollTop = el.scrollHeight + 999;
}

/* ── Render a message row ───────────────────────────────── */
function addMsg(role, html, opts = {}) {
    const wrap = document.getElementById('sc-messages');
    const row  = document.createElement('div');
    row.className = 'sc-msg ' + role + (opts.tail ? ' group-tail' : '');

    const avHTML = role === 'ai'
        ? `<div class="sc-av">✦</div>`
        : `<div class="sc-av">👤</div>`;

    const bubbleHTML = role === 'ai'
        ? `<div class="sc-bubble-ai">${html}</div>`
        : `<div class="sc-bubble-user">${esc(html)}</div>`;

    row.innerHTML = avHTML
        + `<div class="sc-bubble-wrap">${bubbleHTML}<span class="sc-time">${nowTime()}</span></div>`;

    wrap.appendChild(row);
    scrollEnd();
    return row;
}

/* ── Typing indicator ───────────────────────────────────── */
function showTyping() {
    const wrap = document.getElementById('sc-messages');
    const row  = document.createElement('div');
    row.className = 'sc-msg ai sc-typing';
    row.id = 'sc-typing';
    row.innerHTML = `<div class="sc-av">✦</div>
        <div class="sc-bubble-wrap">
            <div class="sc-bubble-ai">
                <div class="sc-typing-dots"><span></span><span></span><span></span></div>
            </div>
        </div>`;
    wrap.appendChild(row);
    scrollEnd();
}
function hideTyping() {
    const el = document.getElementById('sc-typing');
    if (el) el.remove();
}

/* ── Product cards ──────────────────────────────────────── */
function renderCards(results) {
    if (!results || !results.length) return '';
    const items = results.slice(0, 3).map(r => {
        const fillColor = r.fill_percent >= 75 ? 'var(--green)' : r.fill_percent >= 40 ? 'var(--orange)' : 'var(--primary)';
        const img = r.product_image
            ? `<img class="sc-card-img" src="${imgSrc(r.product_image)}" alt="${esc(r.product_name)}" loading="lazy">`
            : `<div class="sc-card-img-placeholder">${catEmoji(r.category)}</div>`;
        const reason = r.match_reason
            ? `<div class="sc-card-reason">🎯 ${esc(r.match_reason)}</div>`
            : '';
        return `
        <div class="sc-card">
            ${img}
            <div class="sc-card-body">
                <div class="sc-card-title">${esc(r.product_name)}</div>
                <div class="sc-card-biz">${esc(r.business_name)}${r.city ? ' · ' + esc(r.city) : ''}</div>
                ${reason}
                <div class="sc-card-price-row">
                    <span class="sc-card-price">₪${r.group_price_ils.toLocaleString()}</span>
                    <span class="sc-card-orig">₪${r.price_ils.toLocaleString()}</span>
                    <span class="sc-card-badge">-${r.discount_percent}%</span>
                </div>
                <div class="sc-card-fill">
                    <div class="sc-card-fill-bar">
                        <div class="sc-card-fill-bar-inner" style="width:${r.fill_percent}%;background:${fillColor};"></div>
                    </div>
                    <div class="sc-card-fill-meta">
                        <span>${r.current_participants}/${r.target_participants} joined</span>
                        <span>${r.countdown}</span>
                    </div>
                </div>
                <a href="${SC_APP_URL}/pages/group.php?id=${r.group_id}" class="sc-card-btn">Join Group →</a>
            </div>
        </div>`;
    }).join('');
    return `<div class="sc-cards">${items}</div>`;
}

/* ── Suggested products (no open group → start one) ────── */
function renderSuggestedProducts(products) {
    if (!products || !products.length) return '';
    const items = products.map(p => {
        const disc = parseInt(p.discount_percent) || 0;
        return `
        <div class="sc-card">
            ${p.product_image
                ? `<img class="sc-card-img" src="${imgSrc(p.product_image)}" alt="${esc(p.product_name)}" loading="lazy">`
                : `<div class="sc-card-img-placeholder">${catEmoji(p.category)}</div>`}
            <div class="sc-card-body">
                <div class="sc-card-title">${esc(p.product_name)}</div>
                <div class="sc-card-biz">${esc(p.business_name)}</div>
                <div class="sc-card-price-row">
                    <span class="sc-card-price">₪${parseFloat(p.group_price_ils).toLocaleString()}</span>
                    <span class="sc-card-orig">₪${parseFloat(p.price_ils).toLocaleString()}</span>
                    ${disc > 0 ? `<span class="sc-card-badge">-${disc}%</span>` : ''}
                </div>
                <a href="${SC_APP_URL}/pages/product.php?id=${p.id}" class="sc-card-btn sc-card-btn-start">
                    ✦ Start a Group
                </a>
            </div>
        </div>`;
    }).join('');
    return `<div class="sc-cards">${items}</div>`;
}

/* ── Natural language response builder ─────────────────── */
function buildReply(meta, results, query) {
    const kws  = (meta && meta.query_keywords) ? meta.query_keywords : [];
    const cat  = meta ? meta.auto_category : null;
    const qMode = meta ? meta.query_mode : false;
    const qDisp = esc(query.trim());

    /* ── Profile-based (no query) ── */
    if (!qMode) {
        if (!results.length) {
            return {
                text: `I checked all open groups based on your profile preferences, but nothing matches your interests right now. 😕<br><br>Try searching for a specific product above, or update your profile with more categories!`,
                actions: [
                    {label:'🛍️ Browse all groups', href: SC_APP_URL + '/pages/browse.php'},
                    {label:'⚙️ Update my preferences', href: SC_APP_URL + '/pages/profile.php'},
                ]
            };
        }
        const n = results.length;
        return {
            text: `Based on your saved preferences, I found <strong>${n} open group${n>1?'s':''}</strong> worth joining. Here are my top picks — the more people join, the bigger the discount! 🎉`,
        };
    }

    /* ── No open groups for query ── */
    if (!results.length) {
        const suggested = (meta && meta.suggested_products) ? meta.suggested_products : [];

        // Case A: product exists in catalog but no open group → offer to start one
        if (suggested.length > 0) {
            return {
                text: `There's no open group for <strong>"${qDisp}"</strong> right now. 😕<br><br>But I found <strong>${suggested.length} matching product${suggested.length > 1 ? 's' : ''}</strong> in our catalog — you could start a new group and others will join! 💡`,
                suggestedProducts: suggested,
            };
        }

        // Case B: nothing anywhere in the platform
        return {
            text: `I searched everywhere for <strong>"${qDisp}"</strong> but couldn't find any matching groups or products in our catalog. 😕<br><br>Try a different search term or browse all available deals!`,
            actions: [
                {label:'🛍️ Browse all groups', href: SC_APP_URL + '/pages/browse.php'},
            ]
        };
    }

    const n   = results.length;
    const top = results[0];
    const has = (...ws) => ws.some(w => kws.includes(w));

    /* ── Brand/category-aware intro ── */
    let intro = '';
    if (has('jbl','speaker','speakers','bluetooth','subwoofer'))
        intro = `Found <strong>${n} audio deal${n>1?'s':''}</strong> for you! 🎵`;
    else if (has('airpods','iphone','apple','ipad','macbook'))
        intro = `Found <strong>${n} Apple group${n>1?'s':''}</strong>! 🍎`;
    else if (has('samsung','galaxy'))
        intro = `Here are <strong>${n} Samsung deal${n>1?'s':''}</strong>! 📱`;
    else if (has('laptop','computer','pc','notebook'))
        intro = `Found <strong>${n} computer deal${n>1?'s':''}</strong>! 💻`;
    else if (has('headphone','headphones','earphone','earphones','earbuds','bose','sony'))
        intro = `Found <strong>${n} headphone deal${n>1?'s':''}</strong>! 🎧`;
    else if (cat === 'electronics')
        intro = `Found <strong>${n} electronics group${n>1?'s':''}</strong> matching your search! 💡`;
    else if (cat === 'fashion')
        intro = `Found <strong>${n} fashion deal${n>1?'s':''}</strong>! 👗`;
    else if (cat === 'home')
        intro = `Found <strong>${n} home deal${n>1?'s':''}</strong>! 🏠`;
    else if (cat === 'food')
        intro = `Found <strong>${n} food deal${n>1?'s':''}</strong>! 🍽️`;
    else if (cat === 'sports')
        intro = `Found <strong>${n} sports deal${n>1?'s':''}</strong>! 🏃`;
    else
        intro = `I found <strong>${n} open group${n>1?'s':''}</strong> matching <strong>"${qDisp}"</strong>! 🎉`;

    /* ── Best deal callout ── */
    let detail = '';
    if (top.discount_percent >= 30)
        detail = ` Best deal: <strong>${top.discount_percent}% off</strong> on ${esc(top.product_name)} — saves you ₪${Math.round(top.price_ils - top.group_price_ils).toLocaleString()}!`;
    else if (top.discount_percent >= 15)
        detail = ` Top pick: <strong>${esc(top.product_name)}</strong> at <strong>${top.discount_percent}% off</strong>.`;

    /* ── Urgency callout ── */
    const urgent = results.filter(r => r.days_left <= 3);
    let urgency = '';
    if (urgent.length)
        urgency = `<br><br>⚡ <strong>${urgent.length} group${urgent.length>1?' are':' is'} closing in under 3 days</strong> — act fast!`;

    /* ── Always offer a browse link in query mode ── */
    const actions = [
        {label: '🔍 Browse all deals', href: SC_APP_URL + '/pages/browse.php'},
    ];

    return { text: intro + detail + urgency, actions };
}

/* ── Send a message ─────────────────────────────────────── */
let isBusy = false;

async function send(text) {
    text = text.trim();
    if (!text || isBusy) return;
    isBusy = true;

    /* Hide prompts after first send */
    const prompts = document.getElementById('sc-prompts');
    if (prompts) { prompts.style.opacity = '0'; setTimeout(() => { prompts.style.display = 'none'; }, 250); }

    /* User bubble */
    addMsg('user', text);

    /* Clear input */
    const inp = document.getElementById('sc-input');
    const btn = document.getElementById('sc-send');
    inp.value = '';
    inp.style.height = 'auto';
    btn.disabled = true;

    /* Typing */
    await delay(280);
    showTyping();

    try {
        const p = new URLSearchParams({ user_id: SC_USER_ID, limit: text ? 3 : 10 });
        if (text) p.append('query', text);
        const res  = await fetch(`${SC_APP_URL}/api/agent.php?${p}`);
        const data = await res.json();

        const meta    = Array.isArray(data) ? null  : (data.meta    || null);
        const results = Array.isArray(data) ? data  : (data.results || []);

        await delay(550);
        hideTyping();

        const reply          = buildReply(meta, results, text);
        const cards          = renderCards(results);
        const suggestedCards = reply.suggestedProducts
                                 ? renderSuggestedProducts(reply.suggestedProducts)
                                 : '';

        let html = reply.text;
        if (cards)          html += cards;
        if (suggestedCards) html += suggestedCards;

        if (reply.actions && reply.actions.length) {
            const chips = reply.actions.map(a =>
                `<a href="${a.href}" class="sc-action">${a.label}</a>`
            ).join('');
            html += `<div class="sc-actions">${chips}</div>`;
        }

        addMsg('ai', html);

    } catch (e) {
        hideTyping();
        addMsg('ai', 'Oops, something went wrong connecting to the server. Please try again! 🙁');
    } finally {
        btn.disabled = false;
        isBusy = false;
        inp.focus();
    }
}

function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

/* ── Input bar events ───────────────────────────────────── */
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

/* ── Welcome message on load ────────────────────────────── */
(function init() {
    const greet = SC_HAS_PREF
        ? `Hi **${SC_NAME}**! 👋 I'm your **SmartCart AI Assistant**.\n\nI can find open group deals based on what you're looking for — or I can suggest deals based on your saved preferences.\n\nWhat are you shopping for today?`
        : `Hi **${SC_NAME}**! 👋 I'm your **SmartCart AI Assistant**.\n\nI help you find group purchases so you can save money together. Just tell me what you're looking for — like *"JBL speaker"* or *"iPhone deals"* — and I'll search all open groups for you!\n\nTap a suggestion below or type your own request to get started.`;

    addMsg('ai', md(greet));

    /* Auto-load profile-based picks for users with preferences */
    if (SC_HAS_PREF) {
        delay(1300).then(async () => {
            showTyping();
            try {
                const res  = await fetch(`${SC_APP_URL}/api/agent.php?user_id=${SC_USER_ID}&limit=10`);
                const data = await res.json();
                const meta    = Array.isArray(data) ? null  : (data.meta    || null);
                const results = Array.isArray(data) ? data  : (data.results || []);
                await delay(700);
                hideTyping();
                const reply = buildReply(meta, results, '');
                const cards = renderCards(results);
                let html = reply.text + (cards || '');
                if (reply.actions && reply.actions.length) {
                    const chips = reply.actions.map(a =>
                        `<a href="${a.href}" class="sc-action">${a.label}</a>`
                    ).join('');
                    html += `<div class="sc-actions">${chips}</div>`;
                }
                addMsg('ai', html);
            } catch(e) {
                hideTyping();
            }
        });
    }
})();
</script>
