// SmartCart — Main JavaScript
// Handles: API calls, group join/leave, group creation modal, chat, tabs, general UI

const APP_URL = document.querySelector('meta[name="app-url"]')?.content || '';

// ─────────────────────────────────────────────────────────
// API helper — wraps fetch with JSON + CSRF convenience
// ─────────────────────────────────────────────────────────
async function api(endpoint, options = {}) {
    const defaults = {
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
    };
    const url = endpoint.startsWith('http') ? endpoint : APP_URL + endpoint;
    const res = await fetch(url, Object.assign(defaults, options));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
}

// ─────────────────────────────────────────────────────────
// Toast notifications
// ─────────────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `flash flash-${type}`;
    toast.style.cssText = 'position:fixed;top:72px;right:20px;z-index:9999;max-width:400px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15)';
    toast.innerHTML = message + '<button class="flash-close" onclick="this.parentElement.remove()">×</button>';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

// ─────────────────────────────────────────────────────────
// Join group button
// ─────────────────────────────────────────────────────────
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('[data-action="join-group"]');
    if (!btn) return;
    e.preventDefault();

    const groupId = btn.dataset.groupId;
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = 'Joining...';

    try {
        await api(`/api/groups.php?action=join&group_id=${groupId}`, { method: 'POST' });
        showToast('Successfully joined the group!');
        setTimeout(() => location.reload(), 1000);
    } catch (err) {
        showToast(err.message, 'error');
        btn.disabled = false;
        btn.textContent = orig;
    }
});

// ─────────────────────────────────────────────────────────
// Leave group button
// ─────────────────────────────────────────────────────────
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('[data-action="leave-group"]');
    if (!btn) return;
    e.preventDefault();

    if (!confirm('Are you sure you want to leave this group?')) return;

    const groupId = btn.dataset.groupId;
    btn.disabled = true;
    try {
        await api(`/api/groups.php?action=leave&group_id=${groupId}`, { method: 'POST' });
        showToast('You have left the group.');
        setTimeout(() => location.reload(), 1000);
    } catch (err) {
        showToast(err.message, 'error');
        btn.disabled = false;
    }
});

// ─────────────────────────────────────────────────────────
// Start group modal
// ─────────────────────────────────────────────────────────
const startGroupModal = document.getElementById('start-group-modal');
document.addEventListener('click', function(e) {
    if (e.target.closest('[data-action="open-start-group"]')) {
        if (startGroupModal) startGroupModal.classList.add('open');
    }
    if (e.target.closest('[data-action="close-modal"]')) {
        document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
    }
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('open');
    }
});

const startGroupForm = document.getElementById('start-group-form');
if (startGroupForm) {
    startGroupForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const fd  = new FormData(this);
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        try {
            const res = await api('/api/groups.php?action=create', {
                method: 'POST',
                body: JSON.stringify(Object.fromEntries(fd)),
            });
            showToast('Group created!');
            setTimeout(() => location.href = `/pages/group.php?id=${res.group_id}`, 800);
        } catch (err) {
            showToast(err.message, 'error');
            btn.disabled = false;
        }
    });
}

// ─────────────────────────────────────────────────────────
// Tab switching
// ─────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const target = this.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + target)?.classList.add('active');
    });
});

// ─────────────────────────────────────────────────────────
// Group chat form
// ─────────────────────────────────────────────────────────
const chatForm = document.getElementById('chat-form');
if (chatForm) {
    chatForm.addEventListener('submit', function(e) {
        // Allow normal form submit (page refresh approach for simplicity)
        const input = this.querySelector('input[name="message_text"]');
        if (!input || !input.value.trim()) {
            e.preventDefault();
        }
    });
}

