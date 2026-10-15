<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = diagnostics_require_user_or_redirect(false);
diagnostics_pdo(); // ensure schema
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Диагностика ПУ-машины</title>
    <link rel="stylesheet" href="assets/diagnostics.css">
</head>
<body>
<header class="app-header">
    <div>
        <h1>Диагностика ПУ-машины</h1>
        <div class="meta"><?= htmlspecialchars($user['user_name']) ?></div>
    </div>
    <nav class="app-nav">
        <?php if ($user['is_editor']): ?>
            <a href="editor.php">Редактор</a>
            <a href="journal.php">Журнал</a>
        <?php endif; ?>
    </nav>
</header>

<main class="container">
    <div id="error" class="error" style="display:none;"></div>

    <section id="screen-list" class="step-screen active">
        <div class="card">
            <h2 style="margin-bottom:12px;font-size:1.05rem;">Выберите симптом</h2>
            <div id="symptom-list" class="symptom-list">
                <p class="muted">Загрузка…</p>
            </div>
        </div>
    </section>

    <section id="screen-step" class="step-screen">
        <div class="card">
            <div class="step-title" id="step-tree-title"></div>
            <div class="step-body" id="step-body"></div>
            <div class="step-actions" id="step-yesno">
                <button type="button" class="btn btn-success" id="btn-yes">Да</button>
                <button type="button" class="btn btn-danger" id="btn-no">Нет</button>
            </div>
            <div class="toolbar" style="margin-top:16px;">
                <button type="button" class="btn" id="btn-back">← Назад</button>
                <button type="button" class="btn btn-ghost" id="btn-cancel">Отменить</button>
            </div>
        </div>
    </section>

    <section id="screen-cause" class="step-screen">
        <div class="card">
            <div class="step-title" id="cause-tree-title"></div>
            <div class="cause-box">
                <h2 id="cause-body"></h2>
                <div class="action" id="cause-action"></div>
            </div>
            <div class="toolbar">
                <button type="button" class="btn btn-primary" id="btn-restart">Начать сначала</button>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    const elError = document.getElementById('error');
    const listEl = document.getElementById('symptom-list');
    const screens = {
        list: document.getElementById('screen-list'),
        step: document.getElementById('screen-step'),
        cause: document.getElementById('screen-cause'),
    };

    let sessionId = null;
    let treeTitle = '';
    let busy = false;

    function showError(msg) {
        elError.style.display = msg ? 'block' : 'none';
        elError.textContent = msg || '';
    }

    function showScreen(name) {
        Object.keys(screens).forEach(k => screens[k].classList.toggle('active', k === name));
    }

    async function api(action, payload) {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action }, payload || {})),
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({ success: false, message: 'Ошибка ответа сервера' }));
        if (!data.success) {
            throw new Error(data.message || 'Ошибка');
        }
        return data;
    }

    async function loadTrees() {
        showError('');
        listEl.innerHTML = '<p class="muted">Загрузка…</p>';
        try {
            const data = await api('list_trees', { active_only: 1 });
            const trees = data.trees || [];
            if (!trees.length) {
                listEl.innerHTML = '<p class="muted">Нет активных симптомов. Добавьте их в редакторе.</p>';
                return;
            }
            listEl.innerHTML = '';
            trees.forEach(t => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'symptom-btn';
                btn.textContent = t.title;
                btn.addEventListener('click', () => startSession(t.id));
                listEl.appendChild(btn);
            });
        } catch (e) {
            listEl.innerHTML = '';
            showError(e.message);
        }
    }

    function renderQuestion(node) {
        document.getElementById('step-tree-title').textContent = treeTitle;
        document.getElementById('step-body').textContent = node.body || '';
        document.getElementById('step-yesno').style.display = node.node_type === 'question' ? 'grid' : 'none';
        showScreen('step');
    }

    function renderCause(node) {
        document.getElementById('cause-tree-title').textContent = treeTitle;
        document.getElementById('cause-body').textContent = node.body || '';
        document.getElementById('cause-action').textContent = node.action_text || 'Следуйте инструкции технолога.';
        showScreen('cause');
    }

    async function startSession(treeId) {
        if (busy) return;
        busy = true;
        showError('');
        try {
            const data = await api('session_start', { tree_id: treeId });
            sessionId = data.session_id;
            treeTitle = (data.tree && data.tree.title) || '';
            if (data.node.node_type === 'cause') {
                renderCause(data.node);
            } else {
                renderQuestion(data.node);
            }
        } catch (e) {
            showError(e.message);
        } finally {
            busy = false;
        }
    }

    async function answer(ans) {
        if (busy || !sessionId) return;
        busy = true;
        showError('');
        try {
            const data = await api('session_step', { session_id: sessionId, answer: ans });
            if (data.finished || data.node.node_type === 'cause') {
                renderCause(data.node);
            } else {
                renderQuestion(data.node);
            }
        } catch (e) {
            showError(e.message);
        } finally {
            busy = false;
        }
    }

    async function goBack() {
        if (busy || !sessionId) return;
        busy = true;
        showError('');
        try {
            const data = await api('session_back', { session_id: sessionId });
            if (data.node.node_type === 'cause') {
                renderCause(data.node);
            } else {
                renderQuestion(data.node);
            }
        } catch (e) {
            showError(e.message);
        } finally {
            busy = false;
        }
    }

    async function cancelSession() {
        if (sessionId) {
            try { await api('session_abandon', { session_id: sessionId }); } catch (e) {}
        }
        sessionId = null;
        treeTitle = '';
        showError('');
        showScreen('list');
        loadTrees();
    }

    document.getElementById('btn-yes').addEventListener('click', () => answer('yes'));
    document.getElementById('btn-no').addEventListener('click', () => answer('no'));
    document.getElementById('btn-back').addEventListener('click', goBack);
    document.getElementById('btn-cancel').addEventListener('click', cancelSession);
    document.getElementById('btn-restart').addEventListener('click', () => {
        sessionId = null;
        treeTitle = '';
        showScreen('list');
        loadTrees();
    });

    loadTrees();
})();
</script>
</body>
</html>
