<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$order = $_GET['order'] ?? '';
$days = intval($_GET['days'] ?? 9);
$start = $_GET['start'] ?? date('Y-m-d');

$start_date = new DateTime($start);
$dates = [];
for ($i = 0; $i < $days; $i++) {
    $dates[] = $start_date->format('Y-m-d');
    $start_date->modify('+1 day');
}

$stmt = $pdo->prepare("SELECT roll_plan.plan_date, filter, height, width 
                       FROM cut_plans 
                       JOIN roll_plan ON cut_plans.bale_id = roll_plan.bale_id 
                       WHERE cut_plans.order_number = ? AND roll_plan.order_number = ?");
$stmt->execute([$order, $order]);
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$by_date = [];
foreach ($positions as $p) {
    $label = "{$p['filter']} [{$p['height']}] {$p['width']}";
    $by_date[$p['plan_date']][] = $label;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Планирование гофрирования</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f0f0; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 5px; vertical-align: top; }
        .position-cell { cursor: pointer; padding: 3px; border-bottom: 1px dotted #ccc; }
        .used { color: #aaa; text-decoration: line-through; pointer-events: none; }
        .drop-target { min-height: 50px; }
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.4); justify-content: center; align-items: center;
        }
        .modal-content {
            background: white; padding: 20px; border-radius: 5px; width: 400px;
        }
        .modal h3 { margin-top: 0; }
        .modal button { margin-top: 10px; }
    </style>
</head>
<body>
<h2>Планирование гофрирования для заявки <?= htmlspecialchars($order) ?></h2>
<form method="get">
    Дата начала: <input type="date" name="start" value="<?= htmlspecialchars($_GET['start'] ?? date('Y-m-d')) ?>">
    Дней: <input type="number" name="days" value="<?= $days ?>" min="1" max="30">
    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
    <button type="submit">Построить таблицу</button>
</form>

<h3>Доступные позиции из раскроя</h3>
<table>
    <tr>
        <?php foreach ($dates as $d): ?>
            <th><?= $d ?></th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php foreach ($dates as $d): ?>
            <td>
                <?php foreach ($by_date[$d] ?? [] as $item): ?>
                    <div class="position-cell" data-filter="<?= htmlspecialchars($item) ?>" data-cut-date="<?= $d ?>">
                        <?= htmlspecialchars($item) ?>
                    </div>

                <?php endforeach; ?>

            </td>
        <?php endforeach; ?>
    </tr>
</table>

<h3>Планирование гофрирования</h3>
<form method="post" action="save_corrugation_plan.php">
    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
    <table>
        <tr>
            <?php foreach ($dates as $d): ?>
                <th><?= $d ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($dates as $d): ?>
                <td class="drop-target" data-date="<?= $d ?>"></td>
            <?php endforeach; ?>
        </tr>
    </table>
    <input type="hidden" name="plan_data" id="plan_data">
    <button type="submit" onclick="preparePlan()">Сохранить план</button>
</form>

<!-- Модалка -->
<div class="modal" id="modal">
    <div class="modal-content">
        <h3>Выберите дату</h3>
        <div id="modal-dates"></div>
        <button onclick="closeModal()">Отмена</button>
    </div>
</div>

<script>
    let selectedFilter = '';
    let selectedCutDate = '';

    function closeModal() {
        document.getElementById("modal").style.display = "none";
        selectedFilter = '';
        selectedCutDate = '';
    }

    document.querySelectorAll('.position-cell').forEach(cell => {
        cell.addEventListener('click', () => {
            if (cell.classList.contains('used')) return;

            selectedFilter = cell.innerText;
            selectedCutDate = cell.dataset.cutDate;

            document.getElementById("modal").style.display = "flex";
            const modalDates = document.getElementById("modal-dates");
            modalDates.innerHTML = '';

            document.querySelectorAll('.drop-target').forEach(td => {
                const date = td.getAttribute('data-date');
                if (date >= selectedCutDate) {
                    const btn = document.createElement('button');
                    btn.textContent = date;
                    btn.onclick = () => {
                        td.innerHTML += `<div>${selectedFilter}</div>`;
                        cell.classList.add('used');
                        closeModal();
                    };
                    modalDates.appendChild(btn);
                }
            });
        });
    });

    function preparePlan() {
        const data = {};
        document.querySelectorAll('.drop-target').forEach(td => {
            const date = td.getAttribute('data-date');
            const items = Array.from(td.querySelectorAll('div')).map(d => d.innerText);
            if (items.length > 0) data[date] = items;
        });
        document.getElementById('plan_data').value = JSON.stringify(data);
    }
</script>

</body>
</html>