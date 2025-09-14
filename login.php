<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login</title>
<style>
/* Seu CSS responsivo para login (igual ao que você tinha) */
body{
    font-family: Arial, Helvetica, sans-serif;
    background: linear-gradient(to right, rgb(20, 147, 220), rgb(17, 54, 71));
    margin: 0;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
}
.telaLogin{
    background-color: rgba(0,0,0,0.9);
    padding: 30px;
    border-radius: 15px;
    color:white;
    text-align:center;
    width:90%;
    max-width:350px;
    box-sizing:border-box;
}
.nome,.senha{padding:15px; border:none; outline:none; font-size:15px; width:100%; border-radius:8px; margin-bottom:15px; box-sizing:border-box;}
.inputSubmit{background-color:dodgerblue;padding:15px;border:none;color:white;width:100%;border-radius:10px;font-size:15px;transition:0.3s;}
.inputSubmit:hover{background-color:deepskyblue; cursor:pointer;}
.cadastro{display:block; text-align:center; margin-top:15px; color:white; text-decoration:none;}
.cadastro:hover{text-decoration:underline;}
</style>
</head>
<body>
<div class="telaLogin">
    <h1>Login</h1>
    <form action="test.php" method="POST">
        <input class="nome" type="text" name="email" placeholder="E-mail" required>
        <input class="senha" type="password" name="senha" placeholder="Senha" required>
        <input class="inputSubmit" type="submit" name="submit" value="Entrar">
    </form>
    <a href="formulario.php" class="cadastro">Não tem cadastro? Clique aqui</a>
</div>
</body>
</html>
