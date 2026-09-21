<?php
declare(strict_types=1);
/**
 * Importa o histórico das fontes atuais (Notion + planilhas do SharePoint).
 * Idempotente: pode rodar de novo — limpa o que importou antes e recarrega.
 * Protegido por HMAC do APP_SEGREDO sobre o corpo.
 */
require __DIR__ . '/lib/nucleo.php';

$corpo = file_get_contents('php://input') ?: '';
if (!hash_equals(hash_hmac('sha256', $corpo, APP_SEGREDO), $_SERVER['HTTP_X_ASSINATURA'] ?? '')) {
    http_response_code(403); exit('nao autorizado');
}
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

$d = json_decode($corpo, true);
$rel = fn(?string $v) => ($v === null || $v === '') ? null : $v;
$log = [];

pdo()->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (['processo_etapas','processos','parcelas','notas_fiscais','contrato_aditivos','contratos',
          'matriculas','responsaveis','alunos','turmas','lancamentos','solicitacoes'] as $t) {
    pdo()->exec("DELETE FROM $t");
}
pdo()->exec("DELETE FROM pessoas WHERE id NOT IN (SELECT pessoa_id FROM usuarios)");
pdo()->exec("DELETE FROM professores WHERE pessoa_id NOT IN (SELECT pessoa_id FROM usuarios)");
pdo()->exec("SET FOREIGN_KEY_CHECKS=1");

/** Professores: identidade única por nome completo. */
$profId = [];
foreach ($d['turmas'] ?? [] as $t) { if ($t['professor']) $profId[$t['professor']] = null; }
foreach ($d['alunos'] ?? [] as $a) { if ($a['professor']) $profId[$a['professor']] = null; }
foreach (array_keys($profId) as $nome) {
    $p = umaLinha("SELECT id FROM pessoas WHERE nome_completo = ?", [$nome]);
    if (!$p) {
        q("INSERT INTO pessoas (nome, nome_completo) VALUES (?,?)", [explode(' ', $nome)[0], $nome]);
        $pid = ultimoId();
    } else { $pid = (int) $p['id']; }
    $pr = umaLinha("SELECT id FROM professores WHERE pessoa_id = ?", [$pid]);
    if (!$pr) {
        q("INSERT INTO professores (pessoa_id, tipo_vinculo, data_entrada, ativo, e_coordenador)
           VALUES (?,?,?,1,?)",
          [$pid, in_array($nome, ['Marcus Merelli','Izabele Almeida'], true) ? 'socio' : 'pj',
           '2024-01-01', in_array($nome, ['Marcus Merelli','Thainá Judice'], true) ? 1 : 0]);
        $profId[$nome] = ultimoId();
    } else { $profId[$nome] = (int) $pr['id']; }
}
$log['professores'] = count($profId);

$cursoId = function (?string $nivel) {
    static $c = [];
    if (!$nivel) return null;
    if (array_key_exists($nivel, $c)) return $c[$nivel];
    $r = umaLinha("SELECT id FROM cursos WHERE nome = ?", [$nivel]);
    if (!$r && stripos($nivel, 'conversation') !== false) $r = umaLinha("SELECT id FROM cursos WHERE codigo='CONV'");
    if (!$r && stripos($nivel, 'travel') !== false) $r = umaLinha("SELECT id FROM cursos WHERE codigo='TRAVEL'");
    return $c[$nivel] = $r['id'] ?? null;
};

/** Turmas */
$turmaId = [];
$mapaStatus = ['active'=>'ativa','inactive'=>'pausada','finished'=>'encerrada','canceled'=>'cancelada'];
foreach ($d['turmas'] ?? [] as $t) {
    q("INSERT INTO turmas (class_id, nome_curto, nome, curso_id, professor_id, modalidade, horas_mes,
              dias, horario_inicio, horario_fim, vagas_total, status, data_inicio, data_encerramento)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$t['class_id'], $t['nome_curto'], trim(($t['nome_curto'] ?? '') . ' · ' . ($t['nivel'] ?? '')),
       $cursoId($t['nivel']), $profId[$t['professor']] ?? null, $t['modo'], $t['horas'],
       implode(',', $t['dias'] ?? []), $rel($t['h1']),
       $rel($t['h2']) ?? ($t['h1'] ? date('H:i', strtotime($t['h1']) + 3600) : null),
       $t['modo'] === 'Group' ? 6 : ($t['modo'] === 'Pair' ? 2 : 1),
       $mapaStatus[$t['status']] ?? 'cancelada', $rel($t['inicio']), $rel($t['fim'])]);
    $turmaId[$t['class_id']] = ultimoId();
}
$log['turmas'] = count($turmaId);

