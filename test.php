<?php
session_start();
include_once('config.php');

// Se já estiver logado, redireciona
if (isset($_SESSION['id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'sistema.php' : 'sistema_usuario.php'));
    exit();
}

// Processamento do login
if (isset($_POST['submit']) && !empty($_POST['email']) && !empty($_POST['senha'])) {
    $email = trim($_POST['email']);
    $senha = trim($_POST['senha']);

    $stmt = $conexao->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $usuario = $result->fetch_assoc();

        // Usuário bloqueado
        if ($usuario['status_conta'] === 'bloqueado') {
            header("Location: test.php?erro=bloqueado");
            exit();
        }

        // Senha correta
        if (password_verify($senha, $usuario['senha'])) {
            // Cria sessão
            $_SESSION['id'] = $usuario['id'];
            $_SESSION['email'] = $usuario['email'];
            $_SESSION['nome'] = $usuario['nome'];
            $_SESSION['role'] = (isset($usuario['role']) && trim(strtolower($usuario['role'])) === 'admin') ? 'admin' : 'usuario';

            // Salva session_id no DB
            $sessionId = session_id();
            $stmt2 = $conexao->prepare("UPDATE usuarios SET session_id = ?, forced_logout = 0 WHERE id = ?");
            $stmt2->bind_param("si", $sessionId, $usuario['id']);
            $stmt2->execute();

            // Registro de login
            $stmtLog = $conexao->prepare("INSERT INTO user_logs (user_id, acao, ip) VALUES (?, 'Login', ?)");
            $ip = $_SERVER['REMOTE_ADDR'];
            $stmtLog->bind_param("is", $usuario['id'], $ip);
            $stmtLog->execute();

            // Redireciona conforme role
            header('Location: ' . ($_SESSION['role'] === 'admin' ? 'sistema.php' : 'sistema_usuario.php'));
            exit();
        } else {
            header('Location: test.php?erro=senha');
            exit();
        }
    } else {
        header('Location: test.php?erro=usuario');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .login-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-container h1 {
            margin-bottom: 20px;
            color: #333;
        }
        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 12px;
            margin: 8px 0;
            border-radius: 5px;
            border: 1px solid #ccc;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #007BFF;
            border: none;
            color: #fff;
            font-weight: bold;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        .error-msg {
            background-color: #ff4d4d;
            color: #fff;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Login</h1>

        <?php
        if (isset($_GET['erro'])) {
            $msg = '';
            switch ($_GET['erro']) {
                case 'bloqueado':
                    $msg = 'Seu perfil está temporariamente bloqueado e em avaliação técnica.';
                    break;
                case 'senha':
                    $msg = 'Senha incorreta!';
                    break;
                case 'usuario':
                    $msg = 'Usuário não encontrado!';
                    break;
            }
            echo "<div class='error-msg'>{$msg}</div>";
        }
        ?>

        <form method="post">
            <input type="email" name="email" placeholder="E-mail" required>
            <input type="password" name="senha" placeholder="Senha" required>
            <button type="submit" name="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
