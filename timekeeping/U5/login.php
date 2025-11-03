// login.php
session_start();

if ($_POST) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $db = new PDO('mysql:host=localhost;dbname=your_db', 'root', '');
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: dashboard.php');
    } else {
        echo 'Неверный логин или пароль';
    }
}