<?php
declare(strict_types=1);

/**
 * Núcleo da API do Makrs Core.
 * Credenciais vêm de config.php, gerado no deploy e nunca versionado.
 * Regras de segurança: ver frentes/andando/002-plataforma-gestao-makrs/SEGURANCA.md
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Servidor sem configuração.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require __DIR__ . '/../config.php';

// ----------------------------------------------------------------- BANCO
function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '-03:00'");
        } catch (PDOException $e) {
            error_log('DB: ' . $e->getMessage());
            responder(['erro' => 'Banco indisponível.'], 503);
        }
    }
    return $pdo;
}

/** Consulta preparada — o ÚNICO caminho para SQL. Nunca interpolar input. */
function q(string $sql, array $args = []): PDOStatement
{
    $st = pdo()->prepare($sql);
    $st->execute($args);
    return $st;
}
function umaLinha(string $sql, array $args = []): ?array { $r = q($sql, $args)->fetch(); return $r === false ? null : $r; }
function linhas(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function ultimoId(): int { return (int) pdo()->lastInsertId(); }

// ----------------------------------------------------------------- RESPOSTA
function origemPermitida(): ?string
{
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o === '') return null;
    if (defined('APP_URL') && $o === APP_URL) return $o;
    if (preg_match('#^https://[a-z0-9-]+\.makrsschool\.com$#', $o)) return $o;
    if (preg_match('#^http://localhost:\d+$#', $o)) return $o;
    if (preg_match('#^http://127\.0\.0\.1:\d+$#', $o)) return $o;
    return null;
}

