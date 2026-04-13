<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/auth_check.php';

$page_title = $t['agent_title'];

require_login();
$user_id = current_user_id();
$user    = get_current_user_data();
$has_prefs = !empty($user['preferred_categories']);

include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top:32px;padding-bottom:60px;">
    <div class="page-header">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--purple)"><path d="M12 3l1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3z"/><path d="M5 3l.5 2 2 .5-2 .5-.5 2-.5-2-2-.5 2-.5.5-2z"/><path d="M19 16l.5 2 2 .5-2 .5-.5 2-.5-2-2-.5 2-.5.5-2z"/></svg>
            <?= $t['agent_title'] ?>
        </h1>
        <p><?= $t['agent_subtitle'] ?></p>
    </div>

    <?php if (!$has_prefs): ?>
    <div style="background:var(--purple-50);border-radius:var(--radius);padding:24px;text-align:center;margin-bottom:24px;">
        <div style="display:flex;justify-content:center;margin-bottom:12px;">
            <div style="width:52px;height:52px;background:var(--purple-50);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--purple)"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
            </div>
        </div>
        <h3 style="margin-bottom:8px;"><?= $t['agent_no_prefs'] ?></h3>
        <a href="<?= APP_URL ?>/pages/profile.php" class="btn btn-primary mt-16"><?= $t['agent_go_profile'] ?></a>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div style="background:white;border-radius:var(--radius);padding:16px;box-shadow:var(--shadow);margin-bottom:24px;display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label class="form-label"><?= $t['agent_filter_dist'] ?></label>
            <select id="dist-filter" class="form-control" style="min-width:140px;">
                <option value=""><?= $t['agent_dist_any'] ?></option>
                <option value="10"><?= $t['agent_dist_10'] ?></option>
                <option value="30" selected><?= $t['agent_dist_30'] ?></option>
                <option value="50"><?= $t['agent_dist_50'] ?></option>
            </select>
        </div>
        <div>
            <label class="form-label"><?= $t['filter_category'] ?></label>
            <select id="cat-filter" class="form-control" style="min-width:160px;">
                <option value=""><?= $t['filter_all'] ?></option>
                <?php
                $categories = ['electronics','home','fashion','food','sports','beauty','toys','books','automotive','other'];
                foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= $t['cat_'.$cat] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button id="run-agent" class="btn btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3z"/></svg>
            Find Groups For Me
        </button>
    </div>

    <!-- How scoring works -->
    <details style="background:white;border-radius:var(--radius);border:1px solid var(--border);padding:16px 20px;box-shadow:var(--shadow);margin-bottom:24px;font-size:13px;color:var(--gray-700);">
        <summary style="cursor:pointer;font-weight:600;color:var(--purple);display:flex;align-items:center;gap:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            How does the Smart Agent score groups?
        </summary>
        <div style="margin-top:14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
            <div class="scoring-card">
                <div class="scoring-card-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                    Category Match
                </div>
                <p>+30 pts if the product matches your preferred categories</p>
            </div>
            <div class="scoring-card">
                <div class="scoring-card-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Location
                </div>
                <p>+25 pts if ≤10km · +15 pts if ≤30km</p>
            </div>
            <div class="scoring-card">
                <div class="scoring-card-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
                    Discount
                </div>
                <p>+20 pts if ≥30% off · +10 pts if ≥15% off</p>
            </div>
            <div class="scoring-card">
                <div class="scoring-card-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Group Fill
                </div>
                <p>+15 pts if ≥50% full · +7 pts if ≥25% full</p>
            </div>
            <div class="scoring-card">
                <div class="scoring-card-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Urgency
                </div>
                <p>+10 pts if ≤3 days left · +5 pts if ≤7 days</p>
            </div>
        </div>
    </details>

    <!-- Results container -->
    <div id="agent-results">
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>
            <h3>Click "Find Groups For Me" to get personalized recommendations</h3>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const AGENT_USER_ID = <?= $user_id ?>;
const APP_URL_JS    = '<?= APP_URL ?>';
const HAS_PREFS     = <?= $has_prefs ? 'true' : 'false' ?>;

