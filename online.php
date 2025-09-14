<?php
session_start();
include_once('config.php');

$tempo_limite = 5; // minutos para considerar online

$query = $conexao->prepare("
    SELECT u.id, u.nome, u.email, u.cidade, u.estado, u.status_conta, o.ultima_atividade, o.ip
    FROM usuarios u
    LEFT JOIN online_users o ON u.id = o.user_id
    ORDER BY o.ultima_atividade DESC
");
$query->execute();
$result = $query->get_result();

$usuarios = [];
while($row = $result->fetch_assoc()){
    // Status baseado em bloqueio ou última atividade
    if($row['status_conta'] === 'bloqueado'){
        $status = 'Bloqueado';
    } else {
        $status = (isset($row['ultima_atividade']) && strtotime($row['ultima_atividade']) > strtotime("-{$tempo_limite} minutes")) ? 'Online' : 'Offline';
    }
    $row['status'] = $status;
    $usuarios[] = $row;
}

header('Content-Type: application/json');
echo json_encode($usuarios);
