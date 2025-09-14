<?php
session_start();
include_once('config.php');

// Verifica se é admin
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'msg' => 'Acesso negado']));
}

if(!isset($_GET['id'])) {
    exit(json_encode(['success' => false, 'msg' => 'ID do usuário não informado']));
}

$userId = intval($_GET['id']);

// Não permitir desconectar outro admin
$stmtCheck = $conexao->prepare("SELECT role FROM usuarios WHERE id = ?");
$stmtCheck->bind_param("i", $userId);
$stmtCheck->execute();
$result = $stmtCheck->get_result();
if($result->num_rows === 0) {
    exit(json_encode(['success' => false, 'msg' => 'Usuário não encontrado']));
}
$usuario = $result->fetch_assoc();
if(strtolower($usuario['role']) === 'admin') {
    exit(json_encode(['success' => false, 'msg' => 'Não é permitido desconectar um admin']));
}

// Seta forced_logout = 1
$stmtUpdate = $conexao->prepare("UPDATE usuarios SET forced_logout = 1 WHERE id = ?");
$stmtUpdate->bind_param("i", $userId);
$stmtUpdate->execute();

// Remove da tabela online_users
$stmtDel = $conexao->prepare("DELETE FROM online_users WHERE user_id = ?");
$stmtDel->bind_param("i", $userId);
$stmtDel->execute();

// Registra log
$ip = $_SERVER['REMOTE_ADDR'];
$acao = "Logout forçado pelo admin";
$stmtLog = $conexao->prepare("INSERT INTO user_logs (user_id, acao, ip) VALUES (?, ?, ?)");
$stmtLog->bind_param("iss", $userId, $acao, $ip);
$stmtLog->execute();

echo json_encode(['success' => true, 'msg' => 'Usuário desconectado com sucesso']);
?>
