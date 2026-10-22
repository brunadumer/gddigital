<?php
// Copie este arquivo para config.php (no servidor) e ajuste.
// config.php NÃO vai para o GitHub.
return [
    // Quem recebe os contatos.
    'para' => 'contato@gddigital.com.br',
    // Remetente: precisa ser um e-mail do SEU domínio, criado na Hostinger
    // (ex.: contato@seudominio.com.br). Evita cair no spam.
    'de'   => 'contato@gddigital.com.br',
    // Senha do e-mail 'de' (a mesma do Webmail). Faz o envio chegar de verdade.
    'smtp_senha' => 'COLOQUE_A_SENHA_DO_EMAIL_AQUI',
    // Só para diagnosticar: true habilita /contato.php?teste=1. Depois volte para false.
    'debug' => false,
];
