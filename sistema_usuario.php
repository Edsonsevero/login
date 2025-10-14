<?php
session_start();
include_once('config.php');

if(!isset($_SESSION['email']) || $_SESSION['role'] != 'usuario'){
    header('Location: login.php');
    exit();
}

// Verificar e criar colunas necessárias se não existirem
$colunas_necessarias = ['bio', 'telefone', 'data_nascimento', 'genero', 'localizacao', 'website', 'tema_preferido'];
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
$stmt = $conexao->prepare("SELECT nome, email, foto_perfil, bio, telefone, data_nascimento, genero, localizacao, website, tema_preferido FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $_SESSION['id']);
$stmt->execute();
$result = $stmt->get_result();
$usuario = $result->fetch_assoc();

// Buscar tarefas do usuário com ordenação
$ordenacao = $_GET['ordenar'] ?? 'data_criacao';
$filtro_categoria = $_GET['categoria'] ?? 'todas';
$filtro_status = $_GET['status'] ?? 'todas';

$sql_tarefas = "SELECT id, descricao, concluida, prioridade, categoria, data_criacao, data_conclusao FROM tarefas WHERE user_id = ?";
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
        <div class="header-actions">
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
                            <div class="task-desc"><?php echo htmlspecialchars($tarefa['descricao']); ?></div>
                            <div class="task-meta">
                                <span class="task-priority priority-<?php echo $tarefa['prioridade']; ?>">
                                    <?php echo ucfirst($tarefa['prioridade']); ?>
                                </span>
                                <span class="task-category"><?php echo ucfirst($tarefa['categoria']); ?></span>
                                <span class="task-date"><?php echo date('d/m/Y', strtotime($tarefa['data_criacao'])); ?></span>
                            </div>
                        </div>
                        <div class="task-actions">
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
</script>
</body>
</html>