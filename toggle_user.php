<?php
session_start();
include_once('config.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'msg' => 'Acesso negado']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'msg' => 'ID do usuário não fornecido']);
    exit();
}

$id = intval($_GET['id']);

// Pega status atual
$stmt = $conexao->prepare("SELECT status_conta FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if($result->num_rows === 0){
    echo json_encode(['success' => false, 'msg' => 'Usuário não encontrado']);
    exit();
}

$user = $result->fetch_assoc();
$novo_status = ($user['status_conta'] === 'ativo') ? 'bloqueado' : 'ativo';

// Atualiza status
$stmt = $conexao->prepare("UPDATE usuarios SET status_conta = ? WHERE id = ?");
$stmt->bind_param("si", $novo_status, $id);
$stmt->execute();

// Se usuário estiver online, remove da tabela online_users para expulsar
if($novo_status === 'bloqueado'){
    $stmt = $conexao->prepare("DELETE FROM online_users WHERE user_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

echo json_encode(['success' => true, 'msg' => "Usuário agora está $novo_status"]);
?>
