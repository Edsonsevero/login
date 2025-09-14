<?php
// Ativa exibição de erros para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include_once('config.php');

// Verifica se o usuário está logado e é admin
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
    echo json_encode([]);
    exit();
}

// Verifica se o ID foi enviado
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([]);
    exit();
}

$userId = (int) $_GET['id'];

// Busca logs do usuário
$stmt = $conexao->prepare("SELECT acao, ip, data_hora FROM user_logs WHERE user_id = ? ORDER BY data_hora DESC LIMIT 20");
if (!$stmt) {
    echo json_encode(['error' => 'Erro ao preparar consulta']);
    exit();
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}

echo json_encode($logs);
?>
