<?php
session_start();
include_once('config.php');

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'usuario'){
    header('Location: login.php');
    exit();
}

// Verificar e criar colunas necessárias se não existirem
$colunas_necessarias = ['bio', 'telefone', 'data_nascimento', 'genero', 'localizacao', 'website', 'tema_preferido'];
// =============================================
// SISTEMA DE NOTIFICAÇÕES
// =============================================

// Marcar notificação como lida
if(isset($_GET['marcar_lida'])) {
    $notificacao_id = intval($_GET['marcar_lida']);
    $stmt = $conexao->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notificacao_id, $_SESSION['id']);
    $stmt->execute();
    $stmt->close();
    
    // Redireciona para evitar reexecução
    header("Location: sistema_usuario.php");
    exit;
}

// Marcar todas como lidas
if(isset($_POST['marcar_todas_lidas'])) {
    $stmt = $conexao->prepare("UPDATE notificacoes SET lida = 1 WHERE user_id = ? AND lida = 0");
    $stmt->bind_param("i", $_SESSION['id']);
    $stmt->execute();
    $stmt->close();
    
    $sucesso_notificacao = "Todas as notificações foram marcadas como lidas!";
}

// Excluir notificação
if(isset($_GET['excluir_notificacao'])) {
    $notificacao_id = intval($_GET['excluir_notificacao']);
    $stmt = $conexao->prepare("DELETE FROM notificacoes WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notificacao_id, $_SESSION['id']);
    $stmt->execute();
    $stmt->close();
    
    header("Location: sistema_usuario.php");
    exit;
}

// Buscar notificações do usuário
$stmt = $conexao->prepare("
    SELECT id, titulo, mensagem, tipo, lida, data_criacao, link 
    FROM notificacoes 
    WHERE user_id = ? 
    ORDER BY lida ASC, data_criacao DESC 
    LIMIT 20
");
$stmt->bind_param("i", $_SESSION['id']);
$stmt->execute();
$result = $stmt->get_result();
$notificacoes = $result->fetch_all(MYSQLI_ASSOC);

// Contar notificações não lidas
$notificacoes_nao_lidas = 0;
foreach($notificacoes as $notificacao) {
    if(!$notificacao['lida']) {
        $notificacoes_nao_lidas++;
    }
}
foreach($colunas_necessarias as $coluna) {
    try {
        $result = $conexao->query("SHOW COLUMNS FROM usuarios LIKE '$coluna'");
        if($result->num_rows == 0) {
            switch($coluna) {
                case 'bio': $conexao->query("ALTER TABLE usuarios ADD COLUMN bio TEXT"); break;
                case 'telefone': $conexao->query("ALTER TABLE usuarios ADD COLUMN telefone VARCHAR(20)"); break;
                case 'data_nascimento': $conexao->query("ALTER TABLE usuarios ADD COLUMN data_nascimento DATE"); break;
                case 'genero': $conexao->query("ALTER TABLE usuarios ADD COLUMN genero ENUM('masculino','feminino','outro','prefiro_nao_dizer')"); break;
                case 'localizacao': $conexao->query("ALTER TABLE usuarios ADD COLUMN localizacao VARCHAR(100)"); break;
                case 'website': $conexao->query("ALTER TABLE usuarios ADD COLUMN website VARCHAR(255)"); break;
                case 'tema_preferido': $conexao->query("ALTER TABLE usuarios ADD COLUMN tema_preferido ENUM('claro','escuro','auto') DEFAULT 'auto'"); break;
            }
        }
    } catch (Exception $e) {
        // Coluna já existe ou erro na criação
    }
}

// Atualiza tabela online_users
if(isset($_SESSION['id'])){
    $stmt = $conexao->prepare("
        INSERT INTO online_users (user_id, ultima_atividade, ip, user_agent)
        VALUES (?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE ultima_atividade = NOW(), ip = ?, user_agent = ?
    ");
    $ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $stmt->bind_param("issss", $_SESSION['id'], $ip, $user_agent, $ip, $user_agent);
    $stmt->execute();
}
// Buscar informações completas do usuário
$stmt = $conexao->prepare("SELECT nome, email, foto_perfil, bio, telefone, data_nascimento, genero, localizacao, website, tema_preferido FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $_SESSION['id']);
$stmt->execute();
$result = $stmt->get_result();
$usuario = $result->fetch_assoc();
// =============================================
// SISTEMA DE CHAT ENTRE USUÁRIOS
// =============================================

// Enviar mensagem
if(isset($_POST['enviar_mensagem']) && !empty($_POST['mensagem']) && !empty($_POST['destinatario_id'])) {
    $destinatario_id = intval($_POST['destinatario_id']);
    $mensagem = trim($_POST['mensagem']);
    
    $stmt = $conexao->prepare("INSERT INTO mensagens_chat (remetente_id, destinatario_id, mensagem) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $_SESSION['id'], $destinatario_id, $mensagem);
    
    if($stmt->execute()) {
        $sucesso_chat = "Mensagem enviada com sucesso!";
        
        // Criar notificação para o destinatário
        $stmt_notif = $conexao->prepare("
            INSERT INTO notificacoes (user_id, titulo, mensagem, tipo, link) 
            VALUES (?, '💬 Nova Mensagem', 'Você recebeu uma nova mensagem de " . $usuario['nome'] . "', 'info', 'sistema_usuario.php')
        ");
        $stmt_notif->bind_param("i", $destinatario_id);
        $stmt_notif->execute();
        $stmt_notif->close();
    }
    $stmt->close();
}

// Marcar mensagens como lidas
if(isset($_GET['ler_mensagens'])) {
    $remetente_id = intval($_GET['ler_mensagens']);
    $stmt = $conexao->prepare("UPDATE mensagens_chat SET lida = 1 WHERE destinatario_id = ? AND remetente_id = ? AND lida = 0");
    $stmt->bind_param("ii", $_SESSION['id'], $remetente_id);
    $stmt->execute();
    $stmt->close();
}

// Buscar usuários para conversar (exceto o próprio usuário)
$stmt_usuarios = $conexao->prepare("SELECT id, nome, foto_perfil FROM usuarios WHERE id != ? ORDER BY nome");
$stmt_usuarios->bind_param("i", $_SESSION['id']);
$stmt_usuarios->execute();
$result_usuarios = $stmt_usuarios->get_result();
$usuarios_chat = $result_usuarios->fetch_all(MYSQLI_ASSOC);

// Buscar conversas recentes
$stmt_conversas = $conexao->prepare("
    SELECT 
        u.id as user_id,
        u.nome,
        u.foto_perfil,
        COUNT(m.id) as total_mensagens,
        SUM(CASE WHEN m.lida = 0 AND m.destinatario_id = ? THEN 1 ELSE 0 END) as nao_lidas,
        MAX(m.data_envio) as ultima_mensagem
    FROM usuarios u
    LEFT JOIN mensagens_chat m ON (
        (m.remetente_id = u.id AND m.destinatario_id = ?) OR 
        (m.destinatario_id = u.id AND m.remetente_id = ?)
    )
    WHERE u.id != ?
    GROUP BY u.id, u.nome, u.foto_perfil
    HAVING total_mensagens > 0
    ORDER BY ultima_mensagem DESC
");
$stmt_conversas->bind_param("iiii", $_SESSION['id'], $_SESSION['id'], $_SESSION['id'], $_SESSION['id']);
$stmt_conversas->execute();
$conversas = $stmt_conversas->get_result()->fetch_all(MYSQLI_ASSOC);

// Buscar mensagens de uma conversa específica
$chat_usuario = null;
$mensagens = [];
if(isset($_GET['chat_com'])) {
    $chat_usuario_id = intval($_GET['chat_com']);
    
    // Buscar informações do usuário
    $stmt_user = $conexao->prepare("SELECT id, nome, foto_perfil FROM usuarios WHERE id = ?");
    $stmt_user->bind_param("i", $chat_usuario_id);
    $stmt_user->execute();
    $chat_usuario = $stmt_user->get_result()->fetch_assoc();
    
    // Buscar mensagens da conversa
    $stmt_msg = $conexao->prepare("
        SELECT m.*, u.nome as remetente_nome, u.foto_perfil as remetente_foto
        FROM mensagens_chat m
        JOIN usuarios u ON m.remetente_id = u.id
        WHERE (m.remetente_id = ? AND m.destinatario_id = ?) OR (m.remetente_id = ? AND m.destinatario_id = ?)
        ORDER BY m.data_envio ASC
    ");
    $stmt_msg->bind_param("iiii", $_SESSION['id'], $chat_usuario_id, $chat_usuario_id, $_SESSION['id']);
    $stmt_msg->execute();
    $mensagens = $stmt_msg->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Marcar como lidas
    $stmt_lidas = $conexao->prepare("UPDATE mensagens_chat SET lida = 1 WHERE destinatario_id = ? AND remetente_id = ? AND lida = 0");
    $stmt_lidas->bind_param("ii", $_SESSION['id'], $chat_usuario_id);
    $stmt_lidas->execute();
    $stmt_lidas->close();
}

// Contar mensagens não lidas no total
$stmt_nao_lidas = $conexao->prepare("SELECT COUNT(*) as total FROM mensagens_chat WHERE destinatario_id = ? AND lida = 0");
$stmt_nao_lidas->bind_param("i", $_SESSION['id']);
$stmt_nao_lidas->execute();
$total_nao_lidas = $stmt_nao_lidas->get_result()->fetch_assoc()['total'];

// Upload de foto de perfil 
if(isset($_POST['update_profile']) && isset($_FILES['foto']) && $_FILES['foto']['error'] == 0){
    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
    $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'gif'];
    
    if(in_array($ext, $extensoes_permitidas)){
        $nomeFoto = 'perfil_' . $_SESSION['id'] . '_' . time() . '.' . $ext;
        if(!is_dir('uploads')){ 
            mkdir('uploads', 0755, true); 
        }
        $caminho = 'uploads/' . $nomeFoto;
        
        if(move_uploaded_file($_FILES['foto']['tmp_name'], $caminho)){
            // Remover foto anterior se existir
            $stmt = $conexao->prepare("SELECT foto_perfil FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['id']);
            $stmt->execute();
            $stmt->bind_result($foto_antiga);
            $stmt->fetch();
            $stmt->close();
            
            if($foto_antiga && file_exists($foto_antiga) && $foto_antiga != 'uploads/default.png'){
                unlink($foto_antiga);
            }
            
            $stmt = $conexao->prepare("UPDATE usuarios SET foto_perfil = ? WHERE id = ?");
            $stmt->bind_param("si", $caminho, $_SESSION['id']);
            if($stmt->execute()){
                $_SESSION['foto_perfil'] = $caminho;
                $sucesso_foto = "Foto de perfil atualizada com sucesso!";
            }
        } else {
            $erro_foto = "Erro ao fazer upload da foto.";
        }
    } else {
        $erro_foto = "Formato de arquivo não permitido. Use JPG, PNG ou GIF.";
    }
}

// Atualizar perfil
if(isset($_POST['update_profile'])){
    $novo_nome = trim($_POST['nome'] ?? '');
    $nova_senha = trim($_POST['senha'] ?? '');
    $nova_bio = trim($_POST['bio'] ?? '');
    $novo_email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $data_nascimento = trim($_POST['data_nascimento'] ?? '');
    $genero = trim($_POST['genero'] ?? '');
    $localizacao = trim($_POST['localizacao'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $tema_preferido = trim($_POST['tema_preferido'] ?? 'auto');

    // Validação da senha
    if(!empty($nova_senha)){
        if(strlen($nova_senha) < 8 || !preg_match('/[A-Z]/', $nova_senha) || !preg_match('/[0-9]/', $nova_senha) || !preg_match('/[\W]/', $nova_senha)){
            $erro_senha = "A senha deve ter pelo menos 8 caracteres, uma letra maiúscula, um número e um caractere especial.";
        } else {
            $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $stmt = $conexao->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            $stmt->bind_param("si", $senha_hash, $_SESSION['id']);
            $stmt->execute();
            $sucesso_senha = "Senha alterada com sucesso!";
        }
    }

    // Atualizar dados do perfil
    $campos = [];
    $valores = [];
    $tipos = "";
    
    if(!empty($novo_nome) && $novo_nome != $_SESSION['nome']){
        $campos[] = "nome = ?";
        $valores[] = $novo_nome;
        $tipos .= "s";
        $_SESSION['nome'] = $novo_nome;
    }
    
    if(!empty($novo_email) && filter_var($novo_email, FILTER_VALIDATE_EMAIL) && $novo_email != $_SESSION['email']){
        // Verificar se o email já existe
        $stmt = $conexao->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $novo_email, $_SESSION['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($result->num_rows == 0){
            $campos[] = "email = ?";
            $valores[] = $novo_email;
            $tipos .= "s";
            $_SESSION['email'] = $novo_email;
        } else {
            $erro_email = "Este email já está em uso por outro usuário.";
        }
    }
    
    // Atualizar campos adicionais
    $campos_adicionais = [
        'bio' => $nova_bio,
        'telefone' => $telefone,
        'data_nascimento' => $data_nascimento,
        'genero' => $genero,
        'localizacao' => $localizacao,
        'website' => $website,
        'tema_preferido' => $tema_preferido
    ];
    
    foreach($campos_adicionais as $campo => $valor){
        if(!empty($valor) || $campo == 'tema_preferido'){
            $campos[] = "$campo = ?";
            $valores[] = $valor;
            $tipos .= "s";
            $_SESSION[$campo] = $valor;
        }
    }
    
    // Executar atualização se houver campos para atualizar
    if(!empty($campos)){
        $sql = "UPDATE usuarios SET " . implode(", ", $campos) . " WHERE id = ?";
        $valores[] = $_SESSION['id'];
        $tipos .= "i";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($tipos, ...$valores);
        if($stmt->execute()){
            $sucesso_perfil = "Perfil atualizado com sucesso!";
        }
    }
}
// Upload de arquivos - MODIFICADO
if(isset($_POST['upload_arquivo']) && isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] == 0){
    $arquivo = $_FILES['arquivo'];
    $descricao = trim($_POST['descricao_arquivo'] ?? '');
    $tarefa_id = isset($_POST['tarefa_id']) ? intval($_POST['tarefa_id']) : null;
    
    // Validação básica
    if($arquivo['size'] > 10 * 1024 * 1024) {
        $erro_arquivo = "Arquivo muito grande. Máximo 10MB.";
    } else {
        // Gera nome único
        $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $nomeArquivo = 'arquivo_' . $_SESSION['id'] . '_' . time() . '.' . $ext;
        
        if(!is_dir('uploads/arquivos')){ 
            mkdir('uploads/arquivos', 0755, true); 
        }
        
        $caminho = 'uploads/arquivos/' . $nomeArquivo;
        
        if(move_uploaded_file($arquivo['tmp_name'], $caminho)){
            // Salva no banco
            $stmt = $conexao->prepare("
                INSERT INTO arquivos_usuarios (user_id, nome_arquivo, nome_original, tipo, tamanho, descricao) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssis", $_SESSION['id'], $nomeArquivo, $arquivo['name'], $arquivo['type'], $arquivo['size'], $descricao);
            
            if($stmt->execute()){
                $arquivo_id = $stmt->insert_id;
                
                // Se foi enviado com uma tarefa, associa o arquivo à tarefa
                if($tarefa_id){
                    $stmt2 = $conexao->prepare("UPDATE tarefas SET arquivo_id = ? WHERE id = ? AND user_id = ?");
                    $stmt2->bind_param("iii", $arquivo_id, $tarefa_id, $_SESSION['id']);
                    $stmt2->execute();
                    $stmt2->close();
                }
                
                $sucesso_arquivo = "Arquivo enviado com sucesso!" . ($tarefa_id ? " Vinculado à tarefa." : "");
            }
            $stmt->close();
        } else {
            $erro_arquivo = "Erro ao fazer upload do arquivo.";
        }
    }
}

// Excluir arquivo - CORRIGIDO
if(isset($_GET['excluir_arquivo'])){
    $arquivo_id = intval($_GET['excluir_arquivo']);
    
    // Busca informações do arquivo
    $stmt = $conexao->prepare("SELECT nome_arquivo FROM arquivos_usuarios WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $arquivo_id, $_SESSION['id']);
    $stmt->execute();
    $stmt->store_result(); // ← ADICIONE ESTA LINHA
    $stmt->bind_result($nome_arquivo);
    
    if($stmt->fetch()){
        $caminho_arquivo = 'uploads/arquivos/' . $nome_arquivo;
        
        // Fecha o statement ANTES de executar o próximo
        $stmt->close();
        
        // Remove do banco
        $stmt2 = $conexao->prepare("DELETE FROM arquivos_usuarios WHERE id = ? AND user_id = ?");
        $stmt2->bind_param("ii", $arquivo_id, $_SESSION['id']);
        $stmt2->execute();
        $stmt2->close();
        
        // Remove arquivo físico
        if(file_exists($caminho_arquivo)){
            unlink($caminho_arquivo);
        }
        
        // Redireciona para evitar reexecução
        header("Location: sistema_usuario.php");
        exit;
    } else {
        $stmt->close();
    }
}

// Buscar arquivos do usuário
$stmt = $conexao->prepare("
    SELECT id, nome_arquivo, nome_original, tipo, tamanho, descricao, data_upload 
    FROM arquivos_usuarios 
    WHERE user_id = ? 
    ORDER BY data_upload DESC
");
$stmt->bind_param("i", $_SESSION['id']);
$stmt->execute();
$result = $stmt->get_result();
$arquivos = $result->fetch_all(MYSQLI_ASSOC);

// Funções auxiliares
function getFileIcon($tipo) {
    if(strpos($tipo, 'image/') !== false) return '🖼️';
    if(strpos($tipo, 'video/') !== false) return '🎬';
    if(strpos($tipo, 'audio/') !== false) return '🎵';
    if(strpos($tipo, 'pdf') !== false) return '📄';
    if(strpos($tipo, 'word') !== false) return '📝';
    if(strpos($tipo, 'excel') !== false) return '📊';
    if(strpos($tipo, 'zip') !== false || strpos($tipo, 'rar') !== false) return '📦';
    return '📁';
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
// Adicionar tarefa
if(isset($_POST['add_tarefa']) && !empty($_POST['descricao'])){
    $prioridade = $_POST['prioridade'] ?? 'media';
    $categoria = $_POST['categoria'] ?? 'geral';
    
    $stmt = $conexao->prepare("INSERT INTO tarefas (user_id, descricao, prioridade, categoria) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $_SESSION['id'], $_POST['descricao'], $prioridade, $categoria);
    $stmt->execute();
}

// Marcar tarefa como concluída
if(isset($_GET['concluir'])){
    $tarefa_id = intval($_GET['concluir']);
    $stmt = $conexao->prepare("UPDATE tarefas SET concluida = 1, data_conclusao = NOW() WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $tarefa_id, $_SESSION['id']);
    $stmt->execute();
}

// Excluir tarefa
if(isset($_GET['excluir'])){
    $tarefa_id = intval($_GET['excluir']);
    $stmt = $conexao->prepare("DELETE FROM tarefas WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $tarefa_id, $_SESSION['id']);
    $stmt->execute();
}

// Buscar informações completas do usuário
//$stmt = $conexao->prepare("SELECT nome, email, foto_perfil, bio, telefone, data_nascimento, genero, localizacao, website, tema_preferido FROM usuarios WHERE id = ?");
//$stmt->bind_param("i", $_SESSION['id']);
//$stmt->execute();
//$result = $stmt->get_result();
//$usuario = $result->fetch_assoc();

// Buscar tarefas do usuário com ordenação
$ordenacao = $_GET['ordenar'] ?? 'data_criacao';
$filtro_categoria = $_GET['categoria'] ?? 'todas';
$filtro_status = $_GET['status'] ?? 'todas';

// Buscar tarefas do usuário com ordenação - MODIFICADO
$sql_tarefas = "SELECT t.id, t.descricao, t.concluida, t.prioridade, t.categoria, t.data_criacao, t.data_conclusao, 
                t.arquivo_id, a.nome_original, a.tipo, a.descricao as descricao_arquivo
                FROM tarefas t 
                LEFT JOIN arquivos_usuarios a ON t.arquivo_id = a.id 
                WHERE t.user_id = ?";
$params = [$_SESSION['id']];
$tipos = "i";

if($filtro_categoria != 'todas'){
    $sql_tarefas .= " AND categoria = ?";
    $params[] = $filtro_categoria;
    $tipos .= "s";
}

if($filtro_status != 'todas'){
    if($filtro_status == 'concluidas'){
        $sql_tarefas .= " AND concluida = 1";
    } else {
        $sql_tarefas .= " AND concluida = 0";
    }
}

switch($ordenacao){
    case 'prioridade': $sql_tarefas .= " ORDER BY FIELD(prioridade, 'alta', 'media', 'baixa')"; break;
    case 'categoria': $sql_tarefas .= " ORDER BY categoria"; break;
    case 'concluidas': $sql_tarefas .= " ORDER BY concluida DESC"; break;
    default: $sql_tarefas .= " ORDER BY data_criacao DESC"; break;
}

$stmt = $conexao->prepare($sql_tarefas);
if(count($params) > 1){
    $stmt->bind_param($tipos, ...$params);
} else {
    $stmt->bind_param($tipos, $params[0]);
}
$stmt->execute();
$result = $stmt->get_result();
$tarefas = $result->fetch_all(MYSQLI_ASSOC);

// Estatísticas
$stmt = $conexao->prepare("SELECT 
    COUNT(*) as total,
    SUM(concluida = 1) as concluidas,
    SUM(prioridade = 'alta') as alta_prioridade,
    SUM(prioridade = 'media') as media_prioridade,
    SUM(prioridade = 'baixa') as baixa_prioridade
    FROM tarefas WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['id']);
$stmt->execute();
$estatisticas = $stmt->get_result()->fetch_assoc();

// Aplicar tema preferido
if(isset($usuario['tema_preferido']) && $usuario['tema_preferido'] != 'auto'){
    $_SESSION['tema'] = $usuario['tema_preferido'];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Meu Perfil - Sistema</title>
<style>
:root {
    --primary-color: #007BFF;
    --secondary-color: #6c757d;
    --success-color: #28a745;
    --danger-color: #dc3545;
    --warning-color: #ffc107;
    --info-color: #17a2b8;
    --light-color: #f8f9fa;
    --dark-color: #343a40;
    --bg-color: #f4f4f9;
    --text-color: #333;
    --card-bg: #fff;
    --border-color: #dee2e6;
    --shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.dark-theme {
    --bg-color: #1a1a2e;
    --text-color: #e9ecef;
    --card-bg: #16213e;
    --border-color: #2d3748;
    --shadow: 0 2px 10px rgba(0,0,0,0.3);
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: var(--bg-color);
    color: var(--text-color);
    line-height: 1.6;
    transition: all 0.3s ease;
    padding: 20px;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 15px;
}

/* Header e Navegação */
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border-color);
}

.user-welcome h1 {
    font-size: 1.8rem;
    color: var(--primary-color);
    margin-bottom: 5px;
}

.user-stats {
    display: flex;
    gap: 15px;
    font-size: 0.9rem;
}

.stat-item {
    background: var(--card-bg);
    padding: 8px 15px;
    border-radius: 20px;
    box-shadow: var(--shadow);
}

/* Grid Principal */
.main-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 30px;
}

/* Card de Perfil */
.profile-card {
    background: var(--card-bg);
    border-radius: 15px;
    padding: 25px;
    box-shadow: var(--shadow);
    text-align: center;
    position: sticky;
    top: 20px;
}

.profile-image {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--primary-color);
    margin: 0 auto 15px;
    cursor: pointer;
    transition: transform 0.3s ease;
}

.profile-image:hover {
    transform: scale(1.05);
}

.profile-name {
    font-size: 1.4rem;
    margin-bottom: 5px;
    color: var(--primary-color);
}

.profile-bio {
    color: var(--secondary-color);
    margin-bottom: 15px;
    font-style: italic;
}

.profile-details {
    text-align: left;
    margin: 20px 0;
}

.detail-item {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    font-size: 0.9rem;
}

.detail-item i {
    width: 20px;
    margin-right: 10px;
    color: var(--primary-color);
}

.profile-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.btn {
    padding: 10px 15px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s ease;
    text-decoration: none;
    text-align: center;
    display: inline-block;
}

.btn-primary {
    background: var(--primary-color);
    color: white;
}

.btn-secondary {
    background: var(--secondary-color);
    color: white;
}

.btn-success {
    background: var(--success-color);
    color: white;
}

.btn-danger {
    background: var(--danger-color);
    color: white;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

/* Conteúdo Principal */
.main-content {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

/* Card de Estatísticas */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--card-bg);
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    box-shadow: var(--shadow);
}

.stat-number {
    font-size: 2rem;
    font-weight: bold;
    color: var(--primary-color);
    display: block;
}

.stat-label {
    font-size: 0.9rem;
    color: var(--secondary-color);
}

/* Filtros e Ordenação */
.filters {
    background: var(--card-bg);
    padding: 20px;
    border-radius: 10px;
    box-shadow: var(--shadow);
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-color);
}

/* Lista de Tarefas */
.tasks-section {
    background: var(--card-bg);
    border-radius: 10px;
    padding: 25px;
    box-shadow: var(--shadow);
}

.section-title {
    font-size: 1.4rem;
    margin-bottom: 20px;
    color: var(--primary-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.task-form {
    display: flex;
    gap: 10px;
    margin-bottom: 25px;
    flex-wrap: wrap;
}

.task-input {
    flex: 1;
    min-width: 200px;
    padding: 10px 15px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-color);
}

.task-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.task-item {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
}

.task-item:hover {
    transform: translateX(5px);
    box-shadow: var(--shadow);
}

.task-item.concluida {
    opacity: 0.7;
    background: rgba(40, 167, 69, 0.1);
}

.task-info {
    flex: 1;
}

.task-desc {
    font-weight: 500;
    margin-bottom: 5px;
}

.task-meta {
    display: flex;
    gap: 15px;
    font-size: 0.8rem;
    color: var(--secondary-color);
}

.task-priority {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: bold;
}

.priority-alta { background: var(--danger-color); color: white; }
.priority-media { background: var(--warning-color); color: black; }
.priority-baixa { background: var(--success-color); color: white; }

.task-actions {
    display: flex;
    gap: 8px;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 0.8rem;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    justify-content: center;
    align-items: center;
    z-index: 1000;
    backdrop-filter: blur(5px);
}

.modal-content {
    background: var(--card-bg);
    border-radius: 15px;
    padding: 30px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.close-modal {
    position: absolute;
    top: 15px;
    right: 20px;
    font-size: 24px;
    cursor: pointer;
    color: var(--secondary-color);
    transition: color 0.3s ease;
}

.close-modal:hover {
    color: var(--danger-color);
}

/* Abas */
.modal-tabs {
    display: flex;
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 25px;
}

.modal-tab {
    padding: 12px 20px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-color);
    transition: all 0.3s ease;
    border-bottom: 2px solid transparent;
}

.modal-tab.active {
    border-bottom-color: var(--primary-color);
    color: var(--primary-color);
    font-weight: bold;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Formulários */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text-color);
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 12px 15px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--card-bg);
    color: var(--text-color);
    transition: border-color 0.3s ease;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: var(--primary-color);
}

.form-textarea {
    min-height: 100px;
    resize: vertical;
}

/* Upload de Foto */
.photo-upload {
    text-align: center;
    padding: 20px;
    border: 2px dashed var(--border-color);
    border-radius: 10px;
    margin-bottom: 20px;
    cursor: pointer;
    transition: border-color 0.3s ease;
}

.photo-upload:hover {
    border-color: var(--primary-color);
}

.photo-preview {
    max-width: 200px;
    max-height: 200px;
    border-radius: 10px;
    margin: 15px auto;
    display: none;
}

/* Força da Senha */
.password-strength {
    height: 6px;
    border-radius: 3px;
    margin-top: 8px;
    transition: all 0.3s ease;
}

.strength-weak { background: var(--danger-color); width: 30%; }
.strength-medium { background: var(--warning-color); width: 60%; }
.strength-strong { background: var(--success-color); width: 100%; }

/* Alertas */
.alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid;
}

.alert-success {
    background: rgba(40, 167, 69, 0.1);
    border-left-color: var(--success-color);
    color: var(--success-color);
}

.alert-error {
    background: rgba(220, 53, 69, 0.1);
    border-left-color: var(--danger-color);
    color: var(--danger-color);
}

/* Responsividade */
@media (max-width: 768px) {
    .main-grid {
        grid-template-columns: 1fr;
    }
    
    .profile-card {
        position: static;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .task-form {
        flex-direction: column;
    }
    
    .task-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .task-actions {
        align-self: flex-end;
    }
}
/* NOVOS ESTILOS PARA NOTIFICAÇÕES */
.notification-badge {
    background: var(--danger-color);
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: -5px;
    right: -5px;
}

.notification-icon {
    position: relative;
    cursor: pointer;
    font-size: 1.5rem;
    padding: 10px;
}

.notification-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    width: 400px;
    max-width: 90vw;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: var(--shadow);
    z-index: 1000;
    display: none;
}

.notification-dropdown.show {
    display: block;
}

.notification-header {
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-list {
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.3s ease;
}

.notification-item:hover {
    background: rgba(0, 123, 255, 0.1);
}

.notification-item.unread {
    background: rgba(0, 123, 255, 0.05);
    border-left: 3px solid var(--primary-color);
}

.notification-title {
    font-weight: bold;
    margin-bottom: 5px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.notification-message {
    color: var(--secondary-color);
    font-size: 0.9rem;
    margin-bottom: 5px;
}

.notification-time {
    font-size: 0.8rem;
    color: var(--secondary-color);
}

.notification-actions {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

.notification-type {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: bold;
    margin-left: 10px;
}

.type-info { background: var(--info-color); color: white; }
.type-sucesso { background: var(--success-color); color: white; }
.type-alerta { background: var(--warning-color); color: black; }
.type-erro { background: var(--danger-color); color: white; }

.notification-footer {
    padding: 15px;
    border-top: 1px solid var(--border-color);
    text-align: center;
}

/* Modal de Notificações */
.notifications-modal .modal-content {
    max-width: 800px;
}

.notification-filters {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.notification-filter-btn {
    padding: 8px 16px;
    border: 1px solid var(--border-color);
    background: var(--card-bg);
    color: var(--text-color);
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.notification-filter-btn.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.empty-notifications {
    text-align: center;
    padding: 40px;
    color: var(--secondary-color);
}

.empty-notifications i {
    font-size: 3rem;
    margin-bottom: 15px;
    display: block;
}

/* Responsividade */
@media (max-width: 768px) {
    .notification-dropdown {
        width: 300px;
        right: -50px;
    }
    
    .notification-item {
        padding: 10px;
    }
}
/* SISTEMA DE CHAT */
.chat-badge {
    background: var(--danger-color);
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: -5px;
    right: -5px;
}

.chat-icon {
    position: relative;
    cursor: pointer;
    font-size: 1.5rem;
    padding: 10px;
}

.chat-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    width: 350px;
    max-width: 90vw;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: var(--shadow);
    z-index: 1000;
    display: none;
}

.chat-dropdown.show {
    display: block;
}

.chat-header {
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-list {
    max-height: 300px;
    overflow-y: auto;
}

.chat-user-item {
    padding: 12px 15px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.3s ease;
    display: flex;
    align-items: center;
    gap: 12px;
}

.chat-user-item:hover {
    background: rgba(0, 123, 255, 0.1);
}

.chat-user-item.active {
    background: rgba(0, 123, 255, 0.05);
    border-left: 3px solid var(--primary-color);
}

.chat-user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.chat-user-info {
    flex: 1;
}

.chat-user-name {
    font-weight: bold;
    margin-bottom: 3px;
}

.chat-user-lastmsg {
    font-size: 0.8rem;
    color: var(--secondary-color);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-user-badge {
    background: var(--primary-color);
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chat-footer {
    padding: 15px;
    border-top: 1px solid var(--border-color);
    text-align: center;
}

/* Modal do Chat */
.chat-modal .modal-content {
    max-width: 800px;
    height: 80vh;
    display: flex;
    flex-direction: column;
}

.chat-container {
    display: flex;
    flex: 1;
    height: 100%;
}

.chat-sidebar {
    width: 300px;
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
}

.chat-conversations {
    flex: 1;
    overflow-y: auto;
}

.chat-messages {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 20px;
}

.chat-messages-header {
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    text-align: center;
    font-weight: bold;
}

.chat-messages-list {
    flex: 1;
    overflow-y: auto;
    padding: 15px 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.message-item {
    display: flex;
    margin-bottom: 15px;
    max-width: 80%;
}

.message-item.own {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    object-fit: cover;
    margin: 0 10px;
}

.message-content {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 15px;
    padding: 10px 15px;
    position: relative;
}

.message-item.own .message-content {
    background: var(--primary-color);
    color: white;
}

.message-text {
    margin: 0;
}

.message-time {
    font-size: 0.7rem;
    opacity: 0.7;
    margin-top: 5px;
    text-align: right;
}

.chat-input-container {
    padding: 15px;
    border-top: 1px solid var(--border-color);
    display: flex;
    gap: 10px;
}

.chat-input {
    flex: 1;
    padding: 12px 15px;
    border: 1px solid var(--border-color);
    border-radius: 25px;
    background: var(--card-bg);
    color: var(--text-color);
}

.chat-send-btn {
    background: var(--primary-color);
    color: white;
    border: none;
    border-radius: 50%;
    width: 45px;
    height: 45px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-chat {
    text-align: center;
    padding: 40px;
    color: var(--secondary-color);
}

.empty-chat i {
    font-size: 3rem;
    margin-bottom: 15px;
    display: block;
}

/* Responsividade Chat */
@media (max-width: 768px) {
    .chat-dropdown {
        width: 300px;
        right: -50px;
    }
    
    .chat-container {
        flex-direction: column;
    }
    
    .chat-sidebar {
        width: 100%;
        height: 200px;
        border-right: none;
        border-bottom: 1px solid var(--border-color);
    }
    
    .message-item {
        max-width: 90%;
    }
}
</style>
</head>
<body class="<?php echo isset($_SESSION['tema']) && $_SESSION['tema'] == 'escuro' ? 'dark-theme' : ''; ?>">

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="user-welcome">
            <h1>Olá, <?php echo htmlspecialchars($usuario['nome']); ?>!</h1>
            <div class="user-stats">
                <div class="stat-item">📊 <?php echo $estatisticas['concluidas']; ?> de <?php echo $estatisticas['total']; ?> tarefas concluídas</div>
                <div class="stat-item">⚡ Produtividade: <?php echo $estatisticas['total'] > 0 ? round(($estatisticas['concluidas'] / $estatisticas['total']) * 100) : 0; ?>%</div>
            </div>
        </div>
           <div class="header-actions" style="display: flex; align-items: center; gap: 15px;">
    <!-- Ícone do Chat -->
    <div class="chat-icon" onclick="toggleChat()">
        💬
        <?php if($total_nao_lidas > 0): ?>
            <span class="chat-badge"><?php echo $total_nao_lidas; ?></span>
        <?php endif; ?>
        
        <!-- Dropdown do Chat -->
        <div class="chat-dropdown" id="chatDropdown">
            <div class="chat-header">
                <h4 style="margin: 0;">Conversas</h4>
                <span class="badge"><?php echo count($conversas); ?> conversas</span>
            </div>
            
            <div class="chat-list">
                <?php if(empty($conversas)): ?>
                    <div class="empty-chat">
                        <div>💬</div>
                        <p>Nenhuma conversa</p>
                    </div>
                <?php else: ?>
                    <?php foreach($conversas as $conversa): ?>
                        <div class="chat-user-item" onclick="openChatModal(<?php echo $conversa['user_id']; ?>)">
                            <img src="<?php echo !empty($conversa['foto_perfil']) ? htmlspecialchars($conversa['foto_perfil']) : 'uploads/default.png'; ?>" 
                                 alt="Avatar" class="chat-user-avatar">
                            <div class="chat-user-info">
                                <div class="chat-user-name"><?php echo htmlspecialchars($conversa['nome']); ?></div>
                                <div class="chat-user-lastmsg"><?php echo $conversa['total_mensagens']; ?> mensagens</div>
                            </div>
                            <?php if($conversa['nao_lidas'] > 0): ?>
                                <span class="chat-user-badge"><?php echo $conversa['nao_lidas']; ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="chat-footer">
                <a href="javascript:void(0)" onclick="openChatModal()" class="btn btn-primary btn-sm">
                    Novo Chat
                </a>
            </div>
        </div>
    </div>
    
    <!-- Ícone de Notificações -->
    <div class="notification-icon" onclick="toggleNotifications()">
        🔔
        <?php if($notificacoes_nao_lidas > 0): ?>
            <span class="notification-badge"><?php echo $notificacoes_nao_lidas; ?></span>
        <?php endif; ?>
        
        <!-- Dropdown de Notificações -->
        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header">
                <h4 style="margin: 0;">Notificações</h4>
                <?php if($notificacoes_nao_lidas > 0): ?>
                    <form method="post" style="margin: 0;">
                        <button type="submit" name="marcar_todas_lidas" class="btn btn-sm btn-primary">
                            Marcar todas como lidas
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            
            <div class="notification-list">
                <?php if(empty($notificacoes)): ?>
                    <div class="empty-notifications">
                        <div>🎉</div>
                        <p>Nenhuma notificação</p>
                    </div>
                <?php else: ?>
                    <?php foreach(array_slice($notificacoes, 0, 5) as $notificacao): ?>
                        <div class="notification-item <?php echo !$notificacao['lida'] ? 'unread' : ''; ?>" 
                             onclick="window.location.href='<?php echo $notificacao['link'] ?: 'sistema_usuario.php'; ?>'">
                            <div class="notification-title">
                                <span><?php echo htmlspecialchars($notificacao['titulo']); ?></span>
                                <span class="notification-type type-<?php echo $notificacao['tipo']; ?>">
                                    <?php echo ucfirst($notificacao['tipo']); ?>
                                </span>
                            </div>
                            <div class="notification-message">
                                <?php echo htmlspecialchars($notificacao['mensagem']); ?>
                            </div>
                            <div class="notification-time">
                                <?php echo date('d/m/Y H:i', strtotime($notificacao['data_criacao'])); ?>
                            </div>
                            <div class="notification-actions">
                                <?php if(!$notificacao['lida']): ?>
                                    <a href="?marcar_lida=<?php echo $notificacao['id']; ?>" class="btn btn-success btn-sm">
                                        Marcar como lida
                                    </a>
                                <?php endif; ?>
                                <a href="?excluir_notificacao=<?php echo $notificacao['id']; ?>" class="btn btn-danger btn-sm" 
                                   onclick="event.stopPropagation(); return confirm('Excluir esta notificação?')">
                                    Excluir
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="notification-footer">
                <a href="javascript:void(0)" onclick="openNotificationsModal()" class="btn btn-secondary btn-sm">
                    Ver todas as notificações
                </a>
            </div>
        </div>
    </div>
    
    <a href="logout.php" class="btn btn-danger">Sair</a>
</div>
    </div>

    <!-- Grid Principal -->
    <div class="main-grid">
        <!-- Card de Perfil -->
        <div class="profile-card">
            <img src="<?php echo !empty($usuario['foto_perfil']) ? htmlspecialchars($usuario['foto_perfil']) : 'uploads/default.png'; ?>" 
                 alt="Foto de perfil" class="profile-image" onclick="openModal()">
            <h2 class="profile-name"><?php echo htmlspecialchars($usuario['nome']); ?></h2>
            <p class="profile-bio"><?php echo !empty($usuario['bio']) ? htmlspecialchars($usuario['bio']) : 'Sem biografia...'; ?></p>
            
            <div class="profile-details">
                <?php if(!empty($usuario['email'])): ?>
                <div class="detail-item">
                    <i>📧</i> <?php echo htmlspecialchars($usuario['email']); ?>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($usuario['telefone'])): ?>
                <div class="detail-item">
                    <i>📱</i> <?php echo htmlspecialchars($usuario['telefone']); ?>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($usuario['localizacao'])): ?>
                <div class="detail-item">
                    <i>📍</i> <?php echo htmlspecialchars($usuario['localizacao']); ?>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($usuario['website'])): ?>
                <div class="detail-item">
                    <i>🌐</i> <a href="<?php echo htmlspecialchars($usuario['website']); ?>" target="_blank">Website</a>
                </div>
                <?php endif; ?>
            </div>

            <div class="profile-actions">
                <button class="btn btn-primary" onclick="openModal()">Editar Perfil</button>
                <button class="btn btn-secondary" onclick="toggleTheme()">Alternar Tema</button>
            </div>
        </div>

        <!-- Conteúdo Principal -->
        <div class="main-content">
            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-number"><?php echo $estatisticas['total']; ?></span>
                    <span class="stat-label">Total de Tarefas</span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $estatisticas['concluidas']; ?></span>
                    <span class="stat-label">Concluídas</span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $estatisticas['alta_prioridade']; ?></span>
                    <span class="stat-label">Alta Prioridade</span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $estatisticas['media_prioridade']; ?></span>
                    <span class="stat-label">Média Prioridade</span>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filters">
                <div class="filter-group">
                    <select class="filter-select" onchange="filtrarTarefas()" id="filtroCategoria">
                        <option value="todas">Todas as Categorias</option>
                        <option value="trabalho" <?php echo $filtro_categoria == 'trabalho' ? 'selected' : ''; ?>>Trabalho</option>
                        <option value="pessoal" <?php echo $filtro_categoria == 'pessoal' ? 'selected' : ''; ?>>Pessoal</option>
                        <option value="estudos" <?php echo $filtro_categoria == 'estudos' ? 'selected' : ''; ?>>Estudos</option>
                        <option value="geral" <?php echo $filtro_categoria == 'geral' ? 'selected' : ''; ?>>Geral</option>
                    </select>

                    <select class="filter-select" onchange="filtrarTarefas()" id="filtroStatus">
                        <option value="todas">Todos os Status</option>
                        <option value="pendentes" <?php echo $filtro_status == 'pendentes' ? 'selected' : ''; ?>>Pendentes</option>
                        <option value="concluidas" <?php echo $filtro_status == 'concluidas' ? 'selected' : ''; ?>>Concluídas</option>
                    </select>

                    <select class="filter-select" onchange="filtrarTarefas()" id="ordenacao">
                        <option value="data_criacao" <?php echo $ordenacao == 'data_criacao' ? 'selected' : ''; ?>>Data de Criação</option>
                        <option value="prioridade" <?php echo $ordenacao == 'prioridade' ? 'selected' : ''; ?>>Prioridade</option>
                        <option value="categoria" <?php echo $ordenacao == 'categoria' ? 'selected' : ''; ?>>Categoria</option>
                        <option value="concluidas" <?php echo $ordenacao == 'concluidas' ? 'selected' : ''; ?>>Status</option>
                    </select>
                </div>
            </div>

            <!-- Tarefas -->
            <div class="tasks-section">
                <h3 class="section-title">
                    Minhas Tarefas
                    <span class="task-count"><?php echo count($tarefas); ?> tarefas</span>
                </h3>

                <form method="post" class="task-form">
                    <input type="text" name="descricao" class="task-input" placeholder="Nova tarefa..." required>
                    <select name="prioridade" class="task-input" style="max-width: 150px;">
                        <option value="baixa">Baixa Prioridade</option>
                        <option value="media" selected>Média Prioridade</option>
                        <option value="alta">Alta Prioridade</option>
                    </select>
                    <select name="categoria" class="task-input" style="max-width: 150px;">
                        <option value="geral">Geral</option>
                        <option value="trabalho">Trabalho</option>
                        <option value="pessoal">Pessoal</option>
                        <option value="estudos">Estudos</option>
                    </select>
                    <button type="submit" name="add_tarefa" class="btn btn-success">Adicionar</button>
                </form>

               <div class="task-list">
    <?php foreach($tarefas as $tarefa): ?>
    <div class="task-item <?php echo $tarefa['concluida'] ? 'concluida' : ''; ?>">
        <div class="task-info">
            <div class="task-desc">
                <?php echo htmlspecialchars($tarefa['descricao']); ?>
                <?php if($tarefa['arquivo_id']): ?>
                    <div style="margin-top: 8px;">
                        <span style="background: var(--info-color); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem;">
                            📎 <?php echo htmlspecialchars($tarefa['nome_original']); ?>
                        </span>
                        <?php if($tarefa['descricao_arquivo']): ?>
                            <small style="color: var(--secondary-color); margin-left: 8px;">
                                <?php echo htmlspecialchars($tarefa['descricao_arquivo']); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="task-meta">
                <span class="task-priority priority-<?php echo $tarefa['prioridade']; ?>">
                    <?php echo ucfirst($tarefa['prioridade']); ?>
                </span>
                <span class="task-category"><?php echo ucfirst($tarefa['categoria']); ?></span>
                <span class="task-date"><?php echo date('d/m/Y', strtotime($tarefa['data_criacao'])); ?></span>
            </div>
        </div>
        <div class="task-actions">
            <?php if($tarefa['arquivo_id']): ?>
                <a href="download.php?id=<?php echo $tarefa['arquivo_id']; ?>" class="btn btn-info btn-sm">Abrir Arquivo</a>
            <?php endif; ?>
            
            <!-- Botão para adicionar/alterar arquivo -->
            <button class="btn btn-secondary btn-sm" onclick="abrirUploadArquivo(<?php echo $tarefa['id']; ?>, '<?php echo htmlspecialchars($tarefa['descricao']); ?>')">
                <?php echo $tarefa['arquivo_id'] ? '📎 Alterar' : '📎 Anexar'; ?>
            </button>
            
            <?php if(!$tarefa['concluida']): ?>
                <a href="?concluir=<?php echo $tarefa['id']; ?>" class="btn btn-success btn-sm">Concluir</a>
            <?php endif; ?>
            <a href="?excluir=<?php echo $tarefa['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir esta tarefa?')">Excluir</a>
        </div>
    </div>
    <?php endforeach; ?>
                    
                    <?php if(empty($tarefas)): ?>
                    <div class="task-item" style="text-align: center; padding: 40px;">
                        <p>Nenhuma tarefa encontrada. Adicione sua primeira tarefa!</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Edição de Perfil -->
<div class="modal" id="modalPerfil">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 style="margin-bottom: 25px; text-align: center;">Editar Perfil</h2>
        
        <?php if(isset($sucesso_perfil)): ?>
            <div class="alert alert-success"><?php echo $sucesso_perfil; ?></div>
        <?php endif; ?>
        
        <?php if(isset($erro_senha)): ?>
            <div class="alert alert-error"><?php echo $erro_senha; ?></div>
        <?php endif; ?>
        
        <?php if(isset($erro_email)): ?>
            <div class="alert alert-error"><?php echo $erro_email; ?></div>
        <?php endif; ?>
        
        <?php if(isset($sucesso_foto)): ?>
            <div class="alert alert-success"><?php echo $sucesso_foto; ?></div>
        <?php endif; ?>
        
        <?php if(isset($erro_foto)): ?>
            <div class="alert alert-error"><?php echo $erro_foto; ?></div>
        <?php endif; ?>

        <div class="modal-tabs">
            <button class="modal-tab active" onclick="openTab(event, 'tabPerfil')">Informações</button>
            <button class="modal-tab" onclick="openTab(event, 'tabSeguranca')">Segurança</button>
            <button class="modal-tab" onclick="openTab(event, 'tabFoto')">Foto</button>
            <button class="modal-tab" onclick="openTab(event, 'tabPreferencias')">Preferências</button>
            <button class="modal-tab" onclick="openTab(event, 'tabArquivos')">Arquivos</button>
        </div>

        <!-- Aba Informações -->
        <div id="tabPerfil" class="tab-content active">
            <form method="post" class="modal-form">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Nome Completo *</label>
                        <input type="text" name="nome" class="form-input" value="<?php echo htmlspecialchars($usuario['nome']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($usuario['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Telefone</label>
                        <input type="tel" name="telefone" class="form-input" value="<?php echo htmlspecialchars($usuario['telefone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Data de Nascimento</label>
                        <input type="date" name="data_nascimento" class="form-input" value="<?php echo htmlspecialchars($usuario['data_nascimento'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Gênero</label>
                        <select name="genero" class="form-select">
                            <option value="">Selecione...</option>
                            <option value="masculino" <?php echo ($usuario['genero'] ?? '') == 'masculino' ? 'selected' : ''; ?>>Masculino</option>
                            <option value="feminino" <?php echo ($usuario['genero'] ?? '') == 'feminino' ? 'selected' : ''; ?>>Feminino</option>
                            <option value="outro" <?php echo ($usuario['genero'] ?? '') == 'outro' ? 'selected' : ''; ?>>Outro</option>
                            <option value="prefiro_nao_dizer" <?php echo ($usuario['genero'] ?? '') == 'prefiro_nao_dizer' ? 'selected' : ''; ?>>Prefiro não dizer</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Localização</label>
                        <input type="text" name="localizacao" class="form-input" value="<?php echo htmlspecialchars($usuario['localizacao'] ?? ''); ?>" placeholder="Cidade, Estado">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Website</label>
                        <input type="url" name="website" class="form-input" value="<?php echo htmlspecialchars($usuario['website'] ?? ''); ?>" placeholder="https://exemplo.com">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Biografia</label>
                        <textarea name="bio" class="form-textarea" placeholder="Conte um pouco sobre você..."><?php echo htmlspecialchars($usuario['bio'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary" style="width: 100%; margin-top: 20px;">Salvar Alterações</button>
            </form>
        </div>

        <!-- Aba Segurança -->
        <div id="tabSeguranca" class="tab-content">
            <form method="post" class="modal-form">
                <?php if(isset($sucesso_senha)): ?>
                    <div class="alert alert-success"><?php echo $sucesso_senha; ?></div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">Nova Senha</label>
                    <input type="password" name="senha" id="senhaInput" class="form-input" placeholder="Deixe em branco para manter a atual" onkeyup="checkPasswordStrength()">
                    <div class="password-strength" id="passwordStrength"></div>
                    <small style="color: var(--secondary-color); display: block; margin-top: 5px;">
                        A senha deve ter pelo menos 8 caracteres, incluindo uma letra maiúscula, um número e um caractere especial.
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Confirmar Senha</label>
                    <input type="password" name="confirmar_senha" id="confirmarSenha" class="form-input" onkeyup="checkPasswordMatch()">
                    <small id="passwordMatch" style="display: block; margin-top: 5px;"></small>
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary" style="width: 100%;">Alterar Senha</button>
            </form>
        </div>

        <!-- Aba Foto -->
        <div id="tabFoto" class="tab-content">
            <form method="post" enctype="multipart/form-data" class="modal-form">
                <div class="form-group" style="text-align: center;">
                    <label class="form-label">Foto Atual</label>
                    <img src="<?php echo !empty($usuario['foto_perfil']) ? htmlspecialchars($usuario['foto_perfil']) : 'uploads/default.png'; ?>" 
                         alt="Foto atual" style="max-width: 150px; border-radius: 10px; display: block; margin: 0 auto;">
                </div>
                
                <div class="photo-upload" onclick="document.getElementById('fotoInput').click()">
                    <input type="file" name="foto" id="fotoInput" accept="image/*" style="display: none;" onchange="previewFoto()">
                    <p>📷 Clique para selecionar uma nova foto</p>
                    <small style="color: var(--secondary-color);">Formatos: JPG, PNG, GIF (Máx. 5MB)</small>
                </div>
                
                <div class="foto-preview" id="fotoPreview">
                    <p>Preview:</p>
                    <img id="previewImg" src="#" alt="Preview da foto" style="max-width: 200px; border-radius: 10px;">
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary" style="width: 100%; margin-top: 20px;">Atualizar Foto</button>
            </form>
        </div>

        <!-- Aba Preferências -->
        <div id="tabPreferencias" class="tab-content">
            <form method="post" class="modal-form">
                <div class="form-group">
                    <label class="form-label">Tema Preferido</label>
                    <select name="tema_preferido" class="form-select">
                        <option value="auto" <?php echo ($usuario['tema_preferido'] ?? 'auto') == 'auto' ? 'selected' : ''; ?>>Automático (Sistema)</option>
                        <option value="claro" <?php echo ($usuario['tema_preferido'] ?? '') == 'claro' ? 'selected' : ''; ?>>Claro</option>
                        <option value="escuro" <?php echo ($usuario['tema_preferido'] ?? '') == 'escuro' ? 'selected' : ''; ?>>Escuro</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notificações por Email</label>
                    <div style="display: flex; gap: 20px; margin-top: 10px;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="notificacoes" value="ativas" checked> Ativas
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="notificacoes" value="inativas"> Inativas
                        </label>
                    </div>
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary" style="width: 100%;">Salvar Preferências</button>
            </form>
        </div>
    </div>
                 <!-- Aba Documentos -->
        <div id="tabArquivos" class="tab-content">
            <h3 style="margin-bottom: 20px;">Meus Documentos</h3>
            
            <?php if(isset($sucesso_arquivo)): ?>
                <div class="alert alert-success"><?php echo $sucesso_arquivo; ?></div>
            <?php endif; ?>
            
            <?php if(isset($erro_arquivo)): ?>
                <div class="alert alert-error"><?php echo $erro_arquivo; ?></div>
            <?php endif; ?>
            
            <!-- Formulário de Upload -->
            <form method="post" enctype="multipart/form-data" class="modal-form">
                <div class="form-group">
                    <label class="form-label">Adicionar Documento</label>
                    <div class="photo-upload" onclick="document.getElementById('arquivoInput').click()">
                        <input type="file" name="arquivo" id="arquivoInput" style="display: none;" onchange="previewArquivo()">
                        <p>📄 Clique para selecionar um documento</p>
                        <small style="color: var(--secondary-color);">Planilhas, PDFs, Manuais, etc. (Máx. 10MB)</small>
                    </div>
                    <div id="arquivoPreview" class="foto-preview">
                        <p id="arquivoNome"></p>
                        <small id="arquivoTamanho"></small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tipo de Documento</label>
                    <select name="descricao_arquivo" class="form-select">
                        <option value="">Selecione o tipo...</option>
                        <option value="📊 Planilha">Planilha</option>
                        <option value="📄 PDF">PDF</option>
                        <option value="📋 Relatório">Relatório</option>
                        <option value="📖 Manual">Manual</option>
                        <option value="📑 Documento">Documento Geral</option>
                        <option value="📝 Anotação">Anotações</option>
                        <option value="📈 Gráfico">Gráfico</option>
                    </select>
                </div>
                
                <button type="submit" name="upload_arquivo" class="btn btn-primary">Salvar Documento</button>
            </form>

            <!-- Lista de Documentos -->
            <div style="margin-top: 30px;">
                <h4>Documentos Salvos</h4>
                <div id="listaArquivos" class="task-list">
                    <?php if(empty($arquivos)): ?>
                        <p style="text-align: center; color: var(--secondary-color); padding: 20px;">
                            Nenhum documento salvo ainda.
                        </p>
                    <?php else: ?>
                        <?php foreach($arquivos as $arquivo): ?>
                        <div class="task-item">
                            <div class="task-info">
                                <div class="task-desc">
                                    <?php 
                                    $icone = getFileIcon($arquivo['tipo']);
                                    echo $icone . ' ' . htmlspecialchars($arquivo['nome_original']); 
                                    ?>
                                </div>
                                <div class="task-meta">
                                    <span><strong><?php echo !empty($arquivo['descricao']) ? $arquivo['descricao'] : '📄 Documento'; ?></strong></span>
                                    <span><?php echo formatFileSize($arquivo['tamanho']); ?></span>
                                    <span><?php echo date('d/m/Y H:i', strtotime($arquivo['data_upload'])); ?></span>
                                </div>
                            </div>
                            <div class="task-actions">
                                <a href="download.php?id=<?php echo $arquivo['id']; ?>" class="btn btn-success btn-sm">Abrir</a>
                                <a href="?excluir_arquivo=<?php echo $arquivo['id']; ?>" class="btn btn-danger btn-sm" 
                                   onclick="return confirm('Tem certeza que deseja excluir este documento?')">Excluir</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Modal de Edição de Perfil -->
<div class="modal" id="modalPerfil">
    <div class="modal-content">
        <!-- ... conteúdo do modal de perfil ... -->
    </div>
</div>

<!-- Modal de Todas as Notificações -->
<div class="modal notifications-modal" id="modalNotificacoes">
    <div class="modal-content">
        <span class="close-modal" onclick="closeNotificationsModal()">&times;</span>
        <h2 style="margin-bottom: 25px; text-align: center;">Todas as Notificações</h2>
        
        <?php if(isset($sucesso_notificacao)): ?>
            <div class="alert alert-success"><?php echo $sucesso_notificacao; ?></div>
        <?php endif; ?>
        
        <div class="notification-filters">
            <button class="notification-filter-btn active" onclick="filterNotifications('todas')">Todas</button>
            <button class="notification-filter-btn" onclick="filterNotifications('nao-lidas')">Não lidas</button>
            <button class="notification-filter-btn" onclick="filterNotifications('info')">Informação</button>
            <button class="notification-filter-btn" onclick="filterNotifications('sucesso')">Sucesso</button>
            <button class="notification-filter-btn" onclick="filterNotifications('alerta')">Alerta</button>
            <button class="notification-filter-btn" onclick="filterNotifications('erro')">Erro</button>
        </div>
        
        <div class="notification-list" id="allNotificationsList">
            <?php if(empty($notificacoes)): ?>
                <div class="empty-notifications">
                    <div>🎉</div>
                    <h3>Nenhuma notificação encontrada</h3>
                    <p>Quando você tiver novas notificações, elas aparecerão aqui.</p>
                </div>
            <?php else: ?>
                <?php foreach($notificacoes as $notificacao): ?>
                    <div class="notification-item <?php echo !$notificacao['lida'] ? 'unread' : ''; ?> notification-<?php echo $notificacao['tipo']; ?>"
                         data-type="<?php echo $notificacao['tipo']; ?>" 
                         data-read="<?php echo $notificacao['lida'] ? 'lida' : 'nao-lida'; ?>">
                        <div class="notification-title">
                            <span><?php echo htmlspecialchars($notificacao['titulo']); ?></span>
                            <span class="notification-type type-<?php echo $notificacao['tipo']; ?>">
                                <?php echo ucfirst($notificacao['tipo']); ?>
                            </span>
                        </div>
                        <div class="notification-message">
                            <?php echo htmlspecialchars($notificacao['mensagem']); ?>
                        </div>
                        <div class="notification-time">
                            <?php echo date('d/m/Y H:i', strtotime($notificacao['data_criacao'])); ?>
                        </div>
                        <div class="notification-actions">
                            <?php if(!$notificacao['lida']): ?>
                                <a href="?marcar_lida=<?php echo $notificacao['id']; ?>" class="btn btn-success btn-sm">
                                    Marcar como lida
                                </a>
                            <?php endif; ?>
                            <a href="?excluir_notificacao=<?php echo $notificacao['id']; ?>" class="btn btn-danger btn-sm" 
                               onclick="return confirm('Excluir esta notificação?')">
                                Excluir
                            </a>
                            <?php if($notificacao['link']): ?>
                                <a href="<?php echo $notificacao['link']; ?>" class="btn btn-primary btn-sm">
                                    Ver mais
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="notification-footer">
            <form method="post">
                <button type="submit" name="marcar_todas_lidas" class="btn btn-primary">
                    Marcar todas como lidas
                </button>
            </form>
        </div>
    </div>
</div>
</div> <!-- fecha modal Notificações -->

<!-- ========== MODAL DO CHAT ========== -->

<!-- Modal do Chat Completo -->
<div class="modal chat-modal" id="modalChat">
    <div class="modal-content">
        <span class="close-modal" onclick="closeChatModal()">&times;</span>
        <h2 style="margin-bottom: 20px; text-align: center;">Chat com Usuários</h2>
        
        <div class="chat-container">
            <!-- Sidebar com lista de usuários -->
            <div class="chat-sidebar">
                <div class="chat-header">
                    <h4 style="margin: 0;">Usuários</h4>
                </div>
                
                <div class="chat-conversations">
                    <?php if(empty($usuarios_chat)): ?>
                        <div class="empty-chat">
                            <div>👥</div>
                            <p>Nenhum outro usuário</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($usuarios_chat as $user): ?>
                            <?php 
                            // Encontrar conversa com este usuário
                            $user_conversa = null;
                            $nao_lidas = 0;
                            foreach($conversas as $conv) {
                                if($conv['user_id'] == $user['id']) {
                                    $user_conversa = $conv;
                                    $nao_lidas = $conv['nao_lidas'];
                                    break;
                                }
                            }
                            ?>
                            <div class="chat-user-item <?php echo isset($_GET['chat_com']) && $_GET['chat_com'] == $user['id'] ? 'active' : ''; ?>" 
                                 onclick="loadChat(<?php echo $user['id']; ?>)">
                                <img src="<?php echo !empty($user['foto_perfil']) ? htmlspecialchars($user['foto_perfil']) : 'uploads/default.png'; ?>" 
                                     alt="Avatar" class="chat-user-avatar">
                                <div class="chat-user-info">
                                    <div class="chat-user-name"><?php echo htmlspecialchars($user['nome']); ?></div>
                                    <div class="chat-user-lastmsg">
                                        <?php if($user_conversa): ?>
                                            <?php echo $user_conversa['total_mensagens']; ?> mensagens
                                        <?php else: ?>
                                            Iniciar conversa
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if($nao_lidas > 0): ?>
                                    <span class="chat-user-badge"><?php echo $nao_lidas; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Área de mensagens -->
            <div class="chat-messages">
                <?php if($chat_usuario): ?>
                    <div class="chat-messages-header">
                        Conversa com <strong><?php echo htmlspecialchars($chat_usuario['nome']); ?></strong>
                    </div>
                    
                    <div class="chat-messages-list" id="chatMessagesList">
                        <?php if(empty($mensagens)): ?>
                            <div class="empty-chat">
                                <div>💬</div>
                                <p>Nenhuma mensagem ainda</p>
                                <small>Envie a primeira mensagem!</small>
                            </div>
                        <?php else: ?>
                            <?php foreach($mensagens as $msg): ?>
                                <div class="message-item <?php echo $msg['remetente_id'] == $_SESSION['id'] ? 'own' : ''; ?>">
                                    <img src="<?php echo !empty($msg['remetente_foto']) ? htmlspecialchars($msg['remetente_foto']) : 'uploads/default.png'; ?>" 
                                         alt="Avatar" class="message-avatar">
                                    <div class="message-content">
                                        <p class="message-text"><?php echo htmlspecialchars($msg['mensagem']); ?></p>
                                        <div class="message-time">
                                            <?php echo date('H:i', strtotime($msg['data_envio'])); ?>
                                            <?php if($msg['remetente_id'] != $_SESSION['id'] && $msg['lida']): ?>
                                                ✓✓
                                            <?php elseif($msg['remetente_id'] == $_SESSION['id']): ?>
                                                <?php echo $msg['lida'] ? '✓✓' : '✓'; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <form method="post" class="chat-input-container">
                        <input type="hidden" name="destinatario_id" value="<?php echo $chat_usuario['id']; ?>">
                        <input type="text" name="mensagem" class="chat-input" placeholder="Digite sua mensagem..." required>
                        <button type="submit" name="enviar_mensagem" class="chat-send-btn">
                            ➤
                        </button>
                    </form>
                <?php else: ?>
                    <div class="empty-chat" style="flex: 1; display: flex; align-items: center; justify-content: center;">
                        <div>
                            <div style="font-size: 4rem;">💬</div>
                            <h3>Selecione um usuário para conversar</h3>
                            <p>Escolha alguém da lista ao lado para iniciar uma conversa</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
// Modal Functions
function openModal(){ 
    document.getElementById('modalPerfil').style.display = 'flex'; 
    document.body.style.overflow = 'hidden';
}

function closeModal(){ 
    document.getElementById('modalPerfil').style.display = 'none'; 
    document.body.style.overflow = 'auto';
}

// Sistema de Abas
function openTab(evt, tabName) {
    const tabContents = document.getElementsByClassName("tab-content");
    for (let i = 0; i < tabContents.length; i++) {
        tabContents[i].classList.remove("active");
    }
    
    const tabButtons = document.getElementsByClassName("modal-tab");
    for (let i = 0; i < tabButtons.length; i++) {
        tabButtons[i].classList.remove("active");
    }
    
    document.getElementById(tabName).classList.add("active");
    if (evt) {
        evt.currentTarget.classList.add("active");
    }
}

// Tema
function toggleTheme() {
    document.body.classList.toggle('dark-theme');
    const isDark = document.body.classList.contains('dark-theme');
    localStorage.setItem('tema', isDark ? 'escuro' : 'claro');
}

// Preview da Foto
function previewFoto() {
    const input = document.getElementById('fotoInput');
    const preview = document.getElementById('fotoPreview');
    const img = document.getElementById('previewImg');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Força da Senha
function checkPasswordStrength() {
    const password = document.getElementById('senhaInput').value;
    const strengthBar = document.getElementById('passwordStrength');
    
    if (password.length === 0) {
        strengthBar.className = 'password-strength';
        strengthBar.style.width = '0%';
        return;
    }
    
    let strength = 0;
    
    // Comprimento
    if (password.length >= 8) strength += 1;
    
    // Letras maiúsculas
    if (/[A-Z]/.test(password)) strength += 1;
    
    // Números
    if (/[0-9]/.test(password)) strength += 1;
    
    // Caracteres especiais
    if (/[\W]/.test(password)) strength += 1;
    
    // Atualizar barra
    if (strength <= 1) {
        strengthBar.className = 'password-strength strength-weak';
    } else if (strength <= 3) {
        strengthBar.className = 'password-strength strength-medium';
    } else {
        strengthBar.className = 'password-strength strength-strong';
    }
}

// Verificar Senhas
function checkPasswordMatch() {
    const password = document.getElementById('senhaInput').value;
    const confirmPassword = document.getElementById('confirmarSenha').value;
    const message = document.getElementById('passwordMatch');
    
    if (confirmPassword.length === 0) {
        message.innerHTML = '';
        return;
    }
    
    if (password === confirmPassword) {
        message.innerHTML = '✓ As senhas coincidem';
        message.style.color = 'var(--success-color)';
    } else {
        message.innerHTML = '✗ As senhas não coincidem';
        message.style.color = 'var(--danger-color)';
    }
}

// Filtros
function filtrarTarefas() {
    const categoria = document.getElementById('filtroCategoria').value;
    const status = document.getElementById('filtroStatus').value;
    const ordenacao = document.getElementById('ordenacao').value;
    
    let url = window.location.pathname + '?';
    if (categoria !== 'todas') url += `categoria=${categoria}&`;
    if (status !== 'todas') url += `status=${status}&`;
    if (ordenacao !== 'data_criacao') url += `ordenar=${ordenacao}&`;
    
    window.location.href = url.slice(0, -1); // Remove o último '&'
}

// Fechar modal ao clicar fora
window.onclick = function(event) {
    const modal = document.getElementById('modalPerfil');
    if (event.target == modal) {
        closeModal();
    }
}

// Carregar tema salvo
document.addEventListener('DOMContentLoaded', function() {
    const temaSalvo = localStorage.getItem('tema');
    if (temaSalvo === 'escuro') {
        document.body.classList.add('dark-theme');
    }
});
// Preview do arquivo
function previewArquivo() {
    const input = document.getElementById('arquivoInput');
    const preview = document.getElementById('arquivoPreview');
    const nome = document.getElementById('arquivoNome');
    const tamanho = document.getElementById('arquivoTamanho');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        nome.textContent = file.name;
        tamanho.textContent = formatFileSizeJS(file.size);
        preview.style.display = 'block';
    }
}

// Formatar tamanho do arquivo em JS
function formatFileSizeJS(bytes) {
    if (bytes >= 1073741824) {
        return (bytes / 1073741824).toFixed(2) + ' GB';
    } else if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    } else {
        return bytes + ' bytes';
    }
}
// Modal para upload rápido de arquivo na tarefa
function abrirUploadArquivo(tarefaId, tarefaDescricao) {
    const modal = document.getElementById('modalUploadRapido');
    if (!modal) {
        criarModalUploadRapido();
    }
    
    document.getElementById('tarefaIdUpload').value = tarefaId;
    document.getElementById('tituloUpload').textContent = 'Anexar arquivo à tarefa: ' + tarefaDescricao;
    document.getElementById('modalUploadRapido').style.display = 'flex';
}

function criarModalUploadRapido() {
    const modalHTML = `
    <div class="modal" id="modalUploadRapido">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close-modal" onclick="fecharUploadRapido()">&times;</span>
            <h3 id="tituloUpload" style="margin-bottom: 20px;">Anexar arquivo à tarefa</h3>
            
            <form method="post" enctype="multipart/form-data" class="modal-form">
                <input type="hidden" name="tarefa_id" id="tarefaIdUpload">
                
                <div class="form-group">
                    <label class="form-label">Selecionar Arquivo</label>
                    <div class="photo-upload" onclick="document.getElementById('arquivoRapidoInput').click()">
                        <input type="file" name="arquivo" id="arquivoRapidoInput" style="display: none;" onchange="previewArquivoRapido()">
                        <p>📁 Clique para selecionar um arquivo</p>
                        <small style="color: var(--secondary-color);">Máx. 10MB - Todos os tipos</small>
                    </div>
                    <div id="arquivoRapidoPreview" class="foto-preview">
                        <p id="arquivoRapidoNome"></p>
                        <small id="arquivoRapidoTamanho"></small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição do arquivo (opcional)</label>
                    <input type="text" name="descricao_arquivo" class="form-input" placeholder="Ex: Planilha de custos, Relatório final...">
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="upload_arquivo" class="btn btn-primary" style="flex: 1;">Salvar</button>
                    <button type="button" onclick="fecharUploadRapido()" class="btn btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

function fecharUploadRapido() {
    document.getElementById('modalUploadRapido').style.display = 'none';
}

function previewArquivoRapido() {
    const input = document.getElementById('arquivoRapidoInput');
    const preview = document.getElementById('arquivoRapidoPreview');
    const nome = document.getElementById('arquivoRapidoNome');
    const tamanho = document.getElementById('arquivoRapidoTamanho');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        nome.textContent = file.name;
        tamanho.textContent = formatFileSizeJS(file.size);
        preview.style.display = 'block';
    }
}

// Heartbeat para manter sessão
setInterval(() => {
    fetch('heartbeat.php')
    .then(res => res.json())
    .then(data => {
        if(data.status === 'forced_logout'){
            alert('Você foi desconectado pelo administrador!');
            window.location.href = 'login.php';
        }
    })
    .catch(err => console.error('Erro no heartbeat:', err));
}, 30000);
// NOVAS FUNÇÕES PARA NOTIFICAÇÕES

// Toggle do dropdown de notificações
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    dropdown.classList.toggle('show');
    
    // Fechar outros dropdowns
    document.querySelectorAll('.notification-dropdown').forEach(otherDropdown => {
        if(otherDropdown !== dropdown) {
            otherDropdown.classList.remove('show');
        }
    });
}

// Fechar dropdown ao clicar fora
document.addEventListener('click', function(event) {
    if(!event.target.closest('.notification-icon')) {
        document.querySelectorAll('.notification-dropdown').forEach(dropdown => {
            dropdown.classList.remove('show');
        });
    }
});

// Modal de todas as notificações
function openNotificationsModal() {
    document.getElementById('modalNotificacoes').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Fechar dropdown
    document.getElementById('notificationDropdown').classList.remove('show');
}

function closeNotificationsModal() {
    document.getElementById('modalNotificacoes').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Filtrar notificações
function filterNotifications(filter) {
    const notifications = document.querySelectorAll('#allNotificationsList .notification-item');
    const filterButtons = document.querySelectorAll('.notification-filter-btn');
    
    // Ativar botão selecionado
    filterButtons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    notifications.forEach(notification => {
        switch(filter) {
            case 'todas':
                notification.style.display = 'block';
                break;
            case 'nao-lidas':
                notification.style.display = notification.getAttribute('data-read') === 'nao-lida' ? 'block' : 'none';
                break;
            default:
                notification.style.display = notification.getAttribute('data-type') === filter ? 'block' : 'none';
                break;
        }
    });
}

// Verificar novas notificações a cada 30 segundos
setInterval(() => {
    // Em uma implementação real, isso faria uma requisição AJAX
    console.log('Verificando novas notificações...');
}, 30000);
// =============================================
// SISTEMA DE CHAT - JAVASCRIPT CORRIGIDO
// =============================================

// Toggle do dropdown do chat
function toggleChat() {
    const dropdown = document.getElementById('chatDropdown');
    const isShowing = dropdown.classList.contains('show');
    
    // Fechar todos os dropdowns primeiro
    document.querySelectorAll('.chat-dropdown, .notification-dropdown').forEach(dropdown => {
        dropdown.classList.remove('show');
    });
    
    // Se não estava mostrando, agora mostra
    if (!isShowing) {
        dropdown.classList.add('show');
    }
}

// Fechar dropdown ao clicar fora
document.addEventListener('click', function(event) {
    if (!event.target.closest('.chat-icon') && !event.target.closest('.chat-dropdown')) {
        document.getElementById('chatDropdown').classList.remove('show');
    }
    if (!event.target.closest('.notification-icon') && !event.target.closest('.notification-dropdown')) {
        document.getElementById('notificationDropdown').classList.remove('show');
    }
});

// Modal do chat
function openChatModal(userId = null) {
    document.getElementById('modalChat').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Fechar dropdowns
    document.getElementById('chatDropdown').classList.remove('show');
    document.getElementById('notificationDropdown').classList.remove('show');
    
    // Se um usuário foi especificado e é diferente do atual, carregar o chat
    if (userId) {
        const currentChat = new URLSearchParams(window.location.search).get('chat_com');
        if (currentChat != userId) {
            loadChat(userId);
        }
    }
}

function closeChatModal() {
    document.getElementById('modalChat').style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Limpar parâmetro da URL sem recarregar a página
    if (window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.delete('chat_com');
        window.history.replaceState({}, '', url);
    }
}

// Carregar chat com usuário específico (CORRIGIDO - EVITA LOOP)
function loadChat(userId) {
    // Verifica se já está no mesmo chat para evitar loop
    const urlParams = new URLSearchParams(window.location.search);
    const currentChat = urlParams.get('chat_com');
    
    if (currentChat != userId) {
        // Usa replaceState para atualizar a URL
        if (window.history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.set('chat_com', userId);
            window.history.replaceState({}, '', url);
        }
        
        // Recarrega a página apenas uma vez
        window.location.reload();
    }
}

// Auto-scroll para baixo nas mensagens
function scrollToBottom() {
    const messagesList = document.getElementById('chatMessagesList');
    if (messagesList) {
        messagesList.scrollTop = messagesList.scrollHeight;
    }
}

// Inicializar chat quando modal abrir
document.addEventListener('DOMContentLoaded', function() {
    // Scroll para baixo se houver mensagens (após um pequeno delay)
    setTimeout(scrollToBottom, 300);
    
    // Verificar se deve abrir o modal automaticamente
    const urlParams = new URLSearchParams(window.location.search);
    const chatCom = urlParams.get('chat_com');
    
    if (chatCom) {
        // Pequeno delay para garantir que tudo carregou
        setTimeout(() => {
            openChatModal(parseInt(chatCom));
        }, 500);
    }
});

// Fechar modal com ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeChatModal();
        if (typeof closeNotificationsModal === 'function') {
            closeNotificationsModal();
        }
        if (document.getElementById('modalPerfil') && typeof closeModal === 'function') {
            closeModal();
        }
    }
});

// Fechar modal ao clicar no backdrop
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        closeChatModal();
        if (typeof closeNotificationsModal === 'function') {
            closeNotificationsModal();
        }
        if (document.getElementById('modalPerfil') && typeof closeModal === 'function') {
            closeModal();
        }
    }
});

// Envio de mensagem com AJAX (opcional - para melhor experiência)
function sendMessageQuick(form) {
    const messageInput = form.querySelector('input[name="mensagem"]');
    const message = messageInput.value.trim();
    
    if (message === '') return false;
    
    // Aqui você pode adicionar AJAX para enviar sem recarregar a página
    // Por enquanto, vamos usar o envio normal por form
    return true;
}
</script>
</body>
</html>