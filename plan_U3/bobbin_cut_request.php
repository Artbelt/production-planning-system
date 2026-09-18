<?php
/**
 * Заявка на бобинорезку (ручной раскрой) — отдельная страница U3.
 */
require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';
require_once __DIR__ . '/settings.php';

initAuthSystem();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
$users = $db->select('SELECT * FROM auth_users WHERE id = ?', [$session['user_id']]);
$user = is_array($users) ? ($users[0] ?? null) : null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);
if (!is_array($userDepartments)) {
    $userDepartments = [];
}

$canAccess = false;
foreach ($userDepartments as $dept) {
    if (($dept['department_code'] ?? '') === 'U3'
        && in_array($dept['role_name'] ?? '', ['assembler', 'corr_operator', 'supervisor', 'director'], true)
    ) {
        $canAccess = true;
        break;
    }
}
if (!$canAccess) {
    header('Location: main.php');
    exit;
}

$userName = htmlspecialchars($user['full_name'] ?? $session['full_name'] ?? 'Пользователь', ENT_QUOTES, 'UTF-8');
$baleFormat = (int)($width_of_main_roll ?? 1200);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Заявка на бобинорезку — U3</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #2457e6;
            --accent-ink: #ffffff;
            --danger: #dc2626;
            --radius: 12px;
            --shadow: 0 2px 12px rgba(2,8,20,.06);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 14px/1.45 "Segoe UI", Roboto, Arial, sans-serif;
        }
        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 16px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .topbar h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        .topbar .user {
            font-size: 12px;
            color: var(--muted);
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 14px 16px;
            margin-bottom: 12px;
        }
        .panel h4 {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
        }
        .bc-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: end;
            margin-bottom: 8px;
        }
        .bc-row label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            font-weight: 500;
            color: var(--muted);
        }
        .bc-row input {
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            min-width: 0;
        }
        button, input[type="submit"] {
            appearance: none;
            border: 1px solid transparent;
            cursor: pointer;
            background: var(--accent);
            color: var(--accent-ink);
            padding: 8px 14px;
            border-radius: 9px;
            font-weight: 600;
        }
        button:hover { background: #1e47c5; }
        button:disabled {
            background: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
        }
        .bc-meta {
            font-size: 12px;
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin: 8px 0;
        }
        .bc-meta b { color: var(--ink); }
        .bc-layout-wrap {
            width: 100%;
            min-width: 33vw;
            max-width: 100%;
            margin: 0 auto 10px;
        }
        .bc-layout-scale {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 4px;
            padding: 0 2px;
        }
        .bc-layout {
            display: flex;
            width: 100%;
            height: 140px;
            border: 2px solid #1f2937;
            background: #fff;
            overflow: hidden;
            position: relative;
        }
        .bc-layout.is-empty::after {
            content: '<?= (int)$baleFormat ?> мм — пустая бухта';
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 13px;
            pointer-events: none;
        }
        .bc-hatch {
            flex: 0 0 auto;
            height: 100%;
            min-width: 0;
            background-color: #f3f4f6;
            background-image: repeating-linear-gradient(
                -45deg,
                #d1d5db 0,
                #d1d5db 1px,
                transparent 1px,
                transparent 7px
            );
        }
        .bc-strip {
            flex: 0 0 auto;
            height: 100%;
            min-width: 0;
            background: #fff;
            border-right: 1px solid #1f2937;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            padding: 4px 2px 6px;
            cursor: pointer;
        }
        .bc-layout > .bc-strip:first-child,
        .bc-hatch + .bc-strip {
            border-left: 1px solid #1f2937;
        }
        .bc-strip:hover { background: #eff6ff; }
        .bc-strip-label {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            font-size: 11px;
            font-weight: 600;
            color: #1f2937;
            line-height: 1.1;
            max-height: 100%;
            overflow: hidden;
        }
        .bc-bale-mini .bc-strip {
            padding: 2px 0;
            cursor: default;
            background: #e0e7ff;
            justify-content: center;
        }
        .bc-bale-mini .bc-strip:hover { background: #e0e7ff; }
        .bc-bale-mini .bc-strip-label {
            display: block;
            writing-mode: horizontal-tb;
            transform: none;
            font-size: 11px;
            font-weight: 700;
            color: #1e3a8a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: clip;
        }
        .bc-width-btns {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            align-items: end;
            margin: 10px 0 4px;
        }
        .bc-width-btns label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            font-weight: 500;
            color: var(--muted);
        }
        .bc-width-btns input[type="number"] {
            width: 120px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
        }
        .bc-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin: 10px 0 4px;
        }
        .bc-actions .btn-muted { background: #6b7280; }
        .bc-saved-row {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 420px;
            overflow-y: auto;
        }
        .bc-bale-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
            font-size: 12px;
            width: 100%;
        }
        .bc-bale-card .bc-bale-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            margin-bottom: 6px;
            gap: 8px;
        }
        .bc-bale-mini {
            display: flex;
            height: 48px;
            border: 1px solid #1f2937;
            overflow: hidden;
            margin-top: 4px;
            width: 100%;
        }
        .bc-btn-sm {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
        }
        .bc-btn-danger { background: var(--danger); }
        .bc-msg {
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 10px;
            display: none;
        }
        .bc-msg.ok { display: block; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .bc-msg.err { display: block; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .bc-requests {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 8px;
        }
        .bc-requests th,
        .bc-requests td {
            padding: 6px 8px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }
        .bc-requests th { background: #f8fafc; font-weight: 600; }
        .bc-status-pending { color: #d97706; font-weight: 500; }
        .bc-status-done { color: #059669; font-weight: 500; }
        .bc-status-cancel { color: #dc2626; font-weight: 500; }
        .footer-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin: 12px 0;
            flex-wrap: wrap;
        }
        a.back-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        a.back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1>Заявка на бобинорезку</h1>
            <div class="user"><?= $userName ?> · U3 · бухта <?= (int)$baleFormat ?> мм</div>
        </div>
        <a class="back-link" href="main.php">← На главную U3</a>
    </div>

    <div id="bobbinCutMsg" class="bc-msg"></div>

    <div class="panel">
        <div class="bc-row">
            <label>Дата порезки
                <input type="date" id="bobbinWorkDate">
            </label>
            <label style="flex: 1; min-width: 180px; max-width: 280px;">Название бухты
                <input type="text" id="bobbinBaleName" maxlength="40" placeholder="напр. Феникс">
                <span style="display:block;margin-top:4px;font-size:12px;color:var(--muted);">К названию добавится суффикс, напр. Феникс-A3F2</span>
            </label>
            <label style="flex: 0 0 140px;">Длина, м
                <input type="number" id="bobbinBaleLength" min="0.1" step="0.1" placeholder="напр. 500">
            </label>
        </div>

        <div class="bc-layout-wrap">
            <div class="bc-layout-scale">
                <span>0</span>
                <span>Раскрой бухты · <?= (int)$baleFormat ?> мм</span>
                <span><?= (int)$baleFormat ?></span>
            </div>
            <div id="bobbinLayout" class="bc-layout is-empty" title="Клик по полосе — удалить"></div>
            <div class="bc-meta">
                <span>Занято: <b id="bobbinUsed">0</b> мм</span>
                <span>Остаток: <b id="bobbinRest"><?= (int)$baleFormat ?></b> мм</span>
                <span>Полос: <b id="bobbinStripCount">0</b></span>
            </div>
            <div class="bc-width-btns">
                <label>Ширина полосы, мм
                    <input type="number" id="bobbinManualWidth" min="1" max="<?= (int)$baleFormat ?>" step="0.1" placeholder="напр. 125">
                </label>
                <button type="button" onclick="bobbinAddManualWidth()">Добавить полосу</button>
            </div>
            <div class="bc-width-btns" style="margin-top: 4px;">
                <label style="flex: 1; min-width: 200px;">Комментарий
                    <input type="text" id="bobbinComment" maxlength="500" placeholder="Необязательно" style="width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px;">
                </label>
            </div>
            <div class="bc-actions">
                <button type="button" id="bobbinSaveBaleBtn" onclick="bobbinSaveBale()" disabled>Сохранить бухту</button>
                <button type="button" class="btn-muted" onclick="bobbinClearCurrent()">Очистить</button>
            </div>
        </div>
    </div>

    <div class="panel">
        <h4>Собранные бухты</h4>
        <div id="bobbinSavedList" class="bc-saved-row">
            <div style="color: var(--muted); font-size: 13px;">Пока нет</div>
        </div>
    </div>

    <div class="footer-actions">
        <button type="button" id="bobbinSubmitBtn" onclick="bobbinSubmitRequest()" disabled>Отправить заявку</button>
    </div>

    <div class="panel">
        <h4>Ваши последние заявки</h4>
        <div id="bobbinRequestsWrap">
            <div style="color: var(--muted); font-size: 13px;">Загрузка…</div>
        </div>
    </div>
</div>

<script>
const BOBBIN_FORMAT_MM = <?= (int)$baleFormat ?>;
const BOBBIN_API = 'bobbin_cut_request_api.php';
let bobbinCurrentStrips = [];
let bobbinSavedBales = [];

function bobbinFmt(w) {
    return String(Number(w));
}

function bobbinUsedSum() {
    return bobbinCurrentStrips.reduce((a, w) => a + w, 0);
}

function bobbinShowMsg(text, ok) {
    const el = document.getElementById('bobbinCutMsg');
    if (!el) return;
    el.className = 'bc-msg ' + (ok ? 'ok' : 'err');
    el.textContent = text || '';
    if (!text) {
        el.className = 'bc-msg';
        el.style.display = 'none';
    }
}

function bobbinLayoutHtml(widths, opts) {
    opts = opts || {};
    const removable = !!opts.removable;
    const used = widths.reduce((a, w) => a + w, 0);
    const rest = Math.max(0, Math.round((BOBBIN_FORMAT_MM - used) * 10) / 10);
    if (widths.length === 0) return '';

    let html = '';
    const leftHatch = Math.round((rest / 2) * 10) / 10;
    const rightHatch = Math.round((rest - leftHatch) * 10) / 10;

    if (leftHatch > 0.01) {
        html += `<div class="bc-hatch" style="flex:${leftHatch} 0 0" title="Остаток ${bobbinFmt(leftHatch)} мм"></div>`;
    }
    widths.forEach((w, i) => {
        const click = removable ? ` onclick="bobbinRemoveStrip(${i})"` : '';
        const title = removable
            ? `title="${bobbinFmt(w)} мм — клик удалить"`
            : `title="${bobbinFmt(w)} мм"`;
        html += `<div class="bc-strip" style="flex:${w} 0 0"${click} ${title}>` +
            `<span class="bc-strip-label">${bobbinFmt(w)}</span></div>`;
    });
    if (rightHatch > 0.01) {
        html += `<div class="bc-hatch" style="flex:${rightHatch} 0 0" title="Остаток ${bobbinFmt(rightHatch)} мм"></div>`;
    }
    return html;
}

function bobbinRenderCurrent() {
    const used = Math.round(bobbinUsedSum() * 10) / 10;
    const rest = Math.round((BOBBIN_FORMAT_MM - used) * 10) / 10;
    document.getElementById('bobbinRest').textContent = bobbinFmt(rest);
    document.getElementById('bobbinRest').style.color = '';
    document.getElementById('bobbinUsed').textContent = bobbinFmt(used);
    document.getElementById('bobbinStripCount').textContent = String(bobbinCurrentStrips.length);

    const layout = document.getElementById('bobbinLayout');
    if (bobbinCurrentStrips.length === 0) {
        layout.className = 'bc-layout is-empty';
        layout.innerHTML = '';
    } else {
        layout.className = 'bc-layout';
        layout.innerHTML = bobbinLayoutHtml(bobbinCurrentStrips, { removable: true });
    }

    document.getElementById('bobbinSaveBaleBtn').disabled = bobbinCurrentStrips.length === 0;
    document.getElementById('bobbinSubmitBtn').disabled = bobbinSavedBales.length === 0;
}

function bobbinRenderSaved() {
    const wrap = document.getElementById('bobbinSavedList');
    if (bobbinSavedBales.length === 0) {
        wrap.innerHTML = '<div style="color: var(--muted); font-size: 13px;">Пока нет</div>';
        document.getElementById('bobbinSubmitBtn').disabled = true;
        return;
    }
    wrap.innerHTML = bobbinSavedBales.map((bale, i) => {
        const sum = Math.round(bale.widths.reduce((a, w) => a + w, 0) * 10) / 10;
        const formats = bale.widths.map(w => bobbinFmt(w)).join(' + ');
        const title = bale.name || ('Бухта ' + (i + 1));
        const lenLabel = (bale.length_m != null && bale.length_m > 0)
            ? (' · ' + bobbinFmt(bale.length_m) + ' м')
            : '';
        return `<div class="bc-bale-card">
            <div class="bc-bale-head">
                <span><b>${title}</b> · ${bale.widths.length} пол. · ${bobbinFmt(sum)} / ${BOBBIN_FORMAT_MM} мм${lenLabel}</span>
                <button type="button" class="bc-btn-sm bc-btn-danger" onclick="bobbinRemoveBale(${i})">×</button>
            </div>
            <div class="bc-bale-mini">${bobbinLayoutHtml(bale.widths, { removable: false })}</div>
            <div style="margin-top:6px;color:var(--muted);">${formats} мм</div>
        </div>`;
    }).join('');
    document.getElementById('bobbinSubmitBtn').disabled = false;
}

function bobbinAddManualWidth() {
    const input = document.getElementById('bobbinManualWidth');
    let width = parseFloat(String(input.value).replace(',', '.'));
    if (!Number.isFinite(width) || width <= 0) {
        bobbinShowMsg('Укажите ширину полосы', false);
        input.focus();
        return;
    }
    width = Math.round(width * 10) / 10;
    const before = bobbinCurrentStrips.length;
    bobbinAddWidth(width);
    if (bobbinCurrentStrips.length > before) {
        input.value = '';
        input.focus();
    }
}

function bobbinAddWidth(width) {
    width = parseFloat(width);
    if (!Number.isFinite(width) || width <= 0) return;
    const used = bobbinUsedSum();
    if (used + width > BOBBIN_FORMAT_MM + 0.01) {
        const rest = Math.round((BOBBIN_FORMAT_MM - used) * 10) / 10;
        bobbinShowMsg(`Не влезает: остаток ${bobbinFmt(rest)} мм, полоса ${bobbinFmt(width)} мм`, false);
        return;
    }
    bobbinCurrentStrips.push(width);
    bobbinShowMsg('', true);
    bobbinRenderCurrent();
}

function bobbinRemoveStrip(idx) {
    bobbinCurrentStrips.splice(idx, 1);
    bobbinRenderCurrent();
}

function bobbinClearCurrent() {
    bobbinCurrentStrips = [];
    bobbinRenderCurrent();
    bobbinShowMsg('', true);
}

function bobbinMakeUniqueSuffix() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let s = '';
    for (let i = 0; i < 4; i++) {
        s += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return s;
}

function bobbinMakeBaleName(prefix) {
    const clean = String(prefix || '').trim().replace(/\s+/g, ' ');
    if (!clean) return '';
    const base = clean.slice(0, 40);
    let full = base + '-' + bobbinMakeUniqueSuffix();
    const used = new Set(bobbinSavedBales.map(b => b.name));
    let guard = 0;
    while (used.has(full) && guard < 20) {
        full = base + '-' + bobbinMakeUniqueSuffix();
        guard++;
    }
    return full;
}

function bobbinSaveBale() {
    if (bobbinCurrentStrips.length === 0) return;
    const sum = bobbinUsedSum();
    if (sum > BOBBIN_FORMAT_MM + 0.01) {
        bobbinShowMsg('Сумма ширин превышает ' + BOBBIN_FORMAT_MM + ' мм', false);
        return;
    }
    const nameInput = document.getElementById('bobbinBaleName');
    const prefix = (nameInput && nameInput.value) ? nameInput.value.trim() : '';
    if (!prefix) {
        bobbinShowMsg('Укажите название бухты (например, Феникс)', false);
        if (nameInput) nameInput.focus();
        return;
    }
    const lenInput = document.getElementById('bobbinBaleLength');
    let lengthM = parseFloat(String(lenInput && lenInput.value ? lenInput.value : '').replace(',', '.'));
    if (!Number.isFinite(lengthM) || lengthM <= 0) {
        bobbinShowMsg('Укажите длину порезки в метрах', false);
        if (lenInput) lenInput.focus();
        return;
    }
    lengthM = Math.round(lengthM * 10) / 10;
    if (lengthM > 99999) {
        bobbinShowMsg('Слишком большая длина', false);
        if (lenInput) lenInput.focus();
        return;
    }
    const name = bobbinMakeBaleName(prefix);
    bobbinSavedBales.push({
        name: name,
        length_m: lengthM,
        widths: bobbinCurrentStrips.slice()
    });
    bobbinCurrentStrips = [];
    if (lenInput) lenInput.value = '';
    bobbinRenderCurrent();
    bobbinRenderSaved();
    bobbinShowMsg('Бухта сохранена: ' + name + ' · ' + bobbinFmt(lengthM) + ' м', true);
}

function bobbinRemoveBale(idx) {
    bobbinSavedBales.splice(idx, 1);
    bobbinRenderSaved();
}

async function bobbinParseJson(res) {
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        const snippet = (text || '').replace(/\s+/g, ' ').slice(0, 180);
        throw new Error('Ответ сервера не JSON (HTTP ' + res.status + '): ' + snippet);
    }
}

async function bobbinLoadRequests() {
    const wrap = document.getElementById('bobbinRequestsWrap');
    wrap.innerHTML = '<div style="color: var(--muted); font-size: 13px;">Загрузка…</div>';
    try {
        const res = await fetch(BOBBIN_API + '?action=list', { credentials: 'same-origin' });
        const data = await bobbinParseJson(res);
        if (!data.ok) {
            wrap.innerHTML = '<div style="color: var(--danger); font-size: 13px;">' +
                (data.error || 'Ошибка загрузки') + '</div>';
            return;
        }
        const rows = data.requests || [];
        if (rows.length === 0) {
            wrap.innerHTML = '<div style="color: var(--muted); font-size: 13px;">У вас пока нет заявок</div>';
            return;
        }
        let html = `<table class="bc-requests"><thead><tr>
            <th>№ задания</th><th>Бухт</th><th>Дата</th><th>Статус</th><th></th>
        </tr></thead><tbody>`;
        rows.forEach(r => {
            let stClass = 'bc-status-pending';
            let stText = 'В работе';
            if (Number(r.is_cancelled)) {
                stClass = 'bc-status-cancel';
                stText = 'Отменено';
            } else if (Number(r.is_completed)) {
                stClass = 'bc-status-done';
                stText = 'Выполнено';
            }
            const canCancel = !Number(r.is_cancelled) && !Number(r.is_completed);
            const dateStr = (r.desired_delivery_time || r.created_at || '').toString().slice(0, 10);
            html += `<tr>
                <td>${r.order_number || '—'}</td>
                <td>${r.quantity || 0}</td>
                <td>${dateStr || '—'}</td>
                <td><span class="${stClass}">${stText}</span></td>
                <td>${canCancel
                    ? `<button type="button" class="bc-btn-sm bc-btn-danger" onclick="bobbinCancelRequest(${r.id})">Отменить</button>`
                    : '—'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        wrap.innerHTML = html;
    } catch (e) {
        wrap.innerHTML = '<div style="color: var(--danger); font-size: 13px;">Ошибка: ' + e.message + '</div>';
    }
}

async function bobbinSubmitRequest() {
    if (bobbinSavedBales.length === 0) {
        bobbinShowMsg('Сохраните хотя бы одну бухту', false);
        return;
    }
    const workDate = document.getElementById('bobbinWorkDate').value || '';
    const comment = document.getElementById('bobbinComment').value || '';
    const bales = bobbinSavedBales.map(b => {
        const groups = {};
        b.widths.forEach(w => {
            const key = bobbinFmt(w);
            groups[key] = (groups[key] || 0) + 1;
        });
        return {
            name: b.name || '',
            length_m: b.length_m || 0,
            strips: Object.keys(groups).map(k => ({
                width: parseFloat(k),
                qty: groups[k]
            }))
        };
    });

    const btn = document.getElementById('bobbinSubmitBtn');
    btn.disabled = true;
    try {
        const res = await fetch(BOBBIN_API + '?action=submit', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'submit', work_date: workDate, comment, bales })
        });
        const data = await bobbinParseJson(res);
        if (!data.ok) {
            bobbinShowMsg(data.error || 'Ошибка отправки', false);
            btn.disabled = false;
            return;
        }
        bobbinShowMsg(data.message || 'Заявка отправлена', true);
        bobbinSavedBales = [];
        bobbinCurrentStrips = [];
        bobbinRenderCurrent();
        bobbinRenderSaved();
        bobbinLoadRequests();
    } catch (e) {
        bobbinShowMsg('Ошибка: ' + e.message, false);
        btn.disabled = false;
    }
}

async function bobbinCancelRequest(requestId) {
    if (!confirm('Отменить заявку и убрать невыполненные бухты из плана бобинорезки?')) {
        return;
    }
    try {
        const res = await fetch(BOBBIN_API + '?action=cancel', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel', request_id: requestId })
        });
        const data = await bobbinParseJson(res);
        if (!data.ok) {
            bobbinShowMsg(data.error || 'Ошибка отмены', false);
            return;
        }
        bobbinShowMsg(data.message || 'Заявка отменена', true);
        bobbinLoadRequests();
    } catch (e) {
        bobbinShowMsg('Ошибка: ' + e.message, false);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('bobbinWorkDate');
    const d = new Date();
    dateInput.value = d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
    const widthInput = document.getElementById('bobbinManualWidth');
    if (widthInput) {
        widthInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                bobbinAddManualWidth();
            }
        });
    }
    bobbinRenderCurrent();
    bobbinRenderSaved();
    bobbinLoadRequests();
});
</script>
</body>
</html>
