<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include_once('config.php');

// Verifica se é admin
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// Função para escapar HTML
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Marca admin atual como online
if (isset($_SESSION['id'])) {
    $stmt = $conexao->prepare("
        INSERT INTO online_users (user_id, ultima_atividade, ip)
        VALUES (?, NOW(), ?)
        ON DUPLICATE KEY UPDATE ultima_atividade = NOW(), ip = ?
    ");
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt->bind_param("iss", $_SESSION['id'], $ip, $ip);
    $stmt->execute();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel ADM</title>
<style>
body { margin:0; font-family:Arial, Helvetica, sans-serif; background:linear-gradient(to right, rgb(20,147,220), rgb(17,54,71)); color:white; }
.navbar { width:100%; background:rgba(0,0,0,0.3); display:flex; justify-content:space-between; align-items:center; padding:15px 25px; position:fixed; top:0;left:0; flex-wrap:wrap; z-index:1000; }
.navbar a { color:white; text-decoration:none; padding:6px 12px; border-radius:4px; }
.content { margin-top:120px; padding:0 15px; }
table { width:100%; border-collapse:collapse; margin-top:20px; color:white; background-color:rgba(0,0,0,0.3); border-radius:6px; overflow:hidden; }
table th, table td { border:1px solid rgba(255,255,255,0.2); padding:8px; text-align:left; }
table th { background-color:rgba(0,0,0,0.5); }
.status-online::before { content:"🟢 "; }
.status-offline::before { content:"🔴 "; }
.status-bloqueado::before { content:"⛔ "; }
.filter-input { margin-bottom:10px; padding:8px; width:250px; border-radius:5px; border:none; }
#totais { margin-top:15px; font-weight:bold; }
button.logout-user, button.details-btn, button.toggle-btn { padding:5px 10px; border:none; border-radius:4px; cursor:pointer; }
button.logout-user { background:red; color:white; }
button.details-btn { background:orange; color:white; margin-left:5px; }
button.toggle-btn { background:purple; color:white; margin-left:5px; }
tr { transition: all 0.3s ease; }

/* Modal */
.modal { display:none; position:fixed; top:0;left:0;width:100%;height:100%; background:rgba(0,0,0,0.7); justify-content:center; align-items:center; z-index:10000; }
.modal-content { background:#fff; color:#000; padding:20px; border-radius:10px; width:90%; max-width:400px; }
.modal-content h2 { margin-top:0; }
.modal-content button { margin-top:10px; padding:5px 10px; background:#333; color:white; border:none; border-radius:4px; cursor:pointer; }

/* Cards mobile */
@media(max-width:600px){
    table, thead, tbody, th, td, tr { display:block; }
    thead { display:none; }
    tr { margin-bottom:15px; background:rgba(0,0,0,0.4); padding:10px; border-radius:8px; }
    td { border:none; padding:5px 0; }
    td::before { font-weight:bold; display:block; color:#ddd; }
    td:nth-child(1)::before{content:"ID";}
    td:nth-child(2)::before{content:"Nome";}
    td:nth-child(3)::before{content:"E-mail";}
    td:nth-child(4)::before{content:"Cidade";}
    td:nth-child(5)::before{content:"Estado";}
    td:nth-child(6)::before{content:"Status";}
    td:nth-child(7)::before{content:"Último acesso";}
    td:nth-child(8)::before{content:"IP";}
    td:nth-child(9)::before{content:"Ação";}
}
</style>
</head>
<body>

<div class="navbar">
    <div class="logo">Painel ADM - Bem-vindo, <?php echo e($_SESSION['nome']); ?>!</div>
    <a href="logout.php">Sair</a>
</div>

<div class="content">
<h1>Usuários cadastrados</h1>
<input type="text" id="filtro" class="filter-input" placeholder="Filtrar por nome, e-mail ou cidade">
<div id="totais">Online: 0 | Offline: 0</div>
<table>
<thead>
<tr>
<th>ID</th><th>Nome</th><th>E-mail</th><th>Cidade</th><th>Estado</th>
<th>Status</th><th>Último acesso</th><th>IP</th><th>Ação</th>
</tr>
</thead>
<tbody id="usuariosOnline"></tbody>
</table>
</div>

<!-- Modal -->
<div class="modal" id="modal">
  <div class="modal-content" id="modalContent">
    <h2>Detalhes do Usuário</h2>
    <div id="modalBody"></div>
    <button onclick="closeModal()">Fechar</button>
  </div>
</div>

<script>
function closeModal() { document.getElementById('modal').style.display = 'none'; }
document.getElementById('modal').addEventListener('click', e => { if(e.target.id === 'modal') closeModal(); });

function atualizarOnline() {
    fetch('online.php')
    .then(res => res.json())
    .then(data => {
        const tbody = document.getElementById('usuariosOnline');
        tbody.innerHTML = '';
        let onlineCount = 0, offlineCount = 0;

        data.forEach(u => {
            const tr = document.createElement('tr');
            const ultima = u.ultima_atividade ? new Date(u.ultima_atividade) : null;
            const agora = new Date();
            const diffMin = ultima ? (agora - ultima)/60000 : 9999;
            const status = (u.status_conta === 'bloqueado') ? 'Bloqueado' : (diffMin <= 5 ? 'Online' : 'Offline');
            if(status === 'Online') onlineCount++; else offlineCount++;

            tr.innerHTML = `
                <td>${u.id}</td>
                <td>${u.nome}</td>
                <td>${u.email}</td>
                <td>${u.cidade}</td>
                <td>${u.estado}</td>
                <td class="status-${status.toLowerCase()}">${status}</td>
                <td>${ultima ? timeAgo(ultima) : '-'}</td>
                <td>${u.ip || '-'}</td>
                <td>
                    ${status === 'Online' ? '<button class="logout-user" onclick="logoutUser('+u.id+')">Forçar logout</button>' : ''}
                    <button class="toggle-btn" onclick="toggleUser(${u.id}, '${u.status_conta}')">
                        ${u.status_conta === 'ativo' ? 'Bloquear' : 'Desbloquear'}
                    </button>
                    <button class="details-btn" onclick="showDetails(${JSON.stringify(u).replace(/"/g,'&quot;')})">👁️ Detalhes</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        document.getElementById('totais').textContent = `Online: ${onlineCount} | Offline: ${offlineCount}`;
    });
}

function timeAgo(date) {
    const now = new Date();
    const diff = Math.floor((now - date)/1000);
    if(diff < 60) return 'há poucos segundos';
    if(diff < 3600) return `há ${Math.floor(diff/60)} minutos`;
    if(diff < 86400) return `há ${Math.floor(diff/3600)} horas`;
    return `há ${Math.floor(diff/86400)} dias`;
}

function logoutUser(id){
    if(confirm("Deseja realmente desconectar este usuário?")){
        fetch('logout_user.php?id='+id)
        .then(res => res.json())
        .then(data => { 
            alert(data.msg); 
            atualizarOnline(); 
        })
        .catch(err => console.error(err));
    }
}

function showDetails(user){
    const ultima = user.ultima_atividade ? new Date(user.ultima_atividade) : null;
    const agora = new Date();
    const diffMin = ultima ? (agora - ultima)/60000 : 9999;
    const status = (user.status_conta === 'bloqueado') ? 'Bloqueado' : (diffMin <= 5 ? 'Online' : 'Offline');

    document.getElementById('modalBody').innerHTML = `
        <p><b>ID:</b> ${user.id}</p>
        <p><b>Nome:</b> ${user.nome}</p>
        <p><b>Email:</b> ${user.email}</p>
        <p><b>Cidade:</b> ${user.cidade}</p>
        <p><b>Estado:</b> ${user.estado}</p>
        <p><b>Status:</b> ${status}</p>
        <p><b>Último acesso:</b> ${user.ultima_atividade || '-'}</p>
        <p><b>IP:</b> ${user.ip || '-'}</p>
        <h3>Últimas Ações:</h3>
        <ul id="userLogs"><li>Carregando...</li></ul>
    `;
    document.getElementById('modal').style.display = 'flex';

    fetch(`user_logs.php?id=${user.id}`)
    .then(res => res.json())
    .then(logs => {
        const ul = document.getElementById('userLogs');
        ul.innerHTML = '';
        if(logs.length === 0){
            ul.innerHTML = '<li>Nenhum log encontrado</li>';
        } else {
            logs.forEach(log => {
                const li = document.createElement('li');
                li.textContent = `[${log.data_hora}] ${log.acao} (IP: ${log.ip})`;
                ul.appendChild(li);
            });
        }
    })
    .catch(err => {
        const ul = document.getElementById('userLogs');
        ul.innerHTML = '<li>Erro ao carregar logs</li>';
        console.error(err);
    });
}

function toggleUser(id, status){
    if(confirm(`Deseja realmente ${status === 'ativo' ? 'bloquear' : 'desbloquear'} este usuário?`)){
        fetch(`toggle_user.php?id=${id}`)
        .then(res => res.json())
        .then(data => {
            alert(data.msg);
            atualizarOnline();
        })
        .catch(err => console.error(err));
    }
}

// Filtro
document.getElementById('filtro').addEventListener('input', function(){
    const filtro = this.value.toLowerCase();
    document.querySelectorAll('#usuariosOnline tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(filtro) ? '' : 'none';
    });
});

setInterval(atualizarOnline, 10000);
atualizarOnline();
setInterval(() => fetch('heartbeat.php'), 60000);
</script>
</body>
</html>
