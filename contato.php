<?php
// Recebe o formulário do site e envia o e-mail para a GD Digital.
// Configuração fica em config.php (fora do GitHub). Veja config.example.php.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function responder($ok, $erro = '', $code = 200) {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'erro' => $erro], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método inválido.', 405);

$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg) || empty($cfg['para']) || empty($cfg['de'])) responder(false, 'Servidor não configurado.', 500);

$dados = json_decode(file_get_contents('php://input'), true);
if (!is_array($dados)) responder(false, 'Dados inválidos.', 400);

// Campo-isca: robôs preenchem, pessoas não. Finge sucesso e descarta.
if (!empty($dados['site'])) responder(true);

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

$cab  = "From: GD Digital <{$cfg['de']}>\r\n";
$cab .= "Reply-To: $nome <$email>\r\n";
$cab .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";

// Envio por SMTP autenticado (confiável). Sem senha configurada, usa mail().
function enviar_smtp($cfg, $assunto, $corpo, $nome, $email) {
    $host = $cfg['smtp_host'] ?? 'smtp.hostinger.com';
    $porta = (int) ($cfg['smtp_porta'] ?? 465);
    $s = @stream_socket_client("ssl://$host:$porta", $en, $es, 15);
    if (!$s) return false;
    stream_set_timeout($s, 15);
    $ler = function () use ($s) {
        $r = '';
        while (($l = fgets($s, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $r;
    };
    $cmd = function ($c, $ok) use ($s, $ler) {
        if ($c !== null) fwrite($s, $c . "\r\n");
        $r = $ler();
        return strpos($r, (string) $ok) === 0;
    };
    $dominio = substr(strrchr($cfg['de'], '@'), 1);
    $okk = $cmd(null, 220)
        && $cmd('EHLO ' . $dominio, 250)
        && $cmd('AUTH LOGIN', 334)
        && $cmd(base64_encode($cfg['smtp_usuario'] ?? $cfg['de']), 334)
        && $cmd(base64_encode($cfg['smtp_senha']), 235)
        && $cmd('MAIL FROM:<' . $cfg['de'] . '>', 250)
        && $cmd('RCPT TO:<' . $cfg['para'] . '>', 250)
        && $cmd('DATA', 354);
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
    $fim = strpos($ler(), '250') === 0;
    fwrite($s, "QUIT\r\n");
    fclose($s);
    return $fim;
}

if (!empty($cfg['smtp_senha'])) {
    $enviado = enviar_smtp($cfg, $assunto, $corpo, $nome, $email);
} else {
    $enviado = mail($cfg['para'], $assunto, $corpo, $cab, '-f' . $cfg['de']);
}
if (!$enviado) {
    responder(false, 'Falha ao enviar.', 500);
}

$marcas[] = $agora;
@file_put_contents($arq, implode(',', $marcas));
responder(true);
