<?php
declare(strict_types=1);
/**
 * Instalador: cria o schema e semeia os dados de fundação.
 * Protegido por token derivado do APP_SEGREDO. Apagar após o uso.
 *   GET /api/instalar.php?t=<token>
 */
require __DIR__ . '/lib/nucleo.php';

$esperado = substr(hash_hmac('sha256', 'instalar', APP_SEGREDO), 0, 32);
if (!hash_equals($esperado, (string) ($_GET['t'] ?? ''))) { http_response_code(403); exit('nao'); }
header('Content-Type: text/plain; charset=utf-8');

// ---------- schema ----------
$sql = file_get_contents(__DIR__ . '/schema.sql');
$comandos = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
$ok = 0; $falhas = [];
foreach ($comandos as $c) {
    if ($c === '' || str_starts_with($c, '--')) continue;
    try { pdo()->exec($c); $ok++; }
    catch (Throwable $e) { $falhas[] = substr($e->getMessage(), 0, 160); }
}
echo "schema: $ok comandos ok\n";
foreach ($falhas as $f) echo "  falha: $f\n";

// ---------- plano de contas (o real da planilha) ----------
foreach ([
    ['Receita Bruta','receita',1,1], ['Outras Entradas','receita',1,2],
    ['Impostos s/ Venda','deducao',-1,3], ['Custos Variáveis','custo_servico',-1,4],
    ['Despesas Fixas','despesa_operacional',-1,5], ['Despesas Financeiras','resultado_financeiro',-1,6],
    ['Investimentos','investimento',-1,7], ['Saldo Inicial / Aporte','aporte',1,8],
] as $x) {
    q("INSERT IGNORE INTO plano_contas (nome, grupo_dre, sinal, ordem) VALUES (?,?,?,?)", $x);
}

// ---------- centros de custo (os reais) ----------
foreach (['Pagamento de Aluno','Professores','Contabilidade','Software','Comunicação','INSS',
          '6% do faturamento','Alvará','Rescisão contratual','Aporte inicial','Bônus docente',
          'Pró-labore','Marketing','Material didático','Indenização'] as $n) {
    q("INSERT IGNORE INTO centros_custo (nome) VALUES (?)", [$n]);
}

// ---------- cursos ----------
foreach ([['1A','Makrs Book 1A','book',1],['1B','Makrs Book 1B','book',2],
          ['2A','Makrs Book 2A','book',3],['2B','Makrs Book 2B','book',4],
          ['3A','Makrs Book 3A','book',5],['3B','Makrs Book 3B','book',6],
          ['CONV','Conversation Class','conversation',7],['TRAVEL','Travel Course','travel',8]] as $x) {
    q("INSERT IGNORE INTO cursos (codigo, nome, tipo, ordem) VALUES (?,?,?,?)", $x);
}

// ---------- parâmetros (regras confirmadas) ----------
foreach ([
    ['diferenca_valor_cheio','75','Valor cheio = promocional + R$75. Confirmado na Ficha de Admissão.','financeiro'],
    ['multa_rescisao_pct','20','Multa sobre parcelas restantes na fidelidade semestral','financeiro'],
    ['multa_atraso','70','Multa de atraso aplicada pelo InfinitePay (aprox.)','financeiro'],
    ['imposto_pct','6','Imposto sobre faturamento','financeiro'],
    ['horas_mensais','{"1x":4.5,"2x":9,"3x":13.5}','Média oficial Makrs por frequência semanal','academico'],
    ['dias_vencimento','[10,18,25]','Datas de vencimento concentradas','financeiro'],
    ['email_equipe','"joao@makrsschool.com"','Caixa compartilhada que recebe as confirmações','operacao'],
    ['convencao_class_id','{"formato":"AA+faixa+seq","grupo":["01","02","03","04"],"individual":["11","12","13","14"]}','Convenção do CLASS ID','academico'],
] as $x) {
    q("INSERT INTO parametros (chave, valor, descricao, categoria) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE descricao = VALUES(descricao)", $x);
}

// ---------- primeiro usuário ----------
$email = 'joao@makrsschool.com';
if (!umaLinha("SELECT id FROM usuarios WHERE email = ?", [$email])) {
    q("INSERT INTO pessoas (nome, nome_completo, email) VALUES ('João Gabriel','João Gabriel Santos Silva',?)", [$email]);
    $pid = ultimoId();
    $senha = bin2hex(random_bytes(8));
    q("INSERT INTO usuarios (pessoa_id, email, senha_hash, papeis) VALUES (?,?,?, 'socio,financeiro,coordenacao')",
      [$pid, $email, password_hash($senha, PASSWORD_DEFAULT)]);
    echo "\nUSUÁRIO CRIADO\n  e-mail: $email\n  senha provisória: $senha\n";
    echo "  (troque no primeiro acesso e ative o segundo fator)\n";
} else {
    echo "\nusuário já existe: $email\n";
}
echo "\npronto.\n";
