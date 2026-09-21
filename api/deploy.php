<?php
declare(strict_types=1);
/**
 * Canal de deploy autenticado. Recebe arquivos por POST assinado com HMAC do
 * APP_SEGREDO sobre o corpo inteiro — sem assinatura válida, 403.
 * Existe porque o deploy por cron da Hostinger é cego e pouco confiável.
 * Ver SEGURANCA.md: risco aceito e documentado; remover quando houver CI.
 */
require __DIR__ . '/config.php';
$corpo = file_get_contents('php://input') ?: '';
$assinatura = $_SERVER['HTTP_X_ASSINATURA'] ?? '';
if (!hash_equals(hash_hmac('sha256', $corpo, APP_SEGREDO), $assinatura)) {
    http_response_code(403);
    exit('nao autorizado');
}
header('Content-Type: application/json; charset=utf-8');
$d = json_decode($corpo, true);
$escritos = [];
foreach (($d['arquivos'] ?? []) as $rel => $b64) {
    if (str_contains($rel, '..')) continue;                 // nunca sair da pasta
    $destino = __DIR__ . '/' . ltrim($rel, '/');
    @mkdir(dirname($destino), 0755, true);
    // Os arquivos ficam somente-leitura: crons órfãos da Hostinger tentavam
    // acrescentar lixo neles a cada minuto. Só este canal pode alterá-los.
    @chmod($destino, 0644);
    file_put_contents($destino, base64_decode($b64));
    @chmod($destino, 0444);
    $escritos[$rel] = md5_file($destino);
}
echo json_encode(['ok' => true, 'escritos' => $escritos], JSON_UNESCAPED_SLASHES);
