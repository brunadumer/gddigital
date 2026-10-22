<?php
// Recebe o formulário do site e envia o e-mail para a GD Digital.
// Configuração fica em config.php (fora do GitHub). Veja config.example.php.
header('X-Content-Type-Options: nosniff');

function responder($ok, $erro = '', $code = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'erro' => $erro], JSON_UNESCAPED_UNICODE);
    exit;
}

// Envio por SMTP autenticado. $log recebe o diálogo com o servidor (sem senhas).
function enviar_smtp($cfg, $assunto, $corpo, $nome, $email, &$log) {
    $host = $cfg['smtp_host'] ?? 'smtp.hostinger.com';
    $porta = (int) ($cfg['smtp_porta'] ?? 465);
    $log = [];
    $s = @stream_socket_client("ssl://$host:$porta", $en, $es, 15);
    if (!$s) { $log[] = "Sem conexão com $host:$porta ($en $es)"; return false; }
    stream_set_timeout($s, 15);
    $ler = function () use ($s) {
        $r = '';
        while (($l = fgets($s, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return trim($r);
    };
    $cmd = function ($c, $ok, $mostrar) use ($s, $ler, &$log) {
        if ($c !== null) { fwrite($s, $c . "\r\n"); $log[] = '> ' . $mostrar; }
        $r = $ler();
        $log[] = '< ' . $r;
        return strpos($r, (string) $ok) === 0;
    };
    $dominio = substr(strrchr($cfg['de'], '@'), 1);
    $usuario = $cfg['smtp_usuario'] ?? $cfg['de'];
    $okk = $cmd(null, 220, '')
        && $cmd('EHLO ' . $dominio, 250, 'EHLO ' . $dominio)
        && $cmd('AUTH LOGIN', 334, 'AUTH LOGIN')
        && $cmd(base64_encode($usuario), 334, '(usuario)')
        && $cmd(base64_encode($cfg['smtp_senha']), 235, '(senha)')
        && $cmd('MAIL FROM:<' . $cfg['de'] . '>', 250, 'MAIL FROM:<' . $cfg['de'] . '>')
        && $cmd('RCPT TO:<' . $cfg['para'] . '>', 250, 'RCPT TO:<' . $cfg['para'] . '>')
        && $cmd('DATA', 354, 'DATA');
    if (!$okk) { fclose($s); return false; }
    $msg  = 'From: =?UTF-8?B?' . base64_encode('GD Digital') . '?= <' . $cfg['de'] . ">\r\n";
    $msg .= 'To: <' . $cfg['para'] . ">\r\n";
    $msg .= 'Reply-To: =?UTF-8?B?' . base64_encode($nome) . '?= <' . $email . ">\r\n";
    $msg .= 'Subject: ' . $assunto . "\r\n";
    $msg .= 'Date: ' . date('r') . "\r\n";
    $msg .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $dominio . ">\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($corpo));
    fwrite($s, $msg . "\r\n.\r\n");
    $log[] = '> (mensagem)';
    $r = $ler();
    $log[] = '< ' . $r;
    fwrite($s, "QUIT\r\n");
    fclose($s);
    return strpos($r, '250') === 0;
}

$cfg = @include __DIR__ . '/config.php';

// Diagnóstico: abra /contato.php?teste=1 no navegador (só com 'debug' => true no config.php).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['teste'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!is_array($cfg) || empty($cfg['debug'])) { echo "Diagnostico desligado (config.php sem 'debug' => true).\n"; exit; }
    echo "contato.php versao SMTP-diagnostico\n";
    echo 'PHP ' . PHP_VERSION . "\n";
    echo 'para: ' . ($cfg['para'] ?? '(vazio)') . "\nde: " . ($cfg['de'] ?? '(vazio)') . "\n";
    echo 'smtp_senha: ' . (empty($cfg['smtp_senha']) ? 'AUSENTE' : 'definida (' . strlen($cfg['smtp_senha']) . " caracteres)") . "\n\n";
    if (empty($cfg['smtp_senha']) || empty($cfg['para']) || empty($cfg['de'])) { echo "Config incompleta.\n"; exit; }
    $ok = enviar_smtp($cfg, 'Teste GD Digital', "Teste de envio pelo diagnostico.\n", 'Teste', $cfg['de'], $log);
    echo implode("\n", $log) . "\n\nRESULTADO: " . ($ok ? 'ACEITO pelo servidor de e-mail' : 'FALHOU') . "\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método inválido.', 405);

if (!is_array($cfg) || empty($cfg['para']) || empty($cfg['de'])) responder(false, 'Servidor não configurado.', 500);

$dados = json_decode(file_get_contents('php://input'), true);
if (!is_array($dados)) responder(false, 'Dados inválidos.', 400);

// Campo-isca: robôs preenchem, pessoas não. Finge sucesso e descarta.
if (!empty($dados['armadilha'])) {
    error_log('contato.php: mensagem descartada pelo campo-isca');
    responder(true);
}

// Limita 5 envios por hora por IP.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$arq = sys_get_temp_dir() . '/gd_rate_' . md5($ip);
$agora = time();
$marcas = is_file($arq) ? array_filter(array_map('intval', explode(',', (string) file_get_contents($arq))), fn($t) => $t > $agora - 3600) : [];
if (count($marcas) >= 5) responder(false, 'Muitos envios seguidos. Tente mais tarde.', 429);

function limpar($v, $max) {
    $v = trim(strip_tags((string) $v));
    return mb_substr($v, 0, $max);
}
function sem_quebra($v) { return str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v); }

$nome      = sem_quebra(limpar($dados['nome'] ?? '', 100));
$empresa   = sem_quebra(limpar($dados['empresa'] ?? '', 100));
$email     = sem_quebra(limpar($dados['email'] ?? '', 150));
$interesse = sem_quebra(limpar($dados['interesse'] ?? '', 60));
$mensagem  = limpar($dados['mensagem'] ?? '', 3000);

if ($nome === '' || $mensagem === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(false, 'Preencha nome, e-mail válido e mensagem.', 422);
}

$assunto = '=?UTF-8?B?' . base64_encode("Novo contato pelo site: $nome") . '?=';
$corpo = "Novo contato pelo site da GD Digital\n\n"
       . "Nome: $nome\n"
       . "Empresa: " . ($empresa ?: '-') . "\n"
       . "E-mail: $email\n"
       . "Interesse: $interesse\n\n"
       . "Mensagem:\n$mensagem\n";

if (empty($cfg['smtp_senha']) || $cfg['smtp_senha'] === 'COLOQUE_A_SENHA_DO_EMAIL_AQUI') {
    error_log('contato.php: smtp_senha ausente no config.php');
    responder(false, 'Envio não configurado no servidor.', 500);
}
if (!enviar_smtp($cfg, $assunto, $corpo, $nome, $email, $log)) {
    error_log('contato.php: SMTP recusou: ' . implode(' | ', $log));
    responder(false, 'Falha ao enviar.', 500);
}

$marcas[] = $agora;
@file_put_contents($arq, implode(',', $marcas));
responder(true);
