<?php

session_start();


if (isset($_GET['action'])) {
    
    // --- CONFIGURATOR DO BANCO DE DADOS ---
    $dbHost = 'localhost';
    $dbName = 'bdreserva';
    $dbUser = 'root';
    $dbPass = '123456';

    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SQL para criar as tabelas se elas não existirem.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `salas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `nome` VARCHAR(255) NOT NULL,
            `capacidade` INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `reservas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `sala_id` INT NOT NULL,
            `data_reserva` DATE NOT NULL,
            `hora_inicio` TIME NOT NULL,
            `hora_fim` TIME NULL,
            `responsavel` VARCHAR(255) NOT NULL,
            `motivo` TEXT NOT NULL,
            `controle` TINYINT(1) DEFAULT 0,
            FOREIGN KEY (`sala_id`) REFERENCES `salas`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `usuarios` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'user') NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Verifica se a coluna 'controle' já existe antes de tentar adicioná-la
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `reservas` LIKE 'controle'");
        $stmt->execute();
        $coluna_controle_existe = $stmt->fetch();
        if (!$coluna_controle_existe) {
             $pdo->exec("ALTER TABLE `reservas` ADD COLUMN `controle` TINYINT(1) DEFAULT 0 AFTER `motivo`;");
        }
        
        // Verifica se a coluna 'hora_fim' permite NULL antes de tentar alterá-la
        $stmt = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'reservas' AND COLUMN_NAME = 'hora_fim'");
        $stmt->execute([$dbName]);
        $is_nullable = $stmt->fetchColumn();
        if ($is_nullable !== 'YES') {
            $pdo->exec("ALTER TABLE `reservas` CHANGE `hora_fim` `hora_fim` TIME NULL;");
        }

        // Verifica e insere os usuários padrão se a tabela estiver vazia
        $stmt = $pdo->query("SELECT COUNT(*) FROM `usuarios`");
        if ($stmt->fetchColumn() === 0) {
            $hashedPasswordAdmin = password_hash("admin123", PASSWORD_DEFAULT);
            $hashedPasswordUser = password_hash("user123", PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO `usuarios` (`username`, `password`, `role`) VALUES (?, ?, ?)")->execute(['admin', $hashedPasswordAdmin, 'admin']);
            $pdo->prepare("INSERT INTO `usuarios` (`username`, `password`, `role`) VALUES (?, ?, ?)")->execute(['user', $hashedPasswordUser, 'user']);
        }

        // Verifica e insere as 30 salas se não existirem
        $stmt = $pdo->query("SELECT COUNT(*) FROM `salas`");
        if ($stmt->fetchColumn() < 30) {
            $pdo->exec("TRUNCATE TABLE `salas`;");
            $insertStmt = $pdo->prepare("INSERT INTO `salas` (`nome`, `capacidade`) VALUES (?, ?);");
            for ($i = 1; $i <= 30; $i++) {
                $capacidade = rand(5, 50);
                $nome = "Sala " . str_pad($i, 2, '0', STR_PAD_LEFT);
                $insertStmt->execute([$nome, $capacidade]);
            }
        }

    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Erro de conexão com o banco de dados: " . $e->getMessage()]);
        exit;
    }

    // Estrutura de switch para tratar as diferentes ações
    switch ($_GET['action']) {
        case 'login':
            header('Content-Type: application/json');
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            $stmt = $pdo->prepare("SELECT * FROM `usuarios` WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                echo json_encode(['success' => true, 'role' => $user['role']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Usuário ou senha inválidos.']);
            }
            break;

        case 'logout':
            header('Content-Type: application/json');
            session_destroy();
            echo json_encode(['success' => true]);
            break;

        case 'check_session':
            header('Content-Type: application/json');
            echo json_encode(['logged_in' => isset($_SESSION['logged_in']) && $_SESSION['logged_in'], 'role' => $_SESSION['role'] ?? 'guest', 'username' => $_SESSION['username'] ?? '']);
            break;

        case 'fetch_rooms':
            header('Content-Type: application/json');
            // Verifica se o usuário está logado
            if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
                exit;
            }
            try {
                // Modificado para incluir a hora de início da ocupação
                $sql = "SELECT s.*,
                        (SELECT responsavel FROM reservas WHERE sala_id = s.id AND data_reserva = CURDATE() AND hora_fim IS NULL ORDER BY id DESC LIMIT 1) as responsavel_ocupacao,
                        (SELECT hora_inicio FROM reservas WHERE sala_id = s.id AND data_reserva = CURDATE() AND hora_fim IS NULL ORDER BY id DESC LIMIT 1) as hora_inicio_ocupacao,
                        (SELECT COUNT(*) FROM reservas WHERE sala_id = s.id AND data_reserva = CURDATE() AND hora_fim IS NULL) as is_occupied
                        FROM salas s ORDER BY s.id ASC;";
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['rooms' => $rooms, 'success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Erro ao buscar salas: " . $e->getMessage()]);
            }
            break;

        case 'register_occupant':
            header('Content-Type: application/json');
            // Apenas usuários logados podem registrar ocupação
            if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
                exit;
            }
            try {
                $salaId = $_POST['sala_id'] ?? null;
                $responsavel = $_POST['responsavel'] ?? null;
                $controle = isset($_POST['controle']) ? 0 : 1;
                $motivo = $_POST['motivo'] ?? "Sem observação.";
                
                // Pega a data e hora do POST, enviadas pelo navegador do cliente
                $dataReserva = $_POST['data_reserva'] ?? null;
                $horaInicio = $_POST['hora_inicio'] ?? null;
                $horaFim = null; 

                if (empty($salaId) || empty($responsavel) || empty($dataReserva) || empty($horaInicio)) {
                    echo json_encode(['success' => false, 'message' => 'Todos os campos são obrigatórios.']);
                    break;
                }
                
                $sqlCheck = "SELECT COUNT(*) FROM reservas WHERE sala_id = ? AND data_reserva = ? AND hora_fim IS NULL";
                $stmtCheck = $pdo->prepare($sqlCheck);
                $stmtCheck->execute([$salaId, $dataReserva]);
                $conflictCount = $stmtCheck->fetchColumn();

                if ($conflictCount > 0) {
                    echo json_encode(['success' => false, 'message' => 'Erro: A sala já está ocupada neste momento.']);
                } else {
                    $sqlInsert = "INSERT INTO reservas (sala_id, data_reserva, hora_inicio, hora_fim, responsavel, motivo, controle) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmtInsert = $pdo->prepare($sqlInsert);
                    if ($stmtInsert->execute([$salaId, $dataReserva, $horaInicio, $horaFim, $responsavel, $motivo, $controle])) {
                        echo json_encode(['success' => true, 'message' => 'Sala ocupada com sucesso!']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Erro ao ocupar a sala.']);
                    }
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Erro ao registrar ocupação: " . $e->getMessage()]);
            }
            break;
        
        case 'unoccupy_room':
            header('Content-Type: application/json');
            // Apenas usuários logados podem desocupar salas
            if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
                exit;
            }
            try {
                $salaId = $_POST['sala_id'] ?? null;
                $horaFim = $_POST['hora_fim'] ?? null;
                if (empty($salaId) || empty($horaFim)) {
                    echo json_encode(['success' => false, 'message' => 'ID da sala e horário de término são obrigatórios.']);
                    break;
                }

                $sqlUpdate = "UPDATE reservas SET hora_fim = ? WHERE sala_id = ? AND data_reserva = CURDATE() AND hora_fim IS NULL ORDER BY id DESC LIMIT 1";
                $stmtUpdate = $pdo->prepare($sqlUpdate);

                if ($stmtUpdate->execute([$horaFim, $salaId])) {
                    echo json_encode(['success' => true, 'message' => 'Sala desocupada com sucesso!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao desocupar a sala.']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Erro ao desocupar sala: " . $e->getMessage()]);
            }
            break;

        case 'fetch_history':
            header('Content-Type: application/json');
            // Apenas usuários logados podem ver o histórico
            if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
                exit;
            }
            try {
                $salaId = $_GET['sala_id'] ?? null;
                $params = [];
                $sql = "SELECT r.responsavel, r.data_reserva, r.hora_inicio, r.hora_fim, s.nome AS sala_nome, r.controle, r.motivo
                        FROM reservas r
                        JOIN salas s ON r.sala_id = s.id";
                
                if ($salaId) {
                    $sql .= " WHERE r.sala_id = ?";
                    $params[] = $salaId;
                }
                
                $sql .= " ORDER BY r.data_reserva DESC, r.hora_fim DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['history' => $history, 'success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Erro ao buscar histórico: " . $e->getMessage()]);
            }
            break;

        // --- AÇÕES DE ADMIN ---
        case 'add_room':
            header('Content-Type: application/json');
            // Apenas administradores podem adicionar salas
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permissão negada.']);
                exit;
            }
            try {
                $nome = $_POST['nome'] ?? '';
                $capacidade = $_POST['capacidade'] ?? 0;
                $sql = "INSERT INTO `salas` (`nome`, `capacidade`) VALUES (?, ?)";
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute([$nome, $capacidade])) {
                    echo json_encode(['success' => true, 'message' => 'Sala adicionada com sucesso.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao adicionar sala.']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Erro ao adicionar sala: " . $e->getMessage()]);
            }
            break;

        case 'update_room':
            header('Content-Type: application/json');
            // Apenas administradores podem atualizar salas
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permissão negada.']);
                exit;
            }
            try {
                $id = $_POST['id'] ?? null;
                $nome = $_POST['nome'] ?? '';
                $capacidade = $_POST['capacidade'] ?? 0;
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'ID da sala é obrigatório.']);
                    break;
                }
                $sql = "UPDATE `salas` SET `nome` = ?, `capacidade` = ? WHERE `id` = ?";
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute([$nome, $capacidade, $id])) {
                    echo json_encode(['success' => true, 'message' => 'Sala atualizada com sucesso.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar sala.']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Erro ao atualizar sala: " . $e->getMessage()]);
            }
            break;

        case 'delete_room':
            header('Content-Type: application/json');
            // Apenas administradores podem deletar salas
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permissão negada.']);
                exit;
            }
            try {
                $id = $_POST['id'] ?? null;
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'ID da sala é obrigatório.']);
                    break;
                }
                $sql = "DELETE FROM `salas` WHERE `id` = ?";
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute([$id])) {
                    echo json_encode(['success' => true, 'message' => 'Sala excluída com sucesso.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao excluir sala.']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Erro ao excluir sala: " . $e->getMessage()]);
            }
            break;

        case 'export_history':
            // Qualquer usuário logado pode exportar o histórico
            if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
                http_response_code(401);
                die("Não autorizado.");
            }
            
            $salaId = $_GET['sala_id'] ?? null;
            $params = [];
            $whereClause = '';
            $filename = 'historico_geral_' . date('Y-m-d') . '.csv';

            if ($salaId) {
                $stmtSala = $pdo->prepare("SELECT nome FROM salas WHERE id = ?");
                $stmtSala->execute([$salaId]);
                $sala = $stmtSala->fetch(PDO::FETCH_ASSOC);
                if ($sala) {
                    $filename = 'historico_sala_' . str_replace(' ', '_', $sala['nome']) . '_' . date('Y-m-d') . '.csv';
                }
                $whereClause = " WHERE r.sala_id = ?";
                $params[] = $salaId;
            }

            try {
                $sql = "SELECT s.id AS sala_id, s.nome AS sala_nome, r.responsavel, r.data_reserva, r.hora_inicio, r.hora_fim, r.motivo, r.controle
                        FROM reservas r
                        JOIN salas s ON r.sala_id = s.id" . $whereClause . "
                        ORDER BY r.data_reserva DESC, r.hora_inicio DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Configura os headers para download do CSV
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                
                $output = fopen('php://output', 'w');
                
                // Adiciona o Byte Order Mark (BOM) para garantir que o Excel reconheça a codificação UTF-8
                fwrite($output, "\xEF\xBB\xBF");
                
                // Cabeçalho do CSV
                // Usa o delimitador ponto e vírgula (;) para compatibilidade com o Excel em português
                fputcsv($output, ['ID da Sala', 'Nome da Sala', 'Responsável', 'Data', 'Hora de Início', 'Hora de Fim', 'Motivo', 'Controle'], ';');

                foreach ($history as $row) {
                    // Usa o delimitador ponto e vírgula (;) para compatibilidade com o Excel
                    fputcsv($output, $row, ';');
                }

                fclose($output);
                exit;
            } catch (Exception $e) {
                http_response_code(500);
                die("Erro ao gerar o arquivo: " . $e->getMessage());
            }
            break;

        default:
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
            break;
    }
    exit;
}
?>

