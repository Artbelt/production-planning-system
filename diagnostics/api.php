<?php
/**
 * JSON API модуля диагностики ПУ.
 *
 * Actions (POST JSON или form):
 *  list_trees, get_tree, get_node
 *  create_tree, update_tree, delete_tree
 *  create_node, update_node, delete_node, set_root
 *  session_start, session_step, session_finish, session_abandon
 *  list_sessions, get_session
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$input = [];
$raw = file_get_contents('php://input');
if ($raw !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (!$input) {
    $input = array_merge($_GET, $_POST);
}

$action = (string)($input['action'] ?? '');

try {
    $pdo = diagnostics_pdo();

    switch ($action) {
        case 'list_trees':
            $user = diagnostics_json_require_user(false);
            $activeOnly = !empty($input['active_only']);
            $sql = 'SELECT id, title, is_active, sort_order, updated_at FROM pu_diag_trees';
            if ($activeOnly) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY sort_order ASC, id ASC';
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode(['success' => true, 'trees' => $rows, 'is_editor' => $user['is_editor']], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_tree':
            diagnostics_json_require_user(false);
            $treeId = (int)($input['tree_id'] ?? 0);
            $tree = diagnostics_fetch_tree($pdo, $treeId);
            if (!$tree) {
                throw new RuntimeException('Дерево не найдено', 404);
            }
            $nodes = diagnostics_fetch_nodes($pdo, $treeId);
            echo json_encode(['success' => true, 'tree' => $tree, 'nodes' => $nodes], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_node':
            diagnostics_json_require_user(false);
            $nodeId = (int)($input['node_id'] ?? 0);
            $node = diagnostics_fetch_node($pdo, $nodeId);
            if (!$node) {
                throw new RuntimeException('Узел не найден', 404);
            }
            echo json_encode(['success' => true, 'node' => $node], JSON_UNESCAPED_UNICODE);
            break;

        case 'create_tree':
            diagnostics_json_require_user(true);
            $title = trim((string)($input['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Укажите название симптома', 400);
            }
            $sort = (int)($input['sort_order'] ?? 0);
            $stmt = $pdo->prepare(
                'INSERT INTO pu_diag_trees (title, is_active, sort_order) VALUES (?, 1, ?)'
            );
            $stmt->execute([$title, $sort]);
            $id = (int)$pdo->lastInsertId();
            echo json_encode(['success' => true, 'tree_id' => $id], JSON_UNESCAPED_UNICODE);
            break;

        case 'update_tree':
            diagnostics_json_require_user(true);
            $treeId = (int)($input['tree_id'] ?? 0);
            $tree = diagnostics_fetch_tree($pdo, $treeId);
            if (!$tree) {
                throw new RuntimeException('Дерево не найдено', 404);
            }
            $title = array_key_exists('title', $input) ? trim((string)$input['title']) : $tree['title'];
            if ($title === '') {
                throw new RuntimeException('Название не может быть пустым', 400);
            }
            $isActive = array_key_exists('is_active', $input)
                ? (int)!empty($input['is_active'])
                : (int)$tree['is_active'];
            $sort = array_key_exists('sort_order', $input)
                ? (int)$input['sort_order']
                : (int)$tree['sort_order'];
            $stmt = $pdo->prepare(
                'UPDATE pu_diag_trees SET title = ?, is_active = ?, sort_order = ? WHERE id = ?'
            );
            $stmt->execute([$title, $isActive, $sort, $treeId]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_tree':
            diagnostics_json_require_user(true);
            $treeId = (int)($input['tree_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM pu_diag_trees WHERE id = ?');
            $stmt->execute([$treeId]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'create_node':
            diagnostics_json_require_user(true);
            $treeId = (int)($input['tree_id'] ?? 0);
            if (!diagnostics_fetch_tree($pdo, $treeId)) {
                throw new RuntimeException('Дерево не найдено', 404);
            }
            $type = (string)($input['node_type'] ?? 'question');
            if (!in_array($type, ['question', 'cause'], true)) {
                throw new RuntimeException('Неверный тип узла', 400);
            }
            $body = trim((string)($input['body'] ?? ''));
            if ($body === '') {
                throw new RuntimeException('Текст узла обязателен', 400);
            }
            $actionText = $type === 'cause' ? trim((string)($input['action_text'] ?? '')) : null;
            if ($type === 'cause' && ($actionText === null || $actionText === '')) {
                $actionText = '';
            }
            $isRoot = !empty($input['is_root']) ? 1 : 0;
            if ($isRoot) {
                $pdo->prepare('UPDATE pu_diag_nodes SET is_root = 0 WHERE tree_id = ?')->execute([$treeId]);
            }
            $yesId = isset($input['yes_node_id']) && $input['yes_node_id'] !== '' && $input['yes_node_id'] !== null
                ? (int)$input['yes_node_id'] : null;
            $noId = isset($input['no_node_id']) && $input['no_node_id'] !== '' && $input['no_node_id'] !== null
                ? (int)$input['no_node_id'] : null;
            if ($type === 'cause') {
                $yesId = null;
                $noId = null;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO pu_diag_nodes
                 (tree_id, node_type, body, action_text, yes_node_id, no_node_id, is_root)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$treeId, $type, $body, $actionText, $yesId, $noId, $isRoot]);
            echo json_encode(['success' => true, 'node_id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
            break;

        case 'update_node':
            diagnostics_json_require_user(true);
            $nodeId = (int)($input['node_id'] ?? 0);
            $node = diagnostics_fetch_node($pdo, $nodeId);
            if (!$node) {
                throw new RuntimeException('Узел не найден', 404);
            }
            $type = array_key_exists('node_type', $input)
                ? (string)$input['node_type']
                : $node['node_type'];
            if (!in_array($type, ['question', 'cause'], true)) {
                throw new RuntimeException('Неверный тип узла', 400);
            }
            $body = array_key_exists('body', $input)
                ? trim((string)$input['body'])
                : $node['body'];
            if ($body === '') {
                throw new RuntimeException('Текст узла обязателен', 400);
            }
            $actionText = array_key_exists('action_text', $input)
                ? trim((string)$input['action_text'])
                : $node['action_text'];
            if ($type !== 'cause') {
                $actionText = null;
            }
            $yesId = array_key_exists('yes_node_id', $input)
                ? ($input['yes_node_id'] === '' || $input['yes_node_id'] === null ? null : (int)$input['yes_node_id'])
                : ($node['yes_node_id'] !== null ? (int)$node['yes_node_id'] : null);
            $noId = array_key_exists('no_node_id', $input)
                ? ($input['no_node_id'] === '' || $input['no_node_id'] === null ? null : (int)$input['no_node_id'])
                : ($node['no_node_id'] !== null ? (int)$node['no_node_id'] : null);
            if ($type === 'cause') {
                $yesId = null;
                $noId = null;
            }
            if ($yesId === $nodeId || $noId === $nodeId) {
                throw new RuntimeException('Узел не может ссылаться сам на себя', 400);
            }
            $isRoot = array_key_exists('is_root', $input)
                ? (!empty($input['is_root']) ? 1 : 0)
                : (int)$node['is_root'];
            if ($isRoot) {
                $pdo->prepare('UPDATE pu_diag_nodes SET is_root = 0 WHERE tree_id = ? AND id != ?')
                    ->execute([(int)$node['tree_id'], $nodeId]);
            }
            $stmt = $pdo->prepare(
                'UPDATE pu_diag_nodes
                 SET node_type = ?, body = ?, action_text = ?, yes_node_id = ?, no_node_id = ?, is_root = ?
                 WHERE id = ?'
            );
            $stmt->execute([$type, $body, $actionText, $yesId, $noId, $isRoot, $nodeId]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_node':
            diagnostics_json_require_user(true);
            $nodeId = (int)($input['node_id'] ?? 0);
            $node = diagnostics_fetch_node($pdo, $nodeId);
            if (!$node) {
                throw new RuntimeException('Узел не найден', 404);
            }
            // Обнуляем ссылки с других узлов
            $pdo->prepare(
                'UPDATE pu_diag_nodes SET yes_node_id = NULL WHERE yes_node_id = ?'
            )->execute([$nodeId]);
            $pdo->prepare(
                'UPDATE pu_diag_nodes SET no_node_id = NULL WHERE no_node_id = ?'
            )->execute([$nodeId]);
            $pdo->prepare('DELETE FROM pu_diag_nodes WHERE id = ?')->execute([$nodeId]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'set_root':
            diagnostics_json_require_user(true);
            $nodeId = (int)($input['node_id'] ?? 0);
            $node = diagnostics_fetch_node($pdo, $nodeId);
            if (!$node) {
                throw new RuntimeException('Узел не найден', 404);
            }
            $pdo->prepare('UPDATE pu_diag_nodes SET is_root = 0 WHERE tree_id = ?')
                ->execute([(int)$node['tree_id']]);
            $pdo->prepare('UPDATE pu_diag_nodes SET is_root = 1 WHERE id = ?')
                ->execute([$nodeId]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'session_start':
            $user = diagnostics_json_require_user(false);
            $treeId = (int)($input['tree_id'] ?? 0);
            $tree = diagnostics_fetch_tree($pdo, $treeId);
            if (!$tree || !(int)$tree['is_active']) {
                throw new RuntimeException('Симптом недоступен', 404);
            }
            $root = diagnostics_fetch_root($pdo, $treeId);
            if (!$root) {
                throw new RuntimeException('У дерева нет корневого вопроса', 400);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO pu_diag_sessions
                 (tree_id, tree_title, user_id, user_name, status)
                 VALUES (?, ?, ?, ?, \'in_progress\')'
            );
            $stmt->execute([
                $treeId,
                $tree['title'],
                $user['user_id'],
                $user['user_name'],
            ]);
            $sessionId = (int)$pdo->lastInsertId();

            // Первый шаг — показ корня без ответа
            $step = $pdo->prepare(
                'INSERT INTO pu_diag_session_steps
                 (session_id, step_no, node_id, node_body, node_type, answer)
                 VALUES (?, 1, ?, ?, ?, NULL)'
            );
            $step->execute([
                $sessionId,
                (int)$root['id'],
                $root['body'],
                $root['node_type'],
            ]);

            echo json_encode([
                'success' => true,
                'session_id' => $sessionId,
                'tree' => $tree,
                'node' => $root,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'session_step':
            $user = diagnostics_json_require_user(false);
            $sessionId = (int)($input['session_id'] ?? 0);
            $answer = (string)($input['answer'] ?? '');
            if (!in_array($answer, ['yes', 'no'], true)) {
                throw new RuntimeException('Ответ должен быть yes или no', 400);
            }
            $session = diagnostics_fetch_session($pdo, $sessionId);
            if (!$session || $session['status'] !== 'in_progress') {
                throw new RuntimeException('Сессия неактивна', 400);
            }
            if ((int)$session['user_id'] !== (int)$user['user_id'] && !$user['is_editor']) {
                throw new RuntimeException('Чужая сессия', 403);
            }

            $current = diagnostics_session_current_node($pdo, $sessionId);
            if (!$current) {
                throw new RuntimeException('Текущий узел не найден', 400);
            }
            if ($current['node_type'] !== 'question') {
                throw new RuntimeException('На причине нельзя отвечать Да/Нет', 400);
            }

            $fullNode = diagnostics_fetch_node($pdo, (int)$current['node_id']);
            if (!$fullNode) {
                throw new RuntimeException('Узел удалён', 400);
            }

            // Фиксируем ответ на текущем шаге
            $pdo->prepare(
                'UPDATE pu_diag_session_steps SET answer = ? WHERE id = ?'
            )->execute([$answer, (int)$current['id']]);

            $nextId = $answer === 'yes'
                ? ($fullNode['yes_node_id'] !== null ? (int)$fullNode['yes_node_id'] : null)
                : ($fullNode['no_node_id'] !== null ? (int)$fullNode['no_node_id'] : null);

            if (!$nextId) {
                throw new RuntimeException('Для этого ответа не задан следующий узел. Обратитесь к технологу.', 400);
            }

            $next = diagnostics_fetch_node($pdo, $nextId);
            if (!$next) {
                throw new RuntimeException('Следующий узел не найден', 400);
            }

            $stepNo = (int)$pdo->query(
                'SELECT COALESCE(MAX(step_no), 0) FROM pu_diag_session_steps WHERE session_id = ' . (int)$sessionId
            )->fetchColumn() + 1;

            $ins = $pdo->prepare(
                'INSERT INTO pu_diag_session_steps
                 (session_id, step_no, node_id, node_body, node_type, answer)
                 VALUES (?, ?, ?, ?, ?, NULL)'
            );
            $ins->execute([
                $sessionId,
                $stepNo,
                (int)$next['id'],
                $next['body'],
                $next['node_type'],
            ]);

            $finished = false;
            if ($next['node_type'] === 'cause') {
                $pdo->prepare(
                    'UPDATE pu_diag_sessions
                     SET status = \'completed\', finished_at = NOW(),
                         result_node_id = ?, result_body = ?, result_action_text = ?
                     WHERE id = ?'
                )->execute([
                    (int)$next['id'],
                    $next['body'],
                    $next['action_text'],
                    $sessionId,
                ]);
                $finished = true;
            }

            echo json_encode([
                'success' => true,
                'node' => $next,
                'finished' => $finished,
                'session_id' => $sessionId,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'session_finish':
            // На случай если cause уже показан без auto-finish
            $user = diagnostics_json_require_user(false);
            $sessionId = (int)($input['session_id'] ?? 0);
            $session = diagnostics_fetch_session($pdo, $sessionId);
            if (!$session) {
                throw new RuntimeException('Сессия не найдена', 404);
            }
            if ($session['status'] === 'completed') {
                echo json_encode(['success' => true, 'already' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            $current = diagnostics_session_current_node($pdo, $sessionId);
            $pdo->prepare(
                'UPDATE pu_diag_sessions
                 SET status = \'completed\', finished_at = NOW(),
                     result_node_id = ?, result_body = ?, result_action_text = ?
                 WHERE id = ?'
            )->execute([
                $current ? (int)$current['node_id'] : null,
                $current['node_body'] ?? null,
                $input['result_action_text'] ?? null,
                $sessionId,
            ]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'session_abandon':
            $user = diagnostics_json_require_user(false);
            $sessionId = (int)($input['session_id'] ?? 0);
            $session = diagnostics_fetch_session($pdo, $sessionId);
            if (!$session) {
                throw new RuntimeException('Сессия не найдена', 404);
            }
            if ($session['status'] === 'in_progress') {
                $pdo->prepare(
                    'UPDATE pu_diag_sessions SET status = \'abandoned\', finished_at = NOW() WHERE id = ?'
                )->execute([$sessionId]);
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'session_back':
            $user = diagnostics_json_require_user(false);
            $sessionId = (int)($input['session_id'] ?? 0);
            $session = diagnostics_fetch_session($pdo, $sessionId);
            if (!$session || $session['status'] !== 'in_progress') {
                throw new RuntimeException('Сессия неактивна', 400);
            }
            $steps = $pdo->prepare(
                'SELECT * FROM pu_diag_session_steps WHERE session_id = ? ORDER BY step_no ASC'
            );
            $steps->execute([$sessionId]);
            $all = $steps->fetchAll();
            if (count($all) <= 1) {
                $node = diagnostics_fetch_node($pdo, (int)$all[0]['node_id']);
                echo json_encode(['success' => true, 'node' => $node, 'at_root' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            // Удаляем последний шаг, сбрасываем ответ на предыдущем
            $last = $all[count($all) - 1];
            $prev = $all[count($all) - 2];
            $pdo->prepare('DELETE FROM pu_diag_session_steps WHERE id = ?')->execute([(int)$last['id']]);
            $pdo->prepare('UPDATE pu_diag_session_steps SET answer = NULL WHERE id = ?')
                ->execute([(int)$prev['id']]);
            $node = diagnostics_fetch_node($pdo, (int)$prev['node_id']);
            echo json_encode(['success' => true, 'node' => $node, 'at_root' => false], JSON_UNESCAPED_UNICODE);
            break;

        case 'list_sessions':
            diagnostics_json_require_user(true);
            $limit = min(200, max(1, (int)($input['limit'] ?? 50)));
            $stmt = $pdo->prepare(
                'SELECT id, tree_id, tree_title, user_id, user_name, started_at, finished_at,
                        status, result_body, result_action_text
                 FROM pu_diag_sessions
                 ORDER BY started_at DESC
                 LIMIT ' . $limit
            );
            $stmt->execute();
            echo json_encode(['success' => true, 'sessions' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_session':
            diagnostics_json_require_user(true);
            $sessionId = (int)($input['session_id'] ?? 0);
            $session = diagnostics_fetch_session($pdo, $sessionId);
            if (!$session) {
                throw new RuntimeException('Сессия не найдена', 404);
            }
            $steps = $pdo->prepare(
                'SELECT step_no, node_id, node_body, node_type, answer, created_at
                 FROM pu_diag_session_steps WHERE session_id = ? ORDER BY step_no ASC'
            );
            $steps->execute([$sessionId]);
            echo json_encode([
                'success' => true,
                'session' => $session,
                'steps' => $steps->fetchAll(),
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new RuntimeException('Неизвестное действие', 400);
    }
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code >= 400 ? $code : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

function diagnostics_fetch_tree(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pu_diag_trees WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function diagnostics_fetch_nodes(PDO $pdo, int $treeId): array
{
    $stmt = $pdo->prepare('SELECT * FROM pu_diag_nodes WHERE tree_id = ? ORDER BY is_root DESC, id ASC');
    $stmt->execute([$treeId]);
    return $stmt->fetchAll();
}

function diagnostics_fetch_node(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pu_diag_nodes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function diagnostics_fetch_root(PDO $pdo, int $treeId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pu_diag_nodes WHERE tree_id = ? AND is_root = 1 LIMIT 1');
    $stmt->execute([$treeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function diagnostics_fetch_session(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pu_diag_sessions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function diagnostics_session_current_node(PDO $pdo, int $sessionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM pu_diag_session_steps WHERE session_id = ? ORDER BY step_no DESC LIMIT 1'
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
