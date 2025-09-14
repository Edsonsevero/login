<?php
session_start();
include_once('config.php');

if(isset($_SESSION['id'])){
    $stmtLog = $conexao->prepare("INSERT INTO user_logs (user_id, acao, ip) VALUES (?, 'Logout', ?)");
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmtLog->bind_param("is", $_SESSION['id'], $ip);
    $stmtLog->execute();
}

session_unset();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header('Location: login.php');
exit();
?>
