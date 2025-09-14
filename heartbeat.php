<?php
session_start();
include_once('config.php');

// Verifica se o usuário está logado
if(!isset($_SESSION['id'])){
    echo json_encode(['status'=>'no_session']);
    exit();
}

// Pega dados do usuário
$stmt = $conexao->prepare("SELECT forced_logout, status_conta, role FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $_SESSION['id']);
$stmt->execute();
$result = $stmt->get_result();
$usuario = $result->fetch_assoc();

// Bloqueia/expulsa usuário normal se bloqueado ou com forced_logout
if($usuario && $_SESSION['role'] !== 'admin' && 
   ($usuario['forced_logout'] == 1 || $usuario['status_conta'] === 'bloqueado')) {
    
    // Limpa sessão
    session_unset();
    session_destroy();

    echo json_encode([
        'status' => 'blocked_or_forced',
        'msg' => 'Seu perfil está temporariamente bloqueado e em avaliação técnica.'
    ]);
    exit();
}

// Atualiza última atividade do usuário
$stmt2 = $conexao->prepare("
    INSERT INTO online_users (user_id, ultima_atividade, ip)
    VALUES (?, NOW(), ?)
    ON DUPLICATE KEY UPDATE ultima_atividade = NOW(), ip = ?
");
$ip = $_SERVER['REMOTE_ADDR'];
$stmt2->bind_param("iss", $_SESSION['id'], $ip, $ip);
$stmt2->execute();

// Opcional: registra log de heartbeat apenas a cada X minutos para não lotar tabela
// Aqui registramos sempre, mas em produção talvez queira otimizar
$stmt3 = $conexao->prepare("
    INSERT INTO user_logs (user_id, acao, ip, data_hora) 
    VALUES (?, 'Heartbeat', ?, NOW())
");
$stmt3->bind_param("is", $_SESSION['id'], $ip);
$stmt3->execute();

// Retorna status OK
echo json_encode(['status'=>'ok']);
?>
