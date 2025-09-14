<?php
$dbHost = 'localhost';
$dbUsername = 'root';
$dbPassword = 'Edsonops123@';
$dbName = 'formulario-login';
$dbPort = 3307; // altere para a porta do XAMPP

$conexao = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName, $dbPort);

if($conexao->connect_errno){
    die("Falha na conexão: " . $conexao->connect_error);
}
?>