function renderAgentResults(results) {
    const container = document.getElementById('agent-results');
    if (!results.length) {
        container.innerHTML = `<div class="empty-state">
            <h3><?= $t['agent_no_results'] ?></h3>
            <a href="<?= APP_URL ?>/pages/profile.php" class="btn btn-outline mt-16">Update Preferences</a>
        </div>`;
        return;
    }
    container.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
        ${results.map((r, i) => `
        <div class="card">
            ${r.product_image ? `<img src="${APP_URL_JS}${r.product_image}" alt="${r.product_name}" style="width:100%;height:180px;object-fit:cover;">` : ''}
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;margin-bottom:12px;">
                    <span class="card-badge badge-joined">#${i+1} <?= $t['agent_rank'] ?></span>
                    <span style="font-size:13px;font-weight:700;color:var(--purple);">${r.score}/100</span>
                </div>
                <div style="height:6px;background:var(--gray-300);border-radius:999px;margin-bottom:12px;overflow:hidden;">
                    <div style="height:100%;width:${r.score}%;background:linear-gradient(90deg,var(--purple),var(--green));border-radius:999px;"></div>
                </div>
                <h3 style="font-size:15px;font-weight:600;margin-bottom:4px;">${r.product_name}</h3>
                <p style="font-size:12px;color:var(--gray-500);margin-bottom:8px;">${r.business_name} · ${r.city || ''}</p>
                <div style="font-size:22px;font-weight:800;color:var(--purple);margin-bottom:10px;">₪${r.group_price_ils.toLocaleString()}</div>
                <div style="display:flex;gap:10px;font-size:12px;color:var(--gray-500);margin-bottom:12px;flex-wrap:wrap;">
                    <span style="display:flex;align-items:center;gap:3px;"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg> ${r.discount_percent}% off</span>
                    <span style="display:flex;align-items:center;gap:3px;"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg> ${r.fill_percent}% full</span>
                    ${r.distance_km !== null ? `<span style="display:flex;align-items:center;gap:3px;"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> ${r.distance_km < 1 ? '<1' : r.distance_km}km</span>` : ''}
                    <span style="display:flex;align-items:center;gap:3px;"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ${r.days_left}d left</span>
                </div>
                <details style="margin-bottom:12px;">
                    <summary style="font-size:12px;cursor:pointer;color:var(--gray-500);"><?= $t['agent_why'] ?></summary>
                    <div style="margin-top:8px;padding:8px;background:var(--gray-100);border-radius:6px;">
                        ${[
                            {label:'<?= $t['agent_cat_match'] ?>', pts: r.score_breakdown.category},
                            {label:'<?= $t['agent_location'] ?>', pts: r.score_breakdown.location},
                            {label:'<?= $t['agent_discount'] ?>', pts: r.score_breakdown.discount},
                            {label:'<?= $t['agent_fill'] ?>',     pts: r.score_breakdown.fill},
                            {label:'<?= $t['agent_urgency'] ?>',  pts: r.score_breakdown.urgency},
                        ].map(it => `
                            <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;">
                                <span style="color:${it.pts > 0 ? 'var(--green)' : 'var(--gray-400)'};">
                                    ${it.pts > 0 ? '✓' : '○'} ${it.label}
                                </span>
                                <span style="font-weight:600;color:${it.pts > 0 ? 'var(--green)' : 'var(--gray-300)'};">+${it.pts}pts</span>
                            </div>`).join('')}
                    </div>
                </details>
                <a href="${APP_URL_JS}/pages/group.php?id=${r.group_id}" class="btn btn-primary btn-full"><?= $t['agent_join'] ?></a>
            </div>
        </div>`).join('')}
    </div>`;
}

document.getElementById('run-agent').addEventListener('click', async function() {
    const dist    = document.getElementById('dist-filter').value;
    const results = document.getElementById('agent-results');
    const btn     = this;
    btn.disabled = true;
    btn.textContent = '<?= $t['loading'] ?>';
    results.innerHTML = '<p class="text-muted text-center" style="padding:40px;"><?= $t['loading'] ?></p>';

    const params = new URLSearchParams({ user_id: AGENT_USER_ID, limit: 10 });
    if (dist) params.append('max_distance_km', dist);

    try {
        const res  = await fetch(`${APP_URL_JS}/api/agent.php?${params}`);
        const data = await res.json();
        renderAgentResults(Array.isArray(data) ? data : []);
    } catch(e) {
        results.innerHTML = '<p class="text-danger text-center" style="padding:40px;">Error loading recommendations. Please try again.</p>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3z"/></svg> Find Groups For Me';
    }
});

// Auto-run on page load if user has prefs
if (HAS_PREFS) {
    document.getElementById('run-agent').click();
}
</script>
