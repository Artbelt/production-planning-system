<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = diagnostics_require_user_or_redirect(true);
$treeId = (int)($_GET['tree_id'] ?? 0);
if ($treeId <= 0) {
    header('Location: editor.php');
    exit;
}
diagnostics_pdo();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Узлы дерева</title>
    <link rel="stylesheet" href="assets/diagnostics.css">
</head>
<body>
<header class="app-header">
    <div>
        <h1 id="page-title">Узлы дерева</h1>
        <div class="meta"><?= htmlspecialchars($user['user_name']) ?></div>
    </div>
    <nav class="app-nav">
        <a href="editor.php">← К списку</a>
        <a href="index.php">Оператор</a>
    </nav>
</header>

<main class="container wide">
    <div id="error" class="error" style="display:none;"></div>
    <div id="warn" class="error" style="display:none;background:#fffbeb;border-color:#fde68a;color:#92400e;"></div>

    <div class="card">
        <div class="form-row">
            <label for="tree-title">Название симптома</label>
            <input type="text" id="tree-title">
        </div>
        <div class="toolbar">
            <button type="button" class="btn btn-primary" id="btn-save-tree">Сохранить название</button>
        </div>
    </div>

    <div class="card">
        <h2 style="font-size:1.05rem;margin-bottom:8px;">Дерево диагностики</h2>
        <p class="muted" style="margin-bottom:12px;">Клик по узлу — редактирование. Ветки: зелёная «Да», красная «Нет».</p>
        <div id="tree-view" class="tree-view"><p class="muted">Загрузка…</p></div>
    </div>

    <div class="card" id="edit-card" style="display:none;">
        <h2 style="font-size:1.05rem;margin-bottom:12px;">Редактирование узла <span id="edit-id" class="muted"></span></h2>
        <div class="form-row">
            <label for="edit-type">Тип</label>
            <select id="edit-type">
                <option value="question">Вопрос</option>
                <option value="cause">Причина</option>
            </select>
        </div>
        <div class="form-row">
            <label for="edit-body">Текст</label>
            <textarea id="edit-body"></textarea>
        </div>
        <div class="form-row" id="edit-action-row">
            <label for="edit-action">Что делать</label>
            <textarea id="edit-action"></textarea>
        </div>
        <div id="edit-links">
            <div class="form-row">
                <label for="edit-yes">Если Да →</label>
                <select id="edit-yes"></select>
            </div>
            <div class="form-row">
                <label for="edit-no">Если Нет →</label>
                <select id="edit-no"></select>
            </div>
        </div>
        <div class="toolbar">
            <button type="button" class="btn btn-primary" id="btn-save-node">Сохранить</button>
            <button type="button" class="btn" id="btn-set-root">Сделать корнем</button>
            <button type="button" class="btn btn-danger" id="btn-del-node">Удалить</button>
        </div>
    </div>

    <div class="card">
        <h2 style="font-size:1.05rem;margin-bottom:12px;">Добавить узел</h2>
        <div class="form-row">
            <label for="new-type">Тип</label>
            <select id="new-type">
                <option value="question">Вопрос</option>
                <option value="cause">Причина</option>
            </select>
        </div>
        <div class="form-row">
            <label for="new-body">Текст</label>
            <textarea id="new-body" placeholder="Текст вопроса или причины"></textarea>
        </div>
        <div class="form-row" id="new-action-row" style="display:none;">
            <label for="new-action">Что делать</label>
            <textarea id="new-action" placeholder="Инструкция для оператора"></textarea>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <input type="checkbox" id="new-root"> Сделать корнем
        </label>
        <button type="button" class="btn btn-primary" id="btn-add-node">Добавить</button>
    </div>
</main>