function responder(array $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($origem = origemPermitida()) {
        header('Access-Control-Allow-Origin: ' . $origem);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Erro para o cliente é genérico; o detalhe vai para o log. */
function erro(string $mensagem, int $status = 400, ?string $detalheLog = null): void
{
    if ($detalheLog) error_log('[makrs-core] ' . $detalheLog);
    responder(['erro' => $mensagem], $status);
}

function corpo(): array
{
    static $c = null;
    if ($c === null) {
        $raw = file_get_contents('php://input') ?: '';
        $c = json_decode($raw, true);
        if (!is_array($c)) $c = $_POST ?: [];
    }
    return $c;
}
function campo(string $n, $padrao = null) { return corpo()[$n] ?? $padrao; }
function texto(string $n, int $max = 255): string
{
    $v = trim((string) (corpo()[$n] ?? ''));
    return mb_substr($v, 0, $max);
}
function inteiro(string $n): ?int { $v = corpo()[$n] ?? null; return ($v === null || $v === '') ? null : (int) $v; }
function decimal(string $n): ?float { $v = corpo()[$n] ?? null; return ($v === null || $v === '') ? null : (float) $v; }
function dataOuNulo(string $n): ?string
{
    $v = trim((string) (corpo()[$n] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
function hoje(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'); }
function agoraSql(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'); }

function ipCliente(): string
{
    // M3 do Buteco: atrás de CDN o REMOTE_ADDR pode ser o do CDN.
    // Só confiamos no cabeçalho se CDN_CONFIAVEL estiver ligado na config.
    if (defined('CDN_CONFIAVEL') && CDN_CONFIAVEL) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h]) && filter_var($_SERVER[$h], FILTER_VALIDATE_IP)) return $_SERVER[$h];
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ----------------------------------------------------------------- CIFRA
/** Cifra simétrica para segredo TOTP e dados bancários. */
function cifrar(string $claro): string
{
    $iv = random_bytes(16);
    $chave = hash('sha256', APP_SEGREDO, true);
    $c = openssl_encrypt($claro, 'aes-256-cbc', $chave, OPENSSL_RAW_DATA, $iv);
    return $iv . $c;
}
function decifrar(?string $cif): ?string
{
    if ($cif === null || strlen($cif) < 17) return null;
    $chave = hash('sha256', APP_SEGREDO, true);
    $r = openssl_decrypt(substr($cif, 16), 'aes-256-cbc', $chave, OPENSSL_RAW_DATA, substr($cif, 0, 16));
    return $r === false ? null : $r;
}

// ----------------------------------------------------------------- TOTP (RFC 6238)
function base32Codificar(string $bin): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $saida = '';
    foreach (str_split($bits, 5) as $pedaco) {
        $saida .= $alfabeto[bindec(str_pad($pedaco, 5, '0', STR_PAD_RIGHT))];
    }
    return $saida;
}
function base32Decodificar(string $b32): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
    $bits = '';
    foreach (str_split($b32) as $ch) {
        $p = strpos($alfabeto, $ch);
        if ($p === false) continue;
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $bin = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $bin .= chr(bindec($byte));
    return $bin;
}
function totpSegredoNovo(): string { return base32Codificar(random_bytes(20)); }

function totpCodigo(string $segredoB32, ?int $passo = null): string
{
    $passo = $passo ?? (int) floor(time() / 30);
    $bin = pack('N*', 0, $passo);
    $hash = hash_hmac('sha1', $bin, base32Decodificar($segredoB32), true);
    $off = ord($hash[19]) & 0xf;
    $n = ((ord($hash[$off]) & 0x7f) << 24) | ((ord($hash[$off + 1]) & 0xff) << 16)
       | ((ord($hash[$off + 2]) & 0xff) << 8) | (ord($hash[$off + 3]) & 0xff);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Aceita janela de ±1 período (30s). Mais que isso é afrouxar de graça. */
function totpConfere(string $segredoB32, string $codigo): bool
{
    $codigo = preg_replace('/\D/', '', $codigo) ?? '';
    if (strlen($codigo) !== 6) return false;
    $agora = (int) floor(time() / 30);
    for ($d = -1; $d <= 1; $d++) {
        if (hash_equals(totpCodigo($segredoB32, $agora + $d), $codigo)) return true;
    }
    return false;
}

function totpUri(string $email, string $segredo): string
{
    return 'otpauth://totp/' . rawurlencode('Makrs Core:' . $email)
         . '?secret=' . $segredo . '&issuer=' . rawurlencode('Makrs Core') . '&digits=6&period=30';
}

// ----------------------------------------------------------------- RATE LIMIT
/**
 * Três contadores simultâneos, bloqueando pelo mais restritivo.
 * Corrige o achado M2 do Buteco (chave email+ip não trava spraying).
 */
function limiteBatido(array $chaves, int $maximo, int $janelaSeg): bool
{
    $agora = agoraSql();
    q("DELETE FROM rate_limit WHERE janela_ate < ?", [$agora]);
    foreach ($chaves as $chave) {
        $r = umaLinha("SELECT tentativas FROM rate_limit WHERE chave = ? AND janela_ate >= ?", [$chave, $agora]);
        if ($r && (int) $r['tentativas'] >= $maximo) return true;
    }
    return false;
}
function limiteRegistrar(array $chaves, int $janelaSeg): void
{
    $ate = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
        ->modify("+{$janelaSeg} seconds")->format('Y-m-d H:i:s');
    foreach ($chaves as $chave) {
        q("INSERT INTO rate_limit (chave, tentativas, janela_ate) VALUES (?, 1, ?)
           ON DUPLICATE KEY UPDATE tentativas = tentativas + 1,
             janela_ate = IF(janela_ate < NOW(), VALUES(janela_ate), janela_ate)", [$chave, $ate]);
    }
}
function limiteLimpar(array $chaves): void
{
    foreach ($chaves as $chave) q("DELETE FROM rate_limit WHERE chave = ?", [$chave]);
}

// ----------------------------------------------------------------- SESSÃO
function criarToken(int $usuarioId, int $horas = 12): string
{
    $token = bin2hex(random_bytes(32));
    $exp = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
        ->modify("+{$horas} hours")->format('Y-m-d H:i:s');
    q("INSERT INTO tokens (usuario_id, token_hash, expira_em, ip, agente) VALUES (?,?,?,?,?)",
      [$usuarioId, hash('sha256', $token), $exp, ipCliente(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
    return $token;
}

/**
 * Usuário da requisição. Relido do banco sempre — permissões NUNCA saem da sessão,
 * para que revogar acesso tenha efeito imediato.
 */
function usuarioAtual(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;

    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m)) return $cache = null;

    $linha = umaLinha(
        "SELECT t.id AS token_id, t.criado_em AS sessao_criada, t.ultima_atividade,
                u.*, p.nome, p.nome_completo
           FROM tokens t
           JOIN usuarios u ON u.id = t.usuario_id
           JOIN pessoas  p ON p.id = u.pessoa_id
          WHERE t.token_hash = ? AND t.expira_em > NOW()",
        [hash('sha256', $m[1])]
    );
    if (!$linha) return $cache = null;

    // conta desativada entre requisições: a sessão morre agora
    if ((int) $linha['ativo'] !== 1) { q("DELETE FROM tokens WHERE id = ?", [$linha['token_id']]); return $cache = null; }

    // M4 do Buteco: troca de senha invalida sessões abertas
    if (strtotime((string) $linha['sessao_criada']) < strtotime((string) $linha['sessoes_validas_apos'])) {
        q("DELETE FROM tokens WHERE id = ?", [$linha['token_id']]);
        return $cache = null;
    }

    // M5 do Buteco: expiração por inatividade
    $inatividade = in_array('financeiro', papeisDe($linha), true) || in_array('socio', papeisDe($linha), true) ? 4 * 3600 : 8 * 3600;
    if (time() - strtotime((string) $linha['ultima_atividade']) > $inatividade) {
        q("DELETE FROM tokens WHERE id = ?", [$linha['token_id']]);
        return $cache = null;
    }
    q("UPDATE tokens SET ultima_atividade = NOW() WHERE id = ?", [$linha['token_id']]);
    q("UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = ?", [$linha['id']]);

    return $cache = $linha;
}

function papeisDe(array $u): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) ($u['papeis'] ?? '')))));
}

// ----------------------------------------------------------------- GUARD
/**
 * Toda rota protegida passa por aqui. Esconder o item do menu não é controle
 * de acesso: URL colado no navegador tem que devolver 403.
 */
function exigirLogin(): array
{
    $u = usuarioAtual();
    if (!$u) erro('Sessão expirada ou inválida.', 401);
    return $u;
}
function temPapel(string ...$papeis): bool
{
    $u = usuarioAtual();
    if (!$u) return false;
    return (bool) array_intersect($papeis, papeisDe($u));
}
function exigirPapel(string ...$papeis): array
{
    $u = exigirLogin();
    if (!array_intersect($papeis, papeisDe($u))) erro('Sem permissão para esta operação.', 403);
    return $u;
}
function exigirSocio(): array { return exigirPapel('socio'); }
function exigirFinanceiro(): array { return exigirPapel('socio', 'financeiro'); }
function exigirGestor(): array { return exigirPapel('socio', 'coordenacao'); }

/** Turmas que o professor logado conduz. Base da autorização ao nível do objeto. */
function minhasTurmas(): array
{
    $u = usuarioAtual();
    if (!$u) return [];
    $r = linhas("SELECT t.id FROM turmas t
                   JOIN professores pr ON pr.id = t.professor_id
                  WHERE pr.pessoa_id = ?", [$u['pessoa_id']]);
    return array_map(fn($x) => (int) $x['id'], $r);
}

/**
 * Autorização ao nível do OBJETO — o achado A1 do Buteco.
 * Não basta "pode ver aluno?"; tem que ser "pode ver ESTE aluno?".
 */
function podeVerAluno(int $alunoId): bool
{
    if (temPapel('socio', 'coordenacao', 'financeiro')) return true;
    $turmas = minhasTurmas();
    if (!$turmas) return false;
    $in = implode(',', array_fill(0, count($turmas), '?'));
    $r = umaLinha("SELECT 1 AS ok FROM matriculas WHERE aluno_id = ? AND turma_id IN ($in) LIMIT 1",
                  array_merge([$alunoId], $turmas));
    return (bool) $r;
}
function exigirVerAluno(int $alunoId): void
{
    exigirLogin();
    if (!podeVerAluno($alunoId)) erro('Sem permissão para este aluno.', 403);
}

// ----------------------------------------------------------------- AUDITORIA
function auditar(string $tabela, ?int $registroId, string $acao, ?array $antes = null, ?array $depois = null): void
{
    $u = usuarioAtual();
    q("INSERT INTO auditoria (tabela, registro_id, acao, antes, depois, usuario_id, ip)
       VALUES (?,?,?,?,?,?,?)",
      [$tabela, $registroId, $acao,
       $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
       $depois === null ? null : json_encode($depois, JSON_UNESCAPED_UNICODE),
       $u['id'] ?? null, ipCliente()]);
}

// ----------------------------------------------------------------- PARÂMETROS
function parametro(string $chave, $padrao = null)
{
    static $cache = [];
    if (array_key_exists($chave, $cache)) return $cache[$chave];
    $r = umaLinha("SELECT valor FROM parametros WHERE chave = ?", [$chave]);
    if (!$r) return $cache[$chave] = $padrao;
    $d = json_decode($r['valor'], true);
    return $cache[$chave] = ($d === null ? $r['valor'] : $d);
}

// ----------------------------------------------------------------- E-MAIL (Graph)
function tokenGraph(): ?string
{
    static $tok = null;
    if ($tok !== null) return $tok;
    if (!defined('MS_TENANT_ID') || MS_TENANT_ID === '') return null;
    $ch = curl_init("https://login.microsoftonline.com/" . MS_TENANT_ID . "/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => MS_CLIENT_ID, 'client_secret' => MS_CLIENT_SECRET,
            'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
        ]),
    ]);
    $r = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return $tok = ($r['access_token'] ?? null);
}