/** Alunos, pessoas e matrículas */
$alunoId = [];
foreach ($d['alunos'] ?? [] as $a) {
    $pid = null;
    if ($a['cpf']) { $p = umaLinha("SELECT id FROM pessoas WHERE cpf = ?", [$a['cpf']]); $pid = $p['id'] ?? null; }
    if (!$pid) {
        q("INSERT INTO pessoas (nome, nome_completo, cpf, email, telefone, data_nascimento,
                   endereco, cidade, estado, cep, origem_externa)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [$a['nome'], $a['nome_completo'], $rel($a['cpf']), $rel($a['email']), $rel($a['telefone']),
           $rel($a['nascimento']), $rel($a['endereco']), $rel($a['cidade']), $rel($a['estado']),
           $rel($a['cep']), 'reg:' . $a['registro']]);
        $pid = ultimoId();
    }
    $st = ['active'=>'ativo','suspended'=>'suspenso'][$a['status']] ?? 'inativo';
    q("INSERT INTO alunos (pessoa_id, registro, status, data_entrada, data_saida,
              service_type, tempo_ativo, observacoes)
       VALUES (?,?,?,?,?,?,?,?)",
      [$pid, $a['registro'], $st, $a['entrada'] ?? date('Y-m-d'), $rel($a['saida']),
       $rel($a['service_type']), $rel($a['tempo_ativo']), $rel($a['observacoes'])]);
    $aid = ultimoId();
    $alunoId[$a['registro']] = $aid;

    $tid = $a['class_id'] ? ($turmaId[$a['class_id']] ?? null) : null;
    q("INSERT INTO matriculas (aluno_id, curso_id, turma_id, modalidade, nivel_texto,
              horas_mes, data_inicio, data_fim, status)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$aid, $cursoId($a['nivel']), $tid, $rel($a['modo']), $rel($a['nivel']),
       $tid ? (umaLinha("SELECT horas_mes FROM turmas WHERE id=?", [$tid])['horas_mes'] ?? null) : null,
       $a['entrada'] ?? date('Y-m-d'), $rel($a['saida']),
       $st === 'ativo' ? ($tid ? 'ativa' : 'aguardando_turma') : 'encerrada']);
}
$log['alunos'] = count($alunoId);

/** Contratos — um aluno tem vários ao longo do tempo */
$nc = 0;
foreach ($d['contratos'] ?? [] as $c) {
    $aid = $alunoId[$c['registro']] ?? null;
    if (!$aid) continue;
    $mat = umaLinha("SELECT id FROM matriculas WHERE aluno_id=? ORDER BY data_inicio DESC LIMIT 1", [$aid]);
    $nParc = ['semestral'=>6,'trimestral'=>3,'mensal'=>1][$c['tipo']] ?? 6;
    $ini = $c['assinatura'] ?? umaLinha("SELECT data_entrada FROM alunos WHERE id=?", [$aid])['data_entrada'];
    q("INSERT INTO contratos (aluno_id, matricula_id, sequencia, contract_id, rotulo, tipo,
              valor_parcela, valor_cheio, num_parcelas, dia_vencimento, data_assinatura,
              data_inicio, data_expiracao, status, renovacao, link_assinatura, arquivo_pdf)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$aid, $mat['id'] ?? null, $c['seq'], $c['registro'], $c['titulo'], $c['tipo'],
       $c['valor'] ?? 0, $c['valor_cheio'], $nParc, $c['dia'] ?? 10, $rel($c['assinatura']),
       $ini, $c['expira'] ?? date('Y-m-d', strtotime($ini . " +$nParc months")),
       $c['status'], $c['renovacao'], $rel($c['link_pag']), $rel($c['link'])]);
    $nc++;
}
$log['contratos'] = $nc;

/** Lançamentos — a Movimentação é a fonte da verdade do que entrou e saiu */
$conta = []; $centro = [];
foreach (linhas("SELECT id, nome FROM plano_contas") as $r) $conta[$r['nome']] = (int) $r['id'];
foreach (linhas("SELECT id, nome FROM centros_custo") as $r) $centro[$r['nome']] = (int) $r['id'];
$nl = 0;
foreach ($d['lancamentos'] ?? [] as $m) {
    $pc = $conta[$m['cl']] ?? null;
    if (!$pc) continue;
    $cc = $centro[$m['cc']] ?? null;
    if (!$cc && $m['cc']) {
        foreach ($centro as $nome => $id) { if (stripos($m['cc'], $nome) !== false) { $cc = $id; break; } }
    }
    $tipo = in_array($m['cl'], ['Receita Bruta','Outras Entradas','Saldo Inicial / Aporte'], true) ? 'entrada' : 'saida';
    $pes = null;
    if ($m['nome']) {
        $p = umaLinha("SELECT id FROM pessoas WHERE nome_completo = ? OR nome = ? LIMIT 1", [$m['nome'], $m['nome']]);
        $pes = $p['id'] ?? null;
    }
    $pago = $m['st'] === 'pago';
    q("INSERT INTO lancamentos (tipo, descricao, pessoa_id, fornecedor, data_competencia,
              data_vencimento, data_pagamento, valor, plano_conta_id, centro_custo_id, status, origem)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [$tipo, $m['desc'] ?? $m['nome'] ?? 'Lançamento importado', $pes,
       $pes ? null : $m['nome'], $m['comp'], $m['pag'] ?? $m['comp'],
       $pago ? ($m['pag'] ?? $m['comp']) : null, $m['val'], $pc, $cc,
       $pago ? 'pago' : 'previsto',
       $m['cl'] === 'Saldo Inicial / Aporte' ? 'aporte'
         : ($m['cl'] === 'Impostos s/ Venda' ? 'imposto'
         : (($m['cc'] ?? '') === 'Professores' ? 'folha_docente'
         : ($m['cl'] === 'Receita Bruta' ? 'contrato' : 'manual')))]);
    $nl++;
}
$log['lancamentos'] = $nl;

echo json_encode(['ok' => true, 'importado' => $log], JSON_UNESCAPED_UNICODE);