<script>
(function () {
    const treeId = <?= (int)$treeId ?>;
    const elError = document.getElementById('error');
    const elWarn = document.getElementById('warn');
    const treeView = document.getElementById('tree-view');
    const editCard = document.getElementById('edit-card');
    let nodes = [];
    let tree = null;
    let selectedId = null;

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

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function byId(id) {
        if (id === null || id === undefined || id === '') return null;
        return nodes.find(n => Number(n.id) === Number(id)) || null;
    }

    function nodeLabel(n) {
        if (!n) return '— не задано —';
        const short = (n.body || '').slice(0, 60);
        return '#' + n.id + ' [' + n.node_type + '] ' + short;
    }

    function fillOptions(selectEl, selectedId, excludeId) {
        let html = '<option value="">— не задано —</option>';
        nodes.forEach(n => {
            if (excludeId && Number(n.id) === Number(excludeId)) return;
            const sel = selectedId && Number(n.id) === Number(selectedId) ? ' selected' : '';
            html += '<option value="' + n.id + '"' + sel + '>' + escapeHtml(nodeLabel(n)) + '</option>';
        });
        selectEl.innerHTML = html;
    }

    function validateWarnings() {
        const roots = nodes.filter(n => Number(n.is_root) === 1);
        const msgs = [];
        if (roots.length === 0) msgs.push('Нет корневого узла — оператор не сможет начать диагностику.');
        if (roots.length > 1) msgs.push('Больше одного корня — оставьте только один.');
        nodes.forEach(n => {
            if (n.node_type === 'question') {
                if (!n.yes_node_id || !n.no_node_id) {
                    msgs.push('Вопрос #' + n.id + ': желательно задать оба ответа (Да и Нет).');
                }
            }
        });
        elWarn.style.display = msgs.length ? 'block' : 'none';
        elWarn.innerHTML = msgs.map(m => '<div>' + escapeHtml(m) + '</div>').join('');
    }

    function collectReachable(root) {
        const seen = new Set();
        function walk(n, depth) {
            if (!n || seen.has(Number(n.id)) || depth > 50) return;
            seen.add(Number(n.id));
            if (n.node_type === 'question') {
                walk(byId(n.yes_node_id), depth + 1);
                walk(byId(n.no_node_id), depth + 1);
            }
        }
        walk(root, 0);
        return seen;
    }

    function renderBranch(node, visited) {
        const wrap = document.createElement('div');
        wrap.className = 'tree-branch';

        if (!node) {
            wrap.innerHTML = '<div class="tree-missing">не задано</div>';
            return wrap;
        }

        const id = Number(node.id);
        if (visited.has(id)) {
            wrap.innerHTML = '<div class="tree-missing">цикл → #' + id + '</div>';
            return wrap;
        }
        visited = new Set(visited);
        visited.add(id);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tree-node-card'
            + (Number(node.is_root) === 1 ? ' is-root' : '')
            + (node.node_type === 'cause' ? ' is-cause' : '')
            + (selectedId && Number(selectedId) === id ? ' selected' : '');
        btn.dataset.nodeId = String(id);

        const kind = node.node_type === 'cause' ? 'причина' : 'вопрос';
        let meta = '<div class="tn-meta">'
            + '<span class="badge ' + escapeHtml(node.node_type) + '">' + kind + '</span>'
            + (Number(node.is_root) === 1 ? '<span class="badge root-badge">корень</span>' : '')
            + '<span class="muted">#' + id + '</span></div>';
        meta += '<div class="tn-body">' + escapeHtml(node.body) + '</div>';
        if (node.node_type === 'cause' && node.action_text) {
            meta += '<div class="tn-action">' + escapeHtml(node.action_text) + '</div>';
        }
        btn.innerHTML = meta;
        btn.addEventListener('click', () => selectNode(id));
        wrap.appendChild(btn);

        if (node.node_type === 'question') {
            const kids = document.createElement('div');
            kids.className = 'tree-children';

            const yesCol = document.createElement('div');
            yesCol.className = 'tree-child';
            yesCol.innerHTML = '<span class="tree-edge-label yes">Да</span>';
            yesCol.appendChild(renderBranch(byId(node.yes_node_id), visited));

            const noCol = document.createElement('div');
            noCol.className = 'tree-child';
            noCol.innerHTML = '<span class="tree-edge-label no">Нет</span>';
            noCol.appendChild(renderBranch(byId(node.no_node_id), visited));

            kids.appendChild(yesCol);
            kids.appendChild(noCol);
            wrap.appendChild(kids);
        }

        return wrap;
    }

    function renderTree() {
        treeView.innerHTML = '';
        if (!nodes.length) {
            treeView.innerHTML = '<p class="muted">Узлов пока нет. Добавьте корневой вопрос.</p>';
            validateWarnings();
            return;
        }

        const root = nodes.find(n => Number(n.is_root) === 1) || null;
        if (!root) {
            treeView.innerHTML = '<p class="muted">Корневой узел не задан. Выберите узел и нажмите «Сделать корнем».</p>';
        } else {
            treeView.appendChild(renderBranch(root, new Set()));
        }

        const reachable = root ? collectReachable(root) : new Set();
        const orphans = nodes.filter(n => !reachable.has(Number(n.id)));
        if (orphans.length) {
            const box = document.createElement('div');
            box.className = 'tree-orphans';
            box.innerHTML = '<h3>Не связанные с корнем</h3><p class="muted" style="margin-bottom:8px;">Эти узлы не достижимы из корня — привяжите их через «Да/Нет» или удалите.</p>';
            const list = document.createElement('div');
            list.className = 'tree-orphan-list';
            orphans.forEach(n => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tree-node-card'
                    + (n.node_type === 'cause' ? ' is-cause' : '')
                    + (selectedId && Number(selectedId) === Number(n.id) ? ' selected' : '');
                btn.innerHTML = '<div class="tn-meta"><span class="badge ' + escapeHtml(n.node_type) + '">'
                    + (n.node_type === 'cause' ? 'причина' : 'вопрос') + '</span>'
                    + '<span class="muted">#' + n.id + '</span></div>'
                    + '<div class="tn-body">' + escapeHtml(n.body) + '</div>';
                btn.addEventListener('click', () => selectNode(Number(n.id)));
                list.appendChild(btn);
            });
            box.appendChild(list);
            treeView.appendChild(box);
        }

        validateWarnings();
    }

    function syncEditFormVisibility() {
        const isCause = document.getElementById('edit-type').value === 'cause';
        document.getElementById('edit-action-row').style.display = isCause ? '' : 'none';
        document.getElementById('edit-links').style.display = isCause ? 'none' : '';
    }

    function selectNode(id) {
        selectedId = id;
        const n = byId(id);
        if (!n) {
            editCard.style.display = 'none';
            renderTree();
            return;
        }
        editCard.style.display = 'block';
        document.getElementById('edit-id').textContent = '#' + n.id;
        document.getElementById('edit-type').value = n.node_type;
        document.getElementById('edit-body').value = n.body || '';
        document.getElementById('edit-action').value = n.action_text || '';
        fillOptions(document.getElementById('edit-yes'), n.yes_node_id, n.id);
        fillOptions(document.getElementById('edit-no'), n.no_node_id, n.id);
        syncEditFormVisibility();
        renderTree();
        editCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    async function load() {
        showError('');
        try {
            const data = await api('get_tree', { tree_id: treeId });
            tree = data.tree;
            nodes = data.nodes || [];
            document.getElementById('page-title').textContent = tree.title;
            document.getElementById('tree-title').value = tree.title;
            document.title = 'Узлы: ' + tree.title;
            if (selectedId && !byId(selectedId)) {
                selectedId = null;
                editCard.style.display = 'none';
            } else if (selectedId) {
                selectNode(selectedId);
                return;
            }
            renderTree();
        } catch (e) {
            treeView.innerHTML = '';
            showError(e.message);
        }
    }

    document.getElementById('edit-type').addEventListener('change', syncEditFormVisibility);

    document.getElementById('btn-save-node').addEventListener('click', async () => {
        if (!selectedId) return;
        showError('');
        try {
            await api('update_node', {
                node_id: selectedId,
                node_type: document.getElementById('edit-type').value,
                body: document.getElementById('edit-body').value,
                action_text: document.getElementById('edit-action').value,
                yes_node_id: document.getElementById('edit-yes').value,
                no_node_id: document.getElementById('edit-no').value,
            });
            await load();
        } catch (e) { showError(e.message); }
    });

    document.getElementById('btn-set-root').addEventListener('click', async () => {
        if (!selectedId) return;
        try {
            await api('set_root', { node_id: selectedId });
            await load();
        } catch (e) { showError(e.message); }
    });

    document.getElementById('btn-del-node').addEventListener('click', async () => {
        if (!selectedId) return;
        if (!confirm('Удалить узел #' + selectedId + '?')) return;
        try {
            await api('delete_node', { node_id: selectedId });
            selectedId = null;
            editCard.style.display = 'none';
            await load();
        } catch (e) { showError(e.message); }
    });

    document.getElementById('new-type').addEventListener('change', (e) => {
        document.getElementById('new-action-row').style.display =
            e.target.value === 'cause' ? '' : 'none';
    });

    document.getElementById('btn-save-tree').addEventListener('click', async () => {
        try {
            await api('update_tree', {
                tree_id: treeId,
                title: document.getElementById('tree-title').value.trim(),
            });
            await load();
        } catch (e) { showError(e.message); }
    });

    document.getElementById('btn-add-node').addEventListener('click', async () => {
        const body = document.getElementById('new-body').value.trim();
        if (!body) { showError('Введите текст узла'); return; }
        try {
            const data = await api('create_node', {
                tree_id: treeId,
                node_type: document.getElementById('new-type').value,
                body,
                action_text: document.getElementById('new-action').value,
                is_root: document.getElementById('new-root').checked ? 1 : 0,
            });
            document.getElementById('new-body').value = '';
            document.getElementById('new-action').value = '';
            document.getElementById('new-root').checked = false;
            selectedId = data.node_id || null;
            await load();
        } catch (e) { showError(e.message); }
    });

    load();
})();
</script>
</body>
</html>
