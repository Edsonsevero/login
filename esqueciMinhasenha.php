<?php
include_once('config.php');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Recuperar Senha</title>
</head>
<body>
    <h2>Recuperar Senha</h2>
    <form method="POST" action="processar_recuperacao.php">
        <label for="email">Digite seu e-mail:</label><br>
        <input type="email" name="email" id="email" required>
        <br><br>
        <input type="submit" name="submit" value="Enviar link de recuperação">
    </form>
</body>
</html>