<!-- RESTANTE DO CÓDIGO HTML/CSS/JAVASCRIPT -->
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Salas</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        :root {
            --primary-color: #4A90E2;
            --secondary-color: #50E3C2;
            --background-color: #f0f2f5;
            --card-bg: #ffffff;
            --text-color: #333;
            --occupied-bg: #f8d7da;
            --available-bg: #d4edda;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-image: url(/projetophp/sistema-operacional-1593107306.jpg); /* Substitua 'background.jpg' pelo caminho da sua imagem */
            background-size: cover; /* Ajusta a imagem para cobrir todo o fundo */
            background-repeat: no-repeat; /* Impede a repetição da imagem */
            background-attachment: fixed; /* Mantém a imagem fixa durante a rolagem */
            background-color: var(--background-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: auto;
            margin: auto;
            background-color: var(--card-bg);
            padding: 30px;
            border-radius: 12px;
            opacity: 0.8; /* Ajuste a opacidade para deixar mais transparente */
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        h1, h2 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 20px;
        }
        .salas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 21px;
            margin-bottom: 20px;
        }
        .card {
           background-color: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
            border: 2px solid transparent;
            text-align: center;
           
            width: 130px;
            height: 130px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
        }
        .card.ocupada {
            background-color: var(--occupied-bg);
            border-color: #d9534f;
        }
        .card.disponivel {
            background-color: var(--available-bg);
            border-color: #5cb85c;
        }
        .card h3 {
            margin: 0 0 5px;
            font-size: 1.5rem;
        }
        .card p {
            margin: 0;
            font-size: 0.8rem;
            color: #666;
        }
        .card .responsavel-ocupacao {
            font-size: 0.9rem;
            font-weight: bold;
            margin-top: 10px;
            color: #d9534f;
        }
        .status {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.8rem;
            margin-top: 10px;
        }
        #message-box {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            display: none;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
        .form-container, .historico-container {
            border: 1px solid #ccc;
            border-radius: 10px;
            padding: 20px;
            background-color: #f9f9f9;
            margin-bottom: 20px;
        }
        .form-container h2, .historico-container h2 {
            text-align: left;
            margin-top: 0;
        }
        .form-container form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 10px;
        }
        .form-container input[type="text"], .form-container input[type="number"], .form-container input[type="password"], .form-container textarea {
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
            
            
        }
        .form-container button, .logout-btn {
            padding: 10px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        .logout-btn {
            position: center;
            top: 20px;
            right: 20px;
            padding: 8px 15px;
            background-color: #d9534f;
        }
        .historico-container {
            margin-top: 40px;
        }
        .historico-container h2 {
            border-bottom: 2px solid #ccc;
            padding-bottom: 10px;
        }
        .historico-container table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .historico-container th, .historico-container td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .historico-container th {
            background-color: #e9e9e9;
            font-weight: bold;
        }
        .historico-container tbody tr:nth-child(odd) {
            background-color: #f9f9f9;
        }
        .historico-container .controle-sim {
            color: #d9534f;
            font-weight: bold;
        }
        .admin-section {
            display: none;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 10px;
        }
        .admin-section .admin-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        .admin-section button {
            background-color: #5cb85c;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        .admin-section input {
            padding: 8px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }
        #login-screen, #main-app {
            display: none;
            
            
        }
        .unoccupy-btn {
            margin: 20px auto;
            background-color: #d9534f;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            display: none; /
        }
    </style>
