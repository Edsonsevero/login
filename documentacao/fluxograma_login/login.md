 ┌────────────────────────┐
        │   Início: Acesso ao    │
        │         Login          │
        │      (test.php)        │
        └──────────┬─────────────┘
                   │
        ┌──────────▼─────────────┐
        │  Valida E-mail e Senha │
        │   no Banco de Dados    │
        └──────────┬─────────────┘
                   │
        ┌──────────▼─────────────┐
        │    Senha Correta?      │
        ├──────────┬─────────────┤
        │   Sim    │     Não     │
        ▼          ▼
┌──────────────────┐  ┌────────────────────┐
│  Cria a Sessão   │  │ Redireciona para   │
│   ($_SESSION)    │  │ login.php + Erro   │
└────────┬─────────┘  └────────────────────┘
         │
┌────────▼───────────────────┐
│ Salva session_id no BD e   │
│ define forced_logout = 0   │
└────────┬───────────────────┘
         │
┌────────▼──────────────────────────┐
│ Redireciona Conforme o Nível:     │
│                                   │
│ admin → sistema.php               │
│ user  → sistema_usuario.php       │
└────────┬──────────────────────────┘
         │
┌────────▼──────────────────────────┐
│         SISTEMA DO USUÁRIO        │
│  - Exibe "Bem-vindo"              │
│  - Heartbeat a cada 15s           │
│  - Verifica "forced_logout"       │
│  - Opção de Logout Normal         │
└────────┬──────────────────────────┘
         │
┌────────▼──────────────────────────┐
│          SISTEMA DO ADMIN         │
│  - Lista todos os usuários        │
│  - Exibe status (online/offline)  │
│  - Permite filtrar/buscar         │
│  - Opção de Forçar Logout         │
│  - Opção de Visualizar Logs       │
└────────┬──────────────────────────┘
         │
┌────────▼──────────────────────────┐
│      Logout Normal (logout.php)   │
│  - Destrói a sessão               │
│  - Registra a ação no log         │
└────────┬──────────────────────────┘
         │
┌────────▼──────────────────────────┐
│     Logout Forçado (pelo Admin)   │
│  - Altera forced_logout para 1    │
│  - Alerta o usuário via Heartbeat │
│  - Remove dos usuários online     │
│  - Registra a ação no log         │
└───────────────────────────────────┘