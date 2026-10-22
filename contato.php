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

if (!mail($cfg['para'], $assunto, $corpo, $cab, '-f' . $cfg['de'])) {
    responder(false, 'Falha ao enviar.', 500);
}

$marcas[] = $agora;
@file_put_contents($arq, implode(',', $marcas));
responder(true);