</head>
<body>

<div id="login-screen" class="container">
    <h1>Sistema de Salas</h1>
    <div class="form-container">
        <h2>Login</h2>
        <form id="login-form">
            <input type="text" id="username" name="username" placeholder="Usuário" required>
            <input type="password" id="password" name="password" placeholder="Senha" required>
            <button type="submit">Entrar</button>
        </form>
    </div>
</div>

<div id="main-app" class="container">
    <button id="logout-btn" class="logout-btn"></button>
    <h1>Ocupação de Salas</h1>

    <div id="registro-form-container" class="form-container" style="display: none;">
        <h2>Ocupar Sala <span id="form-sala-nome"></span></h2>
        <form id="form-registro">
            <input type="hidden" id="sala-id-input" name="sala_id">
            <input type="text" id="responsavel-input" name="responsavel" placeholder="Nome do Responsável" required>
            <label style="text-align: left;"><input type="checkbox" id="controle-input" name="controle"> Marcar como "Controle"</label>
            <textarea id="motivo-input" name="motivo" placeholder="Observações (opcional)" rows="3"></textarea>
            <button type="submit">Registrar Uso</button>
        </form>
        <div class="admin-actions" style="display: none;">
            <button onclick="editRoom(currentRoomId)">Editar</button>
            <button onclick="deleteRoom(currentRoomId)">Excluir</button>
        </div>
    </div>
    
    <div id="admin-section" class="admin-section">
        <h2>Gerenciamento de Salas (Admin)</h2>
        <form id="add-room-form">
            <h3>Adicionar Nova Sala</h3>
            <input type="text" name="nome" placeholder="Nome da Sala" required>
            <input type="number" name="capacidade" placeholder="Capacidade" required>
            <button type="submit">Adicionar Sala</button>
        </form>
        <h3>Alterar ou Excluir Sala</h3>
        <p>Clique em uma sala para gerenciar.</p>
        <button id="export-excel-btn" style="background-color: #28a745;">Exportar Histórico (CSV)</button>
    </div>

    <div id="salas-container" class="salas-grid">
        <p>Carregando salas...</p>
    </div>
    <div id="message-box"></div>
    

    <button id="unoccupy-button" class="unoccupy-btn" style="display: none;">Desocupar Sala</button>

    <div id="historico-container" class="historico-container" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #ccc; padding-bottom: 10px;">
            <h2>Histórico de Uso da Sala <span id="historico-sala-nome"></span></h2>
            <button id="export-room-history-btn" style="background-color: #28a745; color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer;">Exportar Histórico Desta Sala (CSV)</button>
        </div>
        <table id="historico-table">
            <thead><tr><th>Responsável</th><th>Observação</th><th>Controle</th><th>Entrada</th><th>Saída</th></tr></thead>
            <tbody id="historico-body"><tr><td colspan="5" style="text-align:center;">Nenhum histórico para exibir.</td></tr></tbody>
        </table>
    </div>