function enviarEmail(string $para, string $assunto, string $html, array $cc = []): bool
{
    $t = tokenGraph();
    if (!$t) { error_log('Graph sem token'); return false; }
    $msg = ['message' => [
        'subject' => $assunto,
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => [['emailAddress' => ['address' => $para]]],
    ], 'saveToSentItems' => true];
    foreach ($cc as $e) $msg['message']['ccRecipients'][] = ['emailAddress' => ['address' => $e]];
    $ch = curl_init('https://graph.microsoft.com/v1.0/users/' . rawurlencode(MS_SENDER) . '/sendMail');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($msg, JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($st >= 300) error_log("sendMail HTTP $st");
    return $st < 300;
}

/** Papel timbrado da Makrs — roxo #3B3789, acento amarelo #F5C400. */
function templateEmail(string $titulo, string $corpoHtml, string $link = '', string $rotulo = ''): string
{
    $botao = $link ? '<tr><td style="padding:22px 0 4px"><a href="' . htmlspecialchars($link, ENT_QUOTES) . '"
        style="background:#F5C400;color:#1A1608;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:14px;display:inline-block">'
        . htmlspecialchars($rotulo ?: 'Abrir', ENT_QUOTES) . '</a></td></tr>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f4f4f8;font-family:Poppins,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 14px">
<table width="100%" style="max-width:560px;background:#3B3789;border-radius:22px;padding:34px" cellpadding="0" cellspacing="0">
<tr><td><img src="https://daily.makrsschool.com/assets/logo-mark-white.png" alt="Makrs" height="30"></td></tr>
<tr><td style="color:#fff;font-size:21px;font-weight:700;padding-top:20px">' . htmlspecialchars($titulo, ENT_QUOTES) . '</td></tr>
<tr><td style="color:#E4E2F5;font-size:15px;line-height:1.65;padding-top:12px">' . $corpoHtml . '</td></tr>'
. $botao .
'<tr><td style="color:#c9c6e8;font-size:12px;padding-top:26px;border-top:1px solid rgba(255,255,255,.14);margin-top:20px">
Makrs Language School · makrsschool.com</td></tr>
</table></td></tr></table></body></html>';
}
