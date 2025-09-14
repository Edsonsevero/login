<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include_once('config.php');

$abrir_modal_email = false;
$abrir_modal_senha = false;
$mensagem_modal_senha = '';
$valores = [
    'nome' => '',
    'email' => '',
    'telefone' => '',
    'genero' => '',
    'anoNascimento' => '',
    'cep' => '',
    'cidade' => '',
    'estado' => '',
    'endereco' => ''
];

// CSRF token
if(empty($_SESSION['csrf_token'])){
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Processa envio do formulário
if(isset($_POST['submit'])){
    if(!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']){
        die("Erro de segurança: token inválido.");
    }

    $valores['nome'] = substr(trim($_POST['nome']),0,100);
    $valores['email'] = substr(trim($_POST['email']),0,100);
    $valores['telefone'] = preg_replace('/[^0-9]/', '', $_POST['telefone']);
    $senha = trim($_POST['senha']);
    $valores['genero'] = $_POST['genero'];
    $valores['anoNascimento'] = $_POST['anoNascimento'];
    $valores['cep'] = preg_replace('/[^0-9]/', '', $_POST['cep']);
    $valores['cidade'] = $_POST['cidade'];
    $valores['estado'] = $_POST['estado'];
    $valores['endereco'] = substr(trim($_POST['endereco']),0,150);

    // Valida idade mínima
    if(strtotime($valores['anoNascimento']) > strtotime('-1 year')){
        $abrir_modal_senha = true;
        $mensagem_modal_senha = 'Você deve ter pelo menos 1 ano de idade.';
    }

    // Valida senha forte
    if(strlen($senha) < 8 || !preg_match('/[A-Za-z]/', $senha) || !preg_match('/[0-9]/', $senha)){
        $abrir_modal_senha = true;
        $mensagem_modal_senha = 'Senha deve ter mínimo 8 caracteres, incluindo letras e números.';
    }

    // Validação de e-mail
    if(!filter_var($valores['email'], FILTER_VALIDATE_EMAIL)){
        $abrir_modal_senha = true;
        $mensagem_modal_senha = 'E-mail inválido.';
    }

    if(!$abrir_modal_senha){
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

        $stmt_check = $conexao->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt_check->bind_param("s", $valores['email']);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if($result_check->num_rows > 0){
            $abrir_modal_email = true;
        } else {
            $stmt = $conexao->prepare("INSERT INTO usuarios (nome,email,telefone,senha,sexo,data_nasc,cep,cidade,estado,endereco)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                "ssssssssss",
                $valores['nome'],
                $valores['email'],
                $valores['telefone'],
                $senha_hash,
                $valores['genero'],
                $valores['anoNascimento'],
                $valores['cep'],
                $valores['cidade'],
                $valores['estado'],
                $valores['endereco']
            );
            $stmt->execute();
            header("Location: login.php?cadastro=sucesso");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro</title>
<style>
body {
    font-family: Arial, Helvetica, sans-serif;
    background: linear-gradient(to right, rgb(20,147,220), rgb(17,54,71));
    margin: 0;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
}
.telaCadastro {
    background-color: rgba(0,0,0,0.9);
    padding: 30px;
    border-radius: 15px;
    color:white;
    text-align:center;
    width:90%;
    max-width:400px;
    box-sizing:border-box;
}
input, select {
    padding:1em;
    border:none;
    outline:none;
    font-size:1em;
    width:100%;
    border-radius:8px;
    margin-bottom:15px;
    box-sizing:border-box;
}
.inputSubmit {
    background-color:dodgerblue;
    padding:1em;
    border:none;
    color:white;
    width:100%;
    border-radius:10px;
    font-size:1em;
    transition:0.3s;
}
.inputSubmit:hover {
    background-color:deepskyblue;
    cursor:pointer;
}
.alerta {
    background:red;
    color:white;
    padding:10px;
    margin-bottom:15px;
    border-radius:5px;
}
.cadastro-link {
    display:block;
    text-align:center;
    margin-top:15px;
    color:white;
    text-decoration:none;
}
.cadastro-link:hover {
    text-decoration:underline;
}

/* Responsivo */
@media(max-width:768px){
    .telaCadastro {
        padding:20px;
        width:95%;
    }
    input, select, .inputSubmit {
        padding:12px;
        font-size:0.9em;
    }
}
@media(max-width:480px){
    .telaCadastro {
        padding:15px;
    }
    input, select, .inputSubmit {
        padding:10px;
        font-size:0.85em;
    }
}
</style>
</head>
<body>
<div class="telaCadastro">
<h1>Cadastro</h1>

<?php if($abrir_modal_senha): ?>
    <div class="alerta"><?php echo $mensagem_modal_senha; ?></div>
<?php endif; ?>

<?php if($abrir_modal_email): ?>
    <div class="alerta">Este e-mail já está cadastrado!</div>
<?php endif; ?>

<form action="" method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="text" name="nome" placeholder="Nome completo" required>
    <input type="email" name="email" placeholder="E-mail" required>
    <input type="tel" name="telefone" placeholder="Telefone">
    <select name="genero" required>
        <option value="">Selecione o gênero</option>
        <option value="Masculino">Masculino</option>
        <option value="Feminino">Feminino</option>
        <option value="Outro">Outro</option>
    </select>
    <input type="date" name="anoNascimento" required>
    <input type="text" name="cep" placeholder="CEP">
    <input type="text" name="cidade" placeholder="Cidade">
    <input type="text" name="estado" placeholder="Estado">
    <input type="text" name="endereco" placeholder="Endereço">
    <input type="password" name="senha" placeholder="Senha" required>
    <input type="submit" name="submit" value="Cadastrar" class="inputSubmit">
</form>

<a href="login.php" class="cadastro-link">Voltar para login</a>
</div>
</body>
</html>
