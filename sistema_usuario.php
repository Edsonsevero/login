<?php
session_start();
include_once('config.php');

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'usuario'){
    header('Location: login.php');
    exit();
}

// Atualiza tabela online_users
if(isset($_SESSION['id'])){
    $stmt = $conexao->prepare("
        INSERT INTO online_users (user_id, ultima_atividade, ip)
        VALUES (?, NOW(), ?)
        ON DUPLICATE KEY UPDATE ultima_atividade = NOW(), ip = ?
    ");
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt->bind_param("iss", $_SESSION['id'], $ip, $ip);
    $stmt->execute();
}

// Adicionar tarefa
if(isset($_POST['add_tarefa']) && !empty($_POST['descricao'])){
    $stmt = $conexao->prepare("INSERT INTO tarefas (user_id, descricao) VALUES (?, ?)");
    $stmt->bind_param("is", $_SESSION['id'], $_POST['descricao']);
    $stmt->execute();
}

// Marcar tarefa como concluída
if(isset($_GET['concluir'])){
    $tarefa_id = intval($_GET['concluir']);
    $stmt = $conexao->prepare("UPDATE tarefas SET concluida = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $tarefa_id, $_SESSION['id']);
    $stmt->execute();
}

// Buscar tarefas do usuário
$stmt = $conexao->prepare("SELECT id, descricao, concluida FROM tarefas WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['id']);
$stmt->execute();
$result = $stmt->get_result();
$tarefas = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sistema Usuário</title>
<style>
/* Reset básico */
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background-color: #f4f4f9;
    color: #333;
    padding: 20px;
}

/* Container central */
.container {
    max-width: 700px;
    margin: auto;
    padding: 10px;
}

/* Títulos */
h1, h2 {
    color: #444;
    margin-bottom: 15px;
    word-break: break-word;
}

/* Links */
a {
    text-decoration: none;
    color: #007BFF;
}

a:hover {
    text-decoration: underline;
}

/* Formulário */
form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
}

input[type="text"] {
    flex: 1 1 200px;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    min-width: 0;
}

button {
    padding: 10px 20px;
    background-color: #007BFF;
    color: #fff;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

button:hover {
    background-color: #0056b3;
}

/* Lista de tarefas */
ul {
    list-style-type: none;
    padding-left: 0;
}

li {
    background-color: #fff;
    margin-bottom: 10px;
    padding: 12px 15px;
    border-radius: 5px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    word-break: break-word;
}

li span {
    font-weight: bold;
    color: green;
}

/* Responsividade */
@media (max-width: 600px) {
    form {
        flex-direction: column;
    }

    input[type="text"] {
        width: 100%;
    }

    button {
        width: 100%;
    }

    li {
        flex-direction: column;
        align-items: flex-start;
    }

    li a, li span {
        margin-top: 5px;
    }
}

/* Modal de bloqueio elegante */
.blocked-msg {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 9999;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}

.blocked-msg.show {
    opacity: 1;
    pointer-events: auto;
}

.blocked-content {
    background-color: #fff;
    color: #333;
    padding: 20px 30px;
    border-radius: 12px;
    text-align: center;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    animation: slideDown 0.4s ease-out;
    font-family: 'Arial', sans-serif;
}

.blocked-content p {
    font-size: 16px;
    margin-bottom: 20px;
    word-break: break-word;
}

.blocked-content button {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    background-color: #ff4d4d;
    color: #fff;
    font-weight: bold;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.blocked-content button:hover {
    background-color: #e60000;
}

@keyframes slideDown {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
</style>
</head>
<body>
<div id="blockedMessage" class="blocked-msg">
    <div class="blocked-content">
        <p id="blockedText"></p>
        <button onclick="closeBlockedMessage()">Fechar</button>
    </div>
</div>

<div class="container">
    <h1>Bem-vindo, <?php echo htmlspecialchars($_SESSION['nome']); ?>!</h1>
    <p>Status: Online</p>
    <a href="logout.php">Sair</a>

    <h2>Minhas Tarefas</h2>
    <form method="post">
        <input type="text" name="descricao" placeholder="Nova tarefa" required>
        <button type="submit" name="add_tarefa">Adicionar</button>
    </form>

    <ul>
    <?php foreach($tarefas as $tarefa): ?>
        <li>
            <?php echo htmlspecialchars($tarefa['descricao']); ?> 
            <?php if(!$tarefa['concluida']): ?>
                <a href="?concluir=<?php echo $tarefa['id']; ?>">[Concluir]</a>
            <?php else: ?>
                <span>[Concluída]</span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</div>

<script>
function showBlockedMessage(msg){
    const div = document.getElementById('blockedMessage');
    const text = document.getElementById('blockedText');
    text.textContent = msg;
    div.classList.add('show');
}

function closeBlockedMessage(){
    const div = document.getElementById('blockedMessage');
    div.classList.remove('show');
}

// Heartbeat para logout forçado
setInterval(() => {
    fetch('heartbeat.php')
    .then(res => res.json())
    .then(data => {
        if(data.status === 'forced_logout'){
            alert('Você foi desconectado pelo administrador!');
            window.location.href = 'login.php';
        }
    });
}, 15000);

// Heartbeat para usuários bloqueados
setInterval(() => {
    fetch('heartbeat.php')
    .then(res => res.json())
    .then(data => {
        if(data.status === 'blocked_or_forced'){
            showBlockedMessage(data.msg);
            setTimeout(() => { window.location.href = 'login.php'; }, 5000);
        }
    })
    .catch(err => console.error('Erro no heartbeat:', err));
}, 15000);
</script>
</body>
</html>
