<?php
session_start();
include_once('config.php');

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'usuario'){
    header('Location: login.php');
    exit();
}

if(isset($_GET['id'])){
    $arquivo_id = intval($_GET['id']);
    
    $stmt = $conexao->prepare("
        SELECT nome_arquivo, nome_original, tipo 
        FROM arquivos_usuarios 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $arquivo_id, $_SESSION['id']);
    $stmt->execute();
    $stmt->bind_result($nome_arquivo, $nome_original, $tipo);
    
    if($stmt->fetch()){
        $caminho = 'uploads/arquivos/' . $nome_arquivo;
        
        if(file_exists($caminho)){
            header('Content-Type: ' . $tipo);
            header('Content-Disposition: attachment; filename="' . $nome_original . '"');
            header('Content-Length: ' . filesize($caminho));
            readfile($caminho);
            exit;
        }
    }
    
    header('Location: sistema_usuario.php');
    exit;
}
?>