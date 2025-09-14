<?php
include_once('config.php');

if(isset($_POST['submit'])) {
    $email = $_POST['email'];

    // Verifica se o email existe
    $stmt = $conexao->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows == 0){
        echo "E-mail não cadastrado.";
        exit();
    }

    $user = $result->fetch_assoc();
    $user_id = $user['id'];

    // Gera token único
    $token = bin2hex(random_bytes(16));
    $expira = date("Y-m-d H:i:s", strtotime("+1 hour"));

    // Salva token no DB
    $stmt2 = $conexao->prepare("INSERT INTO senha_reset (user_id, token, expira) VALUES (?, ?, ?)");
    $stmt2->bind_param("iss", $user_id, $token, $expira);
    $stmt2->execute();

    // Envia email (simulação)
    $link = "http://seusite.com/resetar_senha.php?token=$token";
    echo "Link de recuperação: <a href='$link'>$link</a>"; // substituir por envio de email real
}
?>
