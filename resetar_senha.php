<?php
include_once('config.php');

if(!isset($_GET['token'])){
    die("Token inválido.");
}

$token = $_GET['token'];

// Verifica token
$stmt = $conexao->prepare("SELECT user_id, expira FROM senha_reset WHERE token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows == 0){
    die("Token inválido ou expirado.");
}

$reset = $result->fetch_assoc();
$expira = $reset['expira'];
$user_id = $reset['user_id'];

if(strtotime($expira) < time()){
    die("Token expirado.");
}

if(isset($_POST['submit'])){
    $nova_senha = $_POST['senha'];
    $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);

    // Atualiza senha
    $stmt2 = $conexao->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
    $stmt2->bind_param("si", $senha_hash, $user_id);
    $stmt2->execute();

    // Remove token
    $stmt3 = $conexao->prepare("DELETE FROM senha_reset WHERE token = ?");
    $stmt3->bind_param("s", $token);
    $stmt3->execute();

    echo "Senha alterada com sucesso! <a href='login.php'>Login</a>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Redefinir Senha</title>
</head>
<body>
    <h2>Redefinir Senha</h2>
    <form method="POST">
        <label for="senha">Nova senha:</label><br>
        <input type="password" name="senha" id="senha" required>
        <br><br>
        <input type="submit" name="submit" value="Redefinir senha">
    </form>
</body>
</html>
