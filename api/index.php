<?php
declare(strict_types=1);
require __DIR__ . '/lib/nucleo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($o = origemPermitida()) {
        header('Access-Control-Allow-Origin: ' . $o);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
        header('Access-Control-Allow-Headers: Authorization,Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    http_response_code(204);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$caminho = preg_replace('#^/api#', '', $caminho) ?: '/';
$p = array_values(array_filter(explode('/', trim($caminho, '/'))));
$r = fn(int $i) => $p[$i] ?? null;

// ============================================================ AUTENTICAÇÃO
/** Desafio de segundo fator: assinado, curto, sem estado no banco. */
function desafioCriar(int $usuarioId): string
{
    $exp = time() + 300;
    $base = $usuarioId . '.' . $exp;
    return $base . '.' . hash_hmac('sha256', $base, APP_SEGREDO);
}
function desafioAbrir(string $d): ?int
{
    $x = explode('.', $d);
    if (count($x) !== 3) return null;
    [$id, $exp, $mac] = $x;
    if (!hash_equals(hash_hmac('sha256', "$id.$exp", APP_SEGREDO), $mac)) return null;
    if ((int) $exp < time()) return null;
    return (int) $id;
}

if ($r(0) === 'auth') {
    // ---------- POST /auth/login ----------
    if ($r(1) === 'login' && $metodo === 'POST') {
        $email = mb_strtolower(texto('email', 160));
        $senha = (string) campo('senha', '');
        $ip = ipCliente();
        // M2 do Buteco: três contadores, bloqueia pelo mais restritivo
        $chaves = ["login:conta:$email", "login:ip:$ip", "login:par:$email:$ip"];
        if (limiteBatido($chaves, 8, 900)) erro('Muitas tentativas. Tente novamente em alguns minutos.', 429);

        $u = umaLinha("SELECT u.*, p.nome FROM usuarios u JOIN pessoas p ON p.id = u.pessoa_id
                        WHERE u.email = ? AND u.ativo = 1", [$email]);
        // mensagem idêntica para conta inexistente e senha errada (sem enumeração)
        if (!$u || !$u['senha_hash'] || !password_verify($senha, $u['senha_hash'])) {
            limiteRegistrar($chaves, 900);
            auditar('usuarios', $u['id'] ?? null, 'login_falho');
            erro('E-mail ou senha incorretos.', 401);
        }
        if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
            q("UPDATE usuarios SET senha_hash = ? WHERE id = ?", [password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
        }
        if ((int) $u['totp_ativo'] === 1) {
            responder(['precisa_totp' => true, 'desafio' => desafioCriar((int) $u['id'])]);
        }
        limiteLimpar($chaves);
        auditar('usuarios', (int) $u['id'], 'login');
        responder(['token' => criarToken((int) $u['id']), 'precisa_ativar_totp' => totpObrigatorio($u)]);
    }

    // ---------- POST /auth/totp  (segundo passo do login) ----------
    if ($r(1) === 'totp' && $r(2) === null && $metodo === 'POST') {
        $id = desafioAbrir((string) campo('desafio', ''));
        if (!$id) erro('Desafio expirado. Faça o login de novo.', 401);
        $ip = ipCliente();
        $chaves = ["totp:conta:$id", "totp:ip:$ip"];
        // rate limiting NO PASSO DO TOTP também: 6 dígitos sem limite é forçável
        if (limiteBatido($chaves, 6, 900)) erro('Muitas tentativas. Tente novamente em alguns minutos.', 429);

        $u = umaLinha("SELECT * FROM usuarios WHERE id = ? AND ativo = 1", [$id]);
        if (!$u) erro('Conta indisponível.', 401);
        $codigo = preg_replace('/\D/', '', (string) campo('codigo', '')) ?? '';
        $segredo = decifrar($u['totp_segredo_cif']);

        $ok = false;
        if ($segredo && totpConfere($segredo, $codigo)) {
            // anti-replay: o mesmo código não vale duas vezes na janela
            if (hash_equals((string) ($u['totp_ultimo_codigo'] ?? ''), $codigo)) {
                erro('Esse código já foi usado. Espere o próximo.', 401);
            }
            q("UPDATE usuarios SET totp_ultimo_codigo = ? WHERE id = ?", [$codigo, $id]);
            $ok = true;
        } else {
            // código de recuperação (uso único)
            foreach (linhas("SELECT id, codigo_hash FROM usuario_recuperacao_totp WHERE usuario_id = ? AND usado_em IS NULL", [$id]) as $c) {
                if (password_verify(strtoupper(trim((string) campo('codigo', ''))), $c['codigo_hash'])) {
                    q("UPDATE usuario_recuperacao_totp SET usado_em = NOW() WHERE id = ?", [$c['id']]);
                    $ok = true;
                    auditar('usuarios', $id, 'totp_recuperacao_usada');
                    break;
                }
            }
        }
        if (!$ok) { limiteRegistrar($chaves, 900); auditar('usuarios', $id, 'totp_falho'); erro('Código inválido.', 401); }
        limiteLimpar($chaves);
        auditar('usuarios', $id, 'login_totp');
        responder(['token' => criarToken($id)]);
    }

    // ---------- GET /auth/eu ----------
    if ($r(1) === 'eu' && $metodo === 'GET') {
        $u = exigirLogin();
        $prof = umaLinha("SELECT id FROM professores WHERE pessoa_id = ?", [$u['pessoa_id']]);
        responder(['usuario' => [
            'id' => (int) $u['id'], 'nome' => $u['nome'], 'email' => $u['email'],
            'papeis' => papeisDe($u), 'totp_ativo' => (int) $u['totp_ativo'] === 1,
            'professor_id' => $prof['id'] ?? null,
            'totp_obrigatorio' => totpObrigatorio($u),
        ]]);
    }

    // ---------- POST /auth/logout ----------
    if ($r(1) === 'logout' && $metodo === 'POST') {
        $u = exigirLogin();
        q("DELETE FROM tokens WHERE id = ?", [$u['token_id']]);
        responder(['ok' => true]);
    }

    // ---------- POST /auth/totp/iniciar ----------
    if ($r(1) === 'totp' && $r(2) === 'iniciar' && $metodo === 'POST') {
        $u = exigirLogin();
        if ((int) $u['totp_ativo'] === 1) erro('O segundo fator já está ativo.', 409);
        // confirmar a senha antes de mexer em 2FA
        if (!$u['senha_hash'] || !password_verify((string) campo('senha', ''), $u['senha_hash'])) {
            erro('Confirme sua senha para ativar o segundo fator.', 401);
        }
        $segredo = totpSegredoNovo();
        q("UPDATE usuarios SET totp_segredo_cif = ? WHERE id = ?", [cifrar($segredo), $u['id']]);
        responder(['segredo' => $segredo, 'uri' => totpUri($u['email'], $segredo)]);
    }

    // ---------- POST /auth/totp/confirmar ----------
    if ($r(1) === 'totp' && $r(2) === 'confirmar' && $metodo === 'POST') {
        $u = exigirLogin();
        $segredo = decifrar($u['totp_segredo_cif']);
        if (!$segredo) erro('Comece a ativação do segundo fator antes de confirmar.', 409);
        if (!totpConfere($segredo, (string) campo('codigo', ''))) erro('Código inválido. Confira o relógio do celular.', 400);

        q("UPDATE usuarios SET totp_ativo = 1, totp_confirmado_em = NOW() WHERE id = ?", [$u['id']]);
        q("DELETE FROM usuario_recuperacao_totp WHERE usuario_id = ?", [$u['id']]);
        $codigos = [];
        for ($i = 0; $i < 10; $i++) {
            $c = strtoupper(bin2hex(random_bytes(4)));
            $codigos[] = $c;
            q("INSERT INTO usuario_recuperacao_totp (usuario_id, codigo_hash) VALUES (?,?)",
              [$u['id'], password_hash($c, PASSWORD_DEFAULT)]);
        }
        auditar('usuarios', (int) $u['id'], 'totp_ativado');
        responder(['ok' => true, 'codigos_recuperacao' => $codigos]);
    }

    // ---------- POST /auth/senha ----------
    if ($r(1) === 'senha' && $metodo === 'POST') {
        $u = exigirLogin();
        $nova = (string) campo('nova', '');
        if (!$u['senha_hash'] || !password_verify((string) campo('atual', ''), $u['senha_hash'])) erro('Senha atual incorreta.', 401);
        if (mb_strlen($nova) < 12) erro('A senha precisa de pelo menos 12 caracteres.', 400);
        if (in_array(mb_strtolower($nova), ['senha123456','123456789012','makrs2026makrs','qwertyuiop12'], true)) {
            erro('Essa senha é previsível demais. Escolha outra.', 400);
        }
        // M4 do Buteco: trocar a senha derruba as sessões abertas
        q("UPDATE usuarios SET senha_hash = ?, sessoes_validas_apos = NOW() WHERE id = ?",
          [password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
        q("DELETE FROM tokens WHERE usuario_id = ?", [$u['id']]);
        auditar('usuarios', (int) $u['id'], 'senha_trocada');
        responder(['ok' => true, 'token' => criarToken((int) $u['id'])]);
    }

    // ---------- Microsoft (Entra ID) ----------
    if ($r(1) === 'ms' && $r(2) === 'inicio' && $metodo === 'GET') {
        if (!defined('MS_LOGIN_CLIENT_ID') || MS_LOGIN_CLIENT_ID === '') erro('Login com Microsoft não configurado.', 503);
        $estado = bin2hex(random_bytes(16));
        $exp = time() + 600;
        $assinado = $estado . '.' . $exp . '.' . hash_hmac('sha256', "$estado.$exp", APP_SEGREDO);
        $url = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/authorize?'
             . http_build_query([
                 'client_id' => MS_LOGIN_CLIENT_ID, 'response_type' => 'code',
                 'redirect_uri' => APP_URL . '/api/auth/ms/callback',
                 'response_mode' => 'query', 'scope' => 'openid email profile', 'state' => $assinado,
             ]);
        header('Location: ' . $url);
        exit;
    }
    if ($r(1) === 'ms' && $r(2) === 'callback' && $metodo === 'GET') {
        $x = explode('.', (string) ($_GET['state'] ?? ''));
        if (count($x) !== 3 || !hash_equals(hash_hmac('sha256', "$x[0].$x[1]", APP_SEGREDO), $x[2]) || (int) $x[1] < time()) {
            erro('Estado inválido.', 400);
        }
        $ch = curl_init('https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => MS_LOGIN_CLIENT_ID, 'client_secret' => MS_LOGIN_CLIENT_SECRET,
                'code' => (string) ($_GET['code'] ?? ''), 'grant_type' => 'authorization_code',
                'redirect_uri' => APP_URL . '/api/auth/ms/callback', 'scope' => 'openid email profile',
            ])]);
        $tk = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $idt = $tk['id_token'] ?? '';
        $partes = explode('.', $idt);
        if (count($partes) !== 3) erro('Não foi possível validar a identidade.', 401);
        $claims = json_decode(base64_decode(strtr($partes[1], '-_', '+/')) ?: '', true) ?: [];
        if (($claims['tid'] ?? '') !== MS_TENANT_ID) erro('Conta de outro domínio.', 403);
        $email = mb_strtolower((string) ($claims['email'] ?? $claims['preferred_username'] ?? ''));
        $u = umaLinha("SELECT id FROM usuarios WHERE (ms_oid = ? OR email = ?) AND ativo = 1",
                      [$claims['oid'] ?? '', $email]);
        if (!$u) erro('Sua conta Microsoft não está vinculada ao Makrs Core.', 403);
        q("UPDATE usuarios SET ms_oid = ? WHERE id = ?", [$claims['oid'] ?? null, $u['id']]);
        auditar('usuarios', (int) $u['id'], 'login_microsoft');
        $t = criarToken((int) $u['id']);
        header('Location: ' . APP_URL . '/#/entrar?token=' . $t);
        exit;
    }
    erro('Rota de autenticação não encontrada.', 404);
}

function totpObrigatorio(array $u): bool
{
    if ((int) ($u['totp_ativo'] ?? 0) === 1) return false;
    return (bool) array_intersect(['socio', 'coordenacao', 'financeiro'], papeisDe($u));
}

// ============================================================ ROTA PÚBLICA
// O formulário de matrícula posta aqui. É a única rota sem login.
if ($r(0) === 'solicitacoes' && $r(1) === null && $metodo === 'POST' && !usuarioAtual()) {
    require __DIR__ . '/lib/rotas_admissao.php';
    solicitacaoPublica();
}

// ============================================================ MÓDULOS
require __DIR__ . '/lib/rotas_core.php';
require __DIR__ . '/lib/rotas_financeiro.php';
require __DIR__ . '/lib/rotas_admissao.php';

rotasCore($p, $metodo);
rotasFinanceiro($p, $metodo);
rotasAdmissao($p, $metodo);

erro('Rota não encontrada.', 404);
