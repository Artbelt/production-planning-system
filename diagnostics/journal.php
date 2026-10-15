<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = diagnostics_require_user_or_redirect(true);
diagnostics_pdo();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Журнал диагностики ПУ</title>
    <link rel="stylesheet" href="assets/diagnostics.css">
</head>
<body>
<header class="app-header">
    <div>
        <h1>Журнал диагностики ПУ</h1>
        <div class="meta"><?= htmlspecialchars($user['user_name']) ?></div>
    </div>
    <nav class="app-nav">
        <a href="index.php">Оператор</a>
        <a href="editor.php">Редактор</a>
    </nav>
</header>

<main class="container">
    <div id="error" class="error" style="display:none;"></div>

    <div class="card">
        <div class="toolbar">
            <button type="button" class="btn" id="btn-refresh">Обновить</button>
        </div>
        <div id="list-wrap"><p class="muted">Загрузка…</p></div>
    </div>

    <div class="card" id="detail-card" style="display:none;">
        <h2 style="font-size:1.05rem;margin-bottom:8px;">Путь диагностики</h2>
        <p class="muted" id="detail-meta" style="margin-bottom:12px;"></p>
        <ul class="steps-path" id="detail-steps"></ul>
        <div class="cause-box" id="detail-result" style="display:none;margin-top:12px;">
            <h2 id="detail-result-body"></h2>
            <div class="action" id="detail-result-action"></div>
        </div>
    </div>
</main>

<script>
(function () {
    const elError = document.getElementById('error');
    const listWrap = document.getElementById('list-wrap');
    const detailCard = document.getElementById('detail-card');

    function showError(msg) {
        elError.style.display = msg ? 'block' : 'none';
        elError.textContent = msg || '';
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function statusLabel(s) {
        const map = {
            completed: 'завершена',
            in_progress: 'в процессе',
            abandoned: 'отменена',
        };
        return map[s] || s;
    }

    async function api(action, payload) {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action }, payload || {})),
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({ success: false, message: 'Ошибка ответа сервера' }));
        if (!data.success) throw new Error(data.message || 'Ошибка');
        return data;
    }

    async function loadList() {
        showError('');
        try {
            const data = await api('list_sessions', { limit: 100 });
            const rows = data.sessions || [];
            if (!rows.length) {
                listWrap.innerHTML = '<p class="muted">Сессий пока нет.</p>';
                return;
            }
            let html = '<table class="data"><thead><tr>' +
                '<th>Когда</th><th>Оператор</th><th>Симптом</th><th>Статус</th><th>Итог</th><th></th>' +
                '</tr></thead><tbody>';
            rows.forEach(r => {
                html += '<tr>' +
                    '<td>' + escapeHtml(r.started_at) + '</td>' +
                    '<td>' + escapeHtml(r.user_name) + '</td>' +
                    '<td>' + escapeHtml(r.tree_title) + '</td>' +
                    '<td><span class="status-pill status-' + escapeHtml(r.status) + '">' +
                    escapeHtml(statusLabel(r.status)) + '</span></td>' +
                    '<td>' + escapeHtml((r.result_body || '').slice(0, 80)) + '</td>' +
                    '<td><button type="button" class="btn" data-id="' + r.id + '">Путь</button></td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            listWrap.innerHTML = html;
            listWrap.querySelectorAll('[data-id]').forEach(btn => {
                btn.addEventListener('click', () => openSession(Number(btn.getAttribute('data-id'))));
            });
        } catch (e) {
            listWrap.innerHTML = '';
            showError(e.message);
        }
    }

    async function openSession(id) {
        showError('');
        try {
            const data = await api('get_session', { session_id: id });
            const s = data.session;
            const steps = data.steps || [];
            detailCard.style.display = 'block';
            document.getElementById('detail-meta').textContent =
                s.started_at + ' · ' + s.user_name + ' · ' + s.tree_title + ' · ' + statusLabel(s.status);

            const ul = document.getElementById('detail-steps');
            ul.innerHTML = '';
            steps.forEach(st => {
                const li = document.createElement('li');
                let ans = '';
                if (st.answer === 'yes') ans = ' → <span class="ans">Да</span>';
                if (st.answer === 'no') ans = ' → <span class="ans">Нет</span>';
                const kind = st.node_type === 'cause' ? 'Причина' : 'Вопрос';
                li.innerHTML = '<strong>' + escapeHtml(kind) + ' ' + Number(st.step_no) + '.</strong> ' +
                    escapeHtml(st.node_body) + ans;
                ul.appendChild(li);
            });

            const box = document.getElementById('detail-result');
            if (s.result_body) {
                box.style.display = 'block';
                document.getElementById('detail-result-body').textContent = s.result_body;
                document.getElementById('detail-result-action').textContent = s.result_action_text || '';
            } else {
                box.style.display = 'none';
            }
            detailCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            showError(e.message);
        }
    }

    document.getElementById('btn-refresh').addEventListener('click', loadList);
    loadList();
})();
</script>
</body>
</html>
