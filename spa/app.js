document.addEventListener("DOMContentLoaded", function () {
    fetch('/api/orders.php')  // Запрос к PHP-скрипту
        .then(response => response.json()) // Преобразуем в JSON
        .then(data => {
            let ordersList = document.getElementById("orders");
            ordersList.innerHTML = ""; // Очищаем список перед обновлением

            data.forEach(order => {
                let li = document.createElement("li");
                li.textContent = `Заказ #${order.id}: ${order.product} (${order.status})`;
                ordersList.appendChild(li);
            });
        })
        .catch(error => console.error("Ошибка загрузки данных:", error));
});