</div>

<script>
    const loginScreen = document.getElementById('login-screen');
    const mainApp = document.getElementById('main-app');
    const salasContainer = document.getElementById('salas-container');
    const messageBox = document.getElementById('message-box');
    const adminSection = document.getElementById('admin-section');
    const registroFormContainer = document.getElementById('registro-form-container');
    const historicoContainer = document.getElementById('historico-container');
    const unoccupyButton = document.getElementById('unoccupy-button');
    const logoutBtn = document.getElementById('logout-btn');
    const exportExcelBtn = document.getElementById('export-excel-btn');
    const exportRoomHistoryBtn = document.getElementById('export-room-history-btn');

    let currentRoomId = null;

    // Mostra mensagens de sucesso/erro
    function showMessage(message, type) {
        messageBox.textContent = message;
        messageBox.className = type;
        messageBox.style.display = 'block';
        setTimeout(() => { messageBox.style.display = 'none'; }, 3000);
    }

    // Gerencia a exibição das telas
    function showMainApp(username, role) {
        loginScreen.style.display = 'none';
        mainApp.style.display = 'block';
        if (logoutBtn) {
            logoutBtn.textContent = `Sair (${username})`;
        }
        if (role === 'admin' && adminSection) {
            adminSection.style.display = 'flex';
        } else {
            adminSection.style.display = 'none';
        }
        fetchRooms();
    }

    function showLoginScreen() {
        loginScreen.style.display = 'block';
        mainApp.style.display = 'none';
        if (adminSection) adminSection.style.display = 'none';
    }

    // Gerencia o login
    document.getElementById('login-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const response = await fetch('?action=login', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            localStorage.setItem('userRole', result.role);
            localStorage.setItem('username', formData.get('username'));
            showMainApp(formData.get('username'), result.role);
        } else {
            showMessage(result.message, 'error');
        }
    });

    // Gerencia o logout
    logoutBtn?.addEventListener('click', async () => {
        const response = await fetch('?action=logout');
        const result = await response.json();
        if (result.success) {
            localStorage.removeItem('userRole');
            localStorage.removeItem('username');
            showLoginScreen();
            hideDetails();
        }
    });

    // Função para buscar e renderizar as salas
    async function fetchRooms() {
        if (!salasContainer) return;
        salasContainer.innerHTML = '<p style="text-align:center;">Carregando salas...</p>';
        
        const response = await fetch('?action=fetch_rooms');
        const data = await response.json();

        if (!data.success) {
            salasContainer.innerHTML = `<p class="error" style="text-align:center;">${data.message}</p>`;
            return;
        }
        
        salasContainer.innerHTML = '';
        data.rooms.forEach(room => {
            const isOccupied = room.is_occupied > 0;
            const card = document.createElement('div');
            card.classList.add('card', isOccupied ? 'ocupada' : 'disponivel' );
            card.setAttribute('data-id', room.id);
            
            let cardContent = `<h3>${room.nome}</h3>`;
            cardContent += `<p>Capacidade: ${room.capacidade}</p>`;

            if (isOccupied) { 
                // Adicionado: Exibe o nome do responsável e a hora de início
                cardContent += `<p class="responsavel-ocupacao">Ocupada por: ${room.responsavel_ocupacao}</p>`; 
                cardContent += `<p class="responsavel-ocupacao">Desde: ${room.hora_inicio_ocupacao.substring(0, 5)}</p>`; 
            }
            cardContent += `<p class="status">${isOccupied ? 'Ocupada' : 'Disponível'}</p>`;
            
            card.innerHTML = cardContent;
            card.onclick = () => handleRoomClick(room);
            salasContainer.appendChild(card);
        });
    }

    // Função para desocupar uma sala
    async function unoccupyRoom(salaId) {
        if (!confirm(`Tem certeza que deseja desocupar a sala?`)) { return; }
        
        // Pega a hora exata da máquina do cliente
        const now = new Date();
        const horaFim = now.toTimeString().substring(0, 8); // Formato HH:MM:SS
        
        const formData = new FormData();
        formData.append('sala_id', salaId);
        formData.append('hora_fim', horaFim);

        const response = await fetch(`?action=unoccupy_room`, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showMessage(result.message, 'success');
            fetchRooms(); // Recarrega a lista de salas
            hideDetails();
        } else {
            showMessage(result.message, 'error');
        }
    }

    // Função para buscar e renderizar o histórico
    async function fetchHistory(salaId) {
        const historicoBody = document.getElementById('historico-body');
        historicoBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Carregando histórico...</td></tr>';
        
        const response = await fetch(`?action=fetch_history&sala_id=${salaId}`);
        const data = await response.json();
        
        if (!data.success) {
             historicoBody.innerHTML = `<tr><td colspan="5" class="error" style="text-align:center;">${data.message}</td></tr>`;
             return;
        }

        if (data.history.length === 0) {
            historicoBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Nenhum registro encontrado para esta sala.</td></tr>';
        } else {
            historicoBody.innerHTML = '';
            data.history.forEach(registro => {
                const row = document.createElement('tr');
                const controleStatus = registro.controle == 1 ? '<span class="controle-sim">Sim</span>' : 'Não';
                const horaFim = registro.hora_fim ? registro.hora_fim.substring(0, 5) : 'Em uso';
                row.innerHTML = `<td>${registro.responsavel}</td><td>${registro.motivo}</td><td>${controleStatus}</td><td>${registro.data_reserva} ${registro.hora_inicio.substring(0, 5)}</td><td>${horaFim}</td>`;
                historicoBody.appendChild(row);
            });
        }
    }

    // Esconde os elementos de detalhes e limpa os botões de admin
    function hideDetails() {
        if (registroFormContainer) registroFormContainer.style.display = 'none';
        if (unoccupyButton) unoccupyButton.style.display = 'none';
        if (historicoContainer) historicoContainer.style.display = 'none';

        const adminActionsContainer = document.querySelector('.admin-actions');
        if (adminActionsContainer) {
            adminActionsContainer.style.display = 'none';
        }
    }

    // Lógica para o clique em cada sala
    async function handleRoomClick(room) {
        hideDetails();
        currentRoomId = room.id;

        if (historicoContainer) {
            historicoContainer.style.display = 'block';
            document.getElementById('historico-sala-nome').textContent = room.nome;
            fetchHistory(room.id);
            // Configura o evento para o botão de exportar desta sala
            exportRoomHistoryBtn.onclick = () => window.location.href = `?action=export_history&sala_id=${room.id}`;
        }
        
        const userRole = localStorage.getItem('userRole');
        const isOccupied = room.is_occupied > 0;
        
        const adminActionsContainer = document.querySelector('.admin-actions');
        
        if (userRole === 'user' || userRole === 'admin') {
            if (isOccupied) {
                if (unoccupyButton) {
                    unoccupyButton.style.display = 'block';
                    unoccupyButton.onclick = () => unoccupyRoom(room.id);
                }
            } else {
                if (registroFormContainer) {
                    registroFormContainer.style.display = 'block';
                    document.getElementById('sala-id-input').value = room.id;
                    document.getElementById('form-sala-nome').textContent = room.nome;
                }
            }
        }
        
        if (userRole === 'admin' && adminActionsContainer) {
            adminActionsContainer.style.display = 'flex';
        }
    }
    
    // Funções de Gerenciamento de Admin
    async function addRoom() {
        const form = document.getElementById('add-room-form');
        const formData = new FormData(form);
        const response = await fetch('?action=add_room', { method: 'POST', body: formData });
        const result = await response.json();
        showMessage(result.message, result.success ? 'success' : 'error');
        if (result.success) { form.reset(); fetchRooms(); }
    }
    
    async function editRoom(id) {
        const nome = prompt('Novo nome da sala:');
        const capacidade = prompt('Nova capacidade:');
        if (!nome || !capacidade) return;
        
        const formData = new FormData();
        formData.append('id', id);
        formData.append('nome', nome);
        formData.append('capacidade', capacidade);

        const response = await fetch('?action=update_room', { method: 'POST', body: formData });
        const result = await response.json();
        showMessage(result.message, result.success ? 'success' : 'error');
        if (result.success) { fetchRooms(); }
    }
    
    async function deleteRoom(id) {
        if (!confirm('Tem certeza que deseja excluir esta sala?')) return;
        const formData = new FormData();
        formData.append('id', id);
        const response = await fetch('?action=delete_room', { method: 'POST', body: formData });
        const result = await response.json();
        showMessage(result.message, result.success ? 'success' : 'error');
        if (result.success) { fetchRooms(); hideDetails(); }
    }

    // Lógica para enviar o formulário de registro
    document.getElementById('form-registro')?.addEventListener('submit', async function(event) {
        event.preventDefault();
        const formData = new FormData(this);
        const responsavelInput = document.getElementById('responsavel-input').value.trim();
        const salaIdInput = document.getElementById('sala-id-input').value;
        
        if (!responsavelInput) {
            showMessage("Por favor, digite o nome do responsável.", "error");
            return;
        }

        // Pega a data e hora exata da máquina do cliente
        const now = new Date();
        const dataReserva = now.toISOString().split('T')[0]; // Formato YYYY-MM-DD
        const horaInicio = now.toTimeString().substring(0, 8); // Formato HH:MM:SS

        const formDataToSend = new FormData();
        formDataToSend.append('sala_id', salaIdInput);
        formDataToSend.append('responsavel', responsavelInput);
        formDataToSend.append('controle', document.getElementById('controle-input').checked ? 1 : 0);
        formDataToSend.append('motivo', document.getElementById('motivo-input').value.trim() || "Sem observação.");
        formDataToSend.append('data_reserva', dataReserva); // Adicionado
        formDataToSend.append('hora_inicio', horaInicio); // Adicionado

        const response = await fetch('?action=register_occupant', { method: 'POST', body: formDataToSend });
        const result = await response.json();
        showMessage(result.message, result.success ? 'success' : 'error');
        if (result.success) { 
            fetchRooms(); 
            hideDetails(); 
            document.getElementById('responsavel-input').value = '';
        }
    });

    // Lógica para o formulário de admin
    document.getElementById('add-room-form')?.addEventListener('submit', async function(event) {
        event.preventDefault();
        const formData = new FormData(this);
        const response = await fetch('?action=add_room', { method: 'POST', body: formData });
        const result = await response.json();
        showMessage(result.message, result.success ? 'success' : 'error');
        if (result.success) { this.reset(); fetchRooms(); }
    });

    // Event listener para o botão de exportar
    exportExcelBtn?.addEventListener('click', () => {
        window.location.href = '?action=export_history';
    });

    // Função de inicialização
    async function init() {
        const response = await fetch('?action=check_session');
        const session = await response.json();
        if (session.logged_in) {
            localStorage.setItem('userRole', session.role);
            localStorage.setItem('username', session.username);
            showMainApp(session.username, session.role);
        } else {
            localStorage.removeItem('userRole');
            localStorage.removeItem('username');
            showLoginScreen();
        }
    }

    document.addEventListener('DOMContentLoaded', init);
</script>
</body>
</html>
