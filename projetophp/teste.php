<?php
// sistema_uso_salas.php
// Este arquivo contém todo o código para o sistema de uso de salas.
// Configure as informações do seu banco de dados MySQL abaixo.

$servername = "localhost";
$username = "root";
$password = "123456";
$dbname = "bdreserva";

// Cria a conexão
$conn = new mysqli($servername, $username, $password, $dbname);

// Verifica a conexão
if ($conn->connect_error) {
    die("Falha na conexão com o banco de dados: " . $conn->connect_error);
}

// Lógica para processar o registro de uso da sala via POST (assíncrono)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'registrar') {
    $nome = $_POST['nome'];
    $sala = $_POST['sala'];
    $controle = isset($_POST['controle']) ? 1 : 0;
    $data_hora_entrada = date('Y-m-d H:i:s');

    // Prepara e executa a inserção no banco de dados
    $stmt = $conn->prepare("INSERT INTO uso_salas (nome_usuario, sala, controle, data_hora_entrada) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $nome, $sala, $controle, $data_hora_entrada);
    $response = [];

    if ($stmt->execute()) {
        $response['status'] = 'success';
        $response['message'] = 'Registro realizado com sucesso!';
    } else {
        $response['status'] = 'error';
        $response['message'] = 'Erro ao registrar: ' . $stmt->error;
    }

    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Lógica para buscar o histórico de uso
$sql = "SELECT * FROM uso_salas ORDER BY data_hora_entrada DESC";
$result = $conn->query($sql);
$historico = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $historico[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Uso de Salas</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f4f4f4;
            color: #333;
        }
        h1, h2 {
            color: #555;
            border-bottom: 2px solid #ccc;
            padding-bottom: 10px;
        }
        .container {
            max-width: 800px;
            margin: auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .salas-lista {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .sala-item {
            background-color: #007BFF;
            color: white;
            padding: 20px;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            font-size: 1.2em;
            transition: transform 0.2s, box-shadow 0.2s;
            flex: 1;
            min-width: 150px;
        }
        .sala-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        #registro-form {
            display: none; /* Inicialmente oculto */
            background: #e9e9e9;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        #registro-form label, #registro-form input[type="text"], #registro-form button {
            display: block;
            width: 100%;
            margin-bottom: 10px;
        }
        #registro-form input[type="text"] {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        #registro-form button {
            background-color: #28a745;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        #registro-form button:hover {
            background-color: #218838;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            color: #555;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .message-box {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            color: white;
            text-align: center;
            display: none;
        }
        .message-box.success {
            background-color: #28a745;
        }
        .message-box.error {
            background-color: #dc3545;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Sistema de Uso de Salas</h1>

    <h2>Salas Disponíveis</h2>
    <div class="salas-lista">
        <div class="sala-item" onclick="showForm('Sala de Reuniões')">Sala de Reuniões</div>
        <div class="sala-item" onclick="showForm('Sala de Treinamento')">Sala de Treinamento</div>
        <div class="sala-item" onclick="showForm('Laboratório de Informática')">Laboratório de Informática</div>
    </div>

    <div id="message-box" class="message-box"></div>

    <div id="registro-form">
        <h3>Registrar Ocupação da <span id="sala-nome-display"></span></h3>
        <form id="form-registro">
            <input type="hidden" id="sala-input" name="sala">
            <input type="hidden" name="action" value="registrar">
            
            <label for="nome">Nome do Ocupante:</label>
            <input type="text" id="nome-input" name="nome" placeholder="Seu nome" required>
            
            <label>
                <input type="checkbox" id="controle-input" name="controle" value="1">
                Controle
            </label>
            
            <button type="submit">Registrar Uso</button>
        </form>
    </div>

    <div class="historico">
        <h2>Histórico de Uso</h2>
        <?php if (!empty($historico)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Usuário</th>
                        <th>Sala</th>
                        <th>Controle</th>
                        <th>Data e Hora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historico as $registro): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($registro['nome_usuario']); ?></td>
                            <td><?php echo htmlspecialchars($registro['sala']); ?></td>
                            <td><?php echo $registro['controle'] ? 'Sim' : 'Não'; ?></td>
                            <td><?php echo htmlspecialchars($registro['data_hora_entrada']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhum registro de uso ainda.</p>
        <?php endif; ?>
    </div>

</div>

<script>
    // Função para mostrar o formulário e preencher o nome da sala
    function showForm(salaNome) {
        document.getElementById('sala-nome-display').innerText = salaNome;
        document.getElementById('sala-input').value = salaNome;
        document.getElementById('registro-form').style.display = 'block';
        document.getElementById('nome-input').focus();
    }

    // Função para exibir mensagens
    function showMessage(message, type) {
        const msgBox = document.getElementById('message-box');
        msgBox.innerText = message;
        msgBox.className = 'message-box ' + type;
        msgBox.style.display = 'block';
    }

    // Lógica para enviar o formulário via Fetch API (AJAX)
    document.getElementById('form-registro').addEventListener('submit', function(event) {
        event.preventDefault(); // Impede o envio padrão do formulário

        const formData = new FormData(this);
        
        fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showMessage(data.message, 'success');
                // Recarrega a página para atualizar o histórico após o sucesso
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Erro na requisição:', error);
            showMessage('Erro na comunicação com o servidor.', 'error');
        });
    });
</script>

<?php
$conn->close();
?>
