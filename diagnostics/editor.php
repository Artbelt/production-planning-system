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
    <title>Редактор диагностики ПУ</title>
    <link rel="stylesheet" href="assets/diagnostics.css">
</head>
<body>
<header class="app-header">
    <div>
        <h1>Редактор диагностики ПУ</h1>
        <div class="meta"><?= htmlspecialchars($user['user_name']) ?></div>
    </div>
    <nav class="app-nav">
        <a href="index.php">Оператор</a>
        <a href="journal.php">Журнал</a>
    </nav>
</header>

<main class="container">
    <div id="error" class="error" style="display:none;"></div>

    <div class="card">
        <h2 style="font-size:1.05rem;margin-bottom:12px;">Новый симптом</h2>
        <div class="form-row">
            <label for="new-title">Название</label>
            <input type="text" id="new-title" placeholder="Например: Не греет стол">
        </div>
        <button type="button" class="btn btn-primary" id="btn-create">Создать</button>
    </div>

    <div class="card">
        <h2 style="font-size:1.05rem;margin-bottom:12px;">Деревья</h2>
        <div id="trees-wrap"><p class="muted">Загрузка…</p></div>
    </div>
</main>

<script>
(function () {
    const elError = document.getElementById('error');
    const wrap = document.getElementById('trees-wrap');

    function showError(msg) {
        elError.style.display = msg ? 'block' : 'none';
        elError.textContent = msg || '';
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

    async function load() {
        showError('');
        try {
            const data = await api('list_trees', {});
            const trees = data.trees || [];
            if (!trees.length) {
                wrap.innerHTML = '<p class="muted">Пока нет деревьев.</p>';
                return;
            }
            let html = '<table class="data"><thead><tr>' +
                '<th>Симптом</th><th>Порядок</th><th>Статус</th><th></th></tr></thead><tbody>';
            trees.forEach(t => {
                const active = Number(t.is_active) === 1;
                html += '<tr>' +
                    '<td><strong>' + escapeHtml(t.title) + '</strong></td>' +
                    '<td>' + Number(t.sort_order) + '</td>' +
                    '<td>' + (active
                        ? '<span class="status-pill status-completed">активно</span>'
                        : '<span class="status-pill status-abandoned">выкл</span>') + '</td>' +
                    '<td class="toolbar" style="margin:0;">' +
                    '<a class="btn btn-primary" href="editor_tree.php?tree_id=' + t.id + '">Узлы</a>' +
                    '<button type="button" class="btn" data-toggle="' + t.id + '" data-active="' + (active ? 0 : 1) + '">' +
                    (active ? 'Выключить' : 'Включить') + '</button>' +
                    '<button type="button" class="btn btn-danger" data-del="' + t.id + '">Удалить</button>' +
                    '</td></tr>';
            });
            html += '</tbody></table>';
            wrap.innerHTML = html;

            wrap.querySelectorAll('[data-toggle]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    try {
                        await api('update_tree', {
                            tree_id: Number(btn.getAttribute('data-toggle')),
                            is_active: Number(btn.getAttribute('data-active')),
                        });
                        load();
                    } catch (e) { showError(e.message); }
                });
            });
            wrap.querySelectorAll('[data-del]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Удалить дерево и все узлы?')) return;
                    try {
                        await api('delete_tree', { tree_id: Number(btn.getAttribute('data-del')) });
                        load();
                    } catch (e) { showError(e.message); }
                });
            });
        } catch (e) {
            wrap.innerHTML = '';
            showError(e.message);
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    document.getElementById('btn-create').addEventListener('click', async () => {
        const title = document.getElementById('new-title').value.trim();
        if (!title) { showError('Укажите название'); return; }
        try {
            const data = await api('create_tree', { title });
            document.getElementById('new-title').value = '';
            location.href = 'editor_tree.php?tree_id=' + data.tree_id;
        } catch (e) { showError(e.message); }
    });

    load();
})();
</script>
</body>
</html>