// Scroll chat to bottom on load
const chatMessages = document.querySelector('.chat-messages');
if (chatMessages) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// ─────────────────────────────────────────────────────────
// Smart Agent: load recommendations via fetch
// ─────────────────────────────────────────────────────────
async function loadAgentRecommendations(container, userId, limit, maxDistKm) {
    if (!container) return;
    container.innerHTML = '<p class="text-muted text-center" style="padding:20px">Loading...</p>';

    const params = new URLSearchParams({ user_id: userId, limit });
    if (maxDistKm) params.append('max_distance_km', maxDistKm);

    try {
        const results = await api(`/api/agent.php?${params}`);
        if (!results.length) {
            container.innerHTML = '<p class="text-muted text-center" style="padding:20px">No recommendations found. Try expanding your search distance or adding more preferred categories in your profile.</p>';
            return;
        }
        container.innerHTML = results.map((r, i) => renderAgentCard(r, i + 1)).join('');
    } catch (err) {
        container.innerHTML = `<p class="text-danger text-center" style="padding:20px">${err.message}</p>`;
    }
}

function renderAgentCard(r, rank) {
    const fillClass = r.fill_percent >= 80 ? 'high' : r.fill_percent >= 50 ? 'medium' : '';
    return `
    <div class="card" style="margin-bottom:16px">
        <div class="card-body">
            <div class="flex flex-between flex-center mb-8">
                <span class="card-badge badge-joined">#${rank}</span>
                <span class="score-value">${r.score}/100</span>
            </div>
            <div class="score-meter-wrap">
                <div class="score-meter"><div class="score-fill" style="width:${r.score}%"></div></div>
            </div>
            <h3 style="margin:12px 0 4px;font-size:16px">${escHtml(r.product_name)}</h3>
            <p style="color:var(--teal);font-weight:700;font-size:18px">₪${r.group_price_ils.toLocaleString()}</p>
            <div style="display:flex;gap:12px;margin:8px 0;font-size:12px;color:var(--gray-500)">
                <span>🏷️ ${r.discount_percent}% off</span>
                <span>👥 ${r.fill_percent}% full</span>
                <span>📍 ${r.distance_km < 1 ? '<1' : Math.round(r.distance_km)} km</span>
                <span>⏰ ${r.days_left} days left</span>
            </div>
            <details style="margin-top:8px">
                <summary style="font-size:12px;cursor:pointer;color:var(--gray-500)">Why recommended</summary>
                <div style="margin-top:8px;padding:8px;background:var(--gray-100);border-radius:6px">
                    ${breakdownHtml(r.score_breakdown)}
                </div>
            </details>
            <a href="/pages/group.php?id=${r.group_id}" class="btn btn-primary btn-full mt-16">Join Group</a>
        </div>
    </div>`;
}

function breakdownHtml(b) {
    const items = [
        { label: 'Category match', pts: b.category },
        { label: 'Location',       pts: b.location },
        { label: 'Discount',       pts: b.discount },
        { label: 'Group fill',     pts: b.fill },
        { label: 'Urgency',        pts: b.urgency },
    ];
    return items.map(it => `
        <div class="breakdown-item">
            <span>${it.pts > 0 ? '✓' : '○'} ${it.label}</span>
            <span style="color:${it.pts > 0 ? 'var(--success)' : 'var(--gray-300)'};font-weight:600">+${it.pts}pts</span>
        </div>`).join('');
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ─────────────────────────────────────────────────────────
// Delete confirmation (business dashboard)
// ─────────────────────────────────────────────────────────
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('[data-action="delete-product"]');
    if (!btn) return;
    e.preventDefault();
    if (!confirm('Delete this product? This cannot be undone.')) return;
    const id = btn.dataset.id;
    try {
        await api(`/api/products.php?action=delete&id=${id}`, { method: 'DELETE' });
        btn.closest('tr')?.remove();
        showToast('Product deleted.');
    } catch (err) {
        showToast(err.message, 'error');
    }
});

// ─────────────────────────────────────────────────────────
// Expose for use by page-specific scripts
// ─────────────────────────────────────────────────────────
window.SmartCart = { api, showToast, loadAgentRecommendations };
