<?php
declare(strict_types=1);

/**
 * Financeiro. Princípio: contas a pagar, contas a receber e caixa são
 * recortes da MESMA tabela `lancamentos`, separados por status.
 * DRE é regime de COMPETÊNCIA (previsto + pago); caixa é regime de CAIXA.
 */
function rotasFinanceiro(array $p, string $metodo): void
{
    $r = fn(int $i) => $p[$i] ?? null;
    if ($r(0) !== 'financeiro' && $r(0) !== 'cobranca') return;

    // ============================================================ INDICADORES
    if ($r(0) === 'financeiro' && $r(1) === 'indicadores' && $metodo === 'GET') {
        exigirFinanceiro();
        // consultas separadas de propósito: uma falha não derruba o painel inteiro,
        // e dá para ver no log qual delas quebrou.
        $n = function (string $sql, array $args = []) {
            try { return (float) (umaLinha($sql, $args)['v'] ?? 0); }
            catch (Throwable $e) { error_log('[indicadores] ' . $e->getMessage()); return 0.0; }
        };
        $mesPassado = date('Y-m-01', strtotime('first day of last month'));

        responder(['indicadores' => [
            'alunos_ativos'       => (int) $n("SELECT COUNT(*) v FROM alunos WHERE status='ativo'"),
            'alunos_total'        => (int) $n("SELECT COUNT(*) v FROM alunos"),
            'turmas_ativas'       => (int) $n("SELECT COUNT(*) v FROM turmas WHERE status='ativa'"),
            'mrr'                 => $n("SELECT COALESCE(SUM(valor_parcela),0) v FROM contratos
                                          WHERE status='assinado' AND data_expiracao >= CURDATE()"),
            'caixa'               => $n("SELECT COALESCE(SUM(IF(tipo='entrada',valor,-valor)),0) v
                                           FROM lancamentos WHERE status='pago'"),
            'a_pagar'             => $n("SELECT COALESCE(SUM(valor),0) v FROM lancamentos
                                          WHERE tipo='saida' AND status='previsto'"),
            'a_pagar_vencido'     => $n("SELECT COALESCE(SUM(valor),0) v FROM lancamentos
                                          WHERE tipo='saida' AND status='previsto' AND data_vencimento < CURDATE()"),
            'inadimplencia'       => $n("SELECT COALESCE(SUM(valor_previsto),0) v FROM parcelas
                                          WHERE status IN ('aberta','atrasada') AND data_vencimento < CURDATE()"),
            'inadimplentes'       => (int) $n("SELECT COUNT(DISTINCT aluno_id) v FROM parcelas
                                          WHERE status IN ('aberta','atrasada') AND data_vencimento < CURDATE()"),
            'contratos_vencendo'  => (int) $n("SELECT COUNT(*) v FROM contratos WHERE status='assinado'
                                          AND data_expiracao BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)"),
            'nf_pendentes'        => (int) $n("SELECT COUNT(*) v FROM notas_fiscais WHERE status='pendente'"),
            'solicitacoes_novas'  => (int) $n("SELECT COUNT(*) v FROM solicitacoes WHERE status IN ('nova','comercial')"),
            'sem_turma'           => (int) $n("SELECT COUNT(*) v FROM matriculas WHERE status='aguardando_turma'"),
            'lucro_mes_anterior'  => $n("SELECT COALESCE(SUM(l.valor * pc.sinal),0) v
                                           FROM lancamentos l JOIN plano_contas pc ON pc.id = l.plano_conta_id
                                          WHERE l.status <> 'cancelado' AND pc.grupo_dre <> 'aporte'
                                            AND l.data_competencia >= ? AND l.data_competencia < DATE_ADD(?, INTERVAL 1 MONTH)",
                                         [$mesPassado, $mesPassado]),
            'mes_anterior'        => $mesPassado,
        ]]);
    }

    // ============================================================ LANÇAMENTOS
    if ($r(0) === 'financeiro' && $r(1) === 'lancamentos' && $r(2) === null) {
        exigirFinanceiro();
        if ($metodo === 'GET') {
            $f = ['1=1']; $a = [];
            if (!empty($_GET['tipo']))   { $f[] = 'l.tipo = ?';   $a[] = $_GET['tipo']; }
            if (!empty($_GET['status'])) { $f[] = 'l.status = ?'; $a[] = $_GET['status']; }
            if (!empty($_GET['de']))     { $f[] = 'COALESCE(l.data_pagamento, l.data_vencimento) >= ?'; $a[] = $_GET['de']; }
            if (!empty($_GET['ate']))    { $f[] = 'COALESCE(l.data_pagamento, l.data_vencimento) <= ?'; $a[] = $_GET['ate']; }
            responder(['itens' => linhas(
                "SELECT l.*, pc.nome AS plano_conta, cc.nome AS centro_custo, p.nome AS pessoa,
                        (l.data_vencimento < CURDATE() AND l.status='previsto') AS vencida
                   FROM lancamentos l
                   LEFT JOIN plano_contas pc ON pc.id = l.plano_conta_id
                   LEFT JOIN centros_custo cc ON cc.id = l.centro_custo_id
                   LEFT JOIN pessoas p ON p.id = l.pessoa_id
                  WHERE " . implode(' AND ', $f) . "
                  ORDER BY COALESCE(l.data_pagamento, l.data_vencimento) DESC LIMIT 800", $a)]);
        }
        if ($metodo === 'POST') {
            $u = exigirFinanceiro();
            $tipo = campo('tipo') === 'entrada' ? 'entrada' : 'saida';
            $valor = decimal('valor');
            if (!$valor || $valor <= 0) erro('Informe um valor válido.', 400);
            $comp = dataOuNulo('data_competencia') ?? hoje();
            q("INSERT INTO lancamentos (tipo, descricao, fornecedor, data_competencia, data_vencimento,
                                        valor, plano_conta_id, centro_custo_id, status, origem, observacoes, criado_por)
               VALUES (?,?,?,?,?,?,?,?, 'previsto', 'manual', ?, ?)",
              [$tipo, texto('descricao'), texto('fornecedor', 160), $comp,
               dataOuNulo('data_vencimento') ?? $comp, $valor,
               inteiro('plano_conta_id'), inteiro('centro_custo_id'), texto('observacoes', 500), $u['id']]);
            $id = ultimoId();
            auditar('lancamentos', $id, 'insert', null, corpo());
            responder(['ok' => true, 'id' => $id], 201);
        }
    }

    // POST /financeiro/lancamentos/{id}/pagar — marcar pago É a entrada no caixa
    if ($r(0) === 'financeiro' && $r(1) === 'lancamentos' && ctype_digit((string) $r(2)) && $r(3) === 'pagar' && $metodo === 'POST') {
        exigirFinanceiro();
        $id = (int) $r(2);
        $antes = umaLinha("SELECT * FROM lancamentos WHERE id = ?", [$id]);
        if (!$antes) erro('Lançamento não encontrado.', 404);
        if ($antes['status'] === 'pago') erro('Este lançamento já está pago.', 409);
        q("UPDATE lancamentos SET status='pago', data_pagamento=?, forma_pagamento=? WHERE id=?",
          [dataOuNulo('data_pagamento') ?? hoje(), texto('forma_pagamento', 20) ?: 'pix', $id]);
        auditar('lancamentos', $id, 'pagar', $antes, umaLinha("SELECT * FROM lancamentos WHERE id=?", [$id]));
        responder(['ok' => true]);
    }

    // ============================================================ CAIXA
    if ($r(0) === 'financeiro' && $r(1) === 'caixa' && $metodo === 'GET') {
        exigirFinanceiro();
        $itens = linhas(
            "SELECT l.id, l.data_pagamento, l.tipo, l.descricao, l.valor, l.forma_pagamento, l.origem,
                    pc.nome AS plano_conta, cc.nome AS centro_custo, p.nome AS pessoa
               FROM lancamentos l
               LEFT JOIN plano_contas pc ON pc.id = l.plano_conta_id
               LEFT JOIN centros_custo cc ON cc.id = l.centro_custo_id
               LEFT JOIN pessoas p ON p.id = l.pessoa_id
              WHERE l.status='pago' ORDER BY l.data_pagamento, l.id");
        $saldo = 0.0;
        foreach ($itens as &$i) {
            $saldo += ($i['tipo'] === 'entrada' ? 1 : -1) * (float) $i['valor'];
            $i['saldo_acumulado'] = round($saldo, 2);
        }
        responder(['itens' => array_reverse($itens), 'saldo' => round($saldo, 2)]);
    }

    // ============================================================ DRE
    if ($r(0) === 'financeiro' && $r(1) === 'dre' && $metodo === 'GET') {
        exigirFinanceiro();
        // regime de competência: inclui previsto, exclui cancelado
        $bruto = linhas(
            "SELECT DATE_FORMAT(l.data_competencia,'%Y-%m-01') AS mes, pc.grupo_dre,
                    SUM(l.valor * pc.sinal) AS total,
                    SUM(IF(l.status='previsto', l.valor * pc.sinal, 0)) AS nao_liquidado
               FROM lancamentos l JOIN plano_contas pc ON pc.id = l.plano_conta_id
              WHERE l.status <> 'cancelado'
              GROUP BY 1, 2 ORDER BY 1");
        $meses = [];
        foreach ($bruto as $b) {
            $m = $b['mes'];
            $meses[$m] ??= ['mes' => $m, 'receita' => 0, 'deducoes' => 0, 'custos' => 0,
                            'despesas' => 0, 'financeiro' => 0, 'aporte' => 0, 'nao_liquidado' => 0];
            $mapa = ['receita' => 'receita', 'deducao' => 'deducoes', 'custo_servico' => 'custos',
                     'despesa_operacional' => 'despesas', 'resultado_financeiro' => 'financeiro',
                     'investimento' => 'despesas', 'aporte' => 'aporte'];
            $k = $mapa[$b['grupo_dre']] ?? 'despesas';
            $meses[$m][$k] += (float) $b['total'];
            if ($b['grupo_dre'] !== 'aporte') $meses[$m]['nao_liquidado'] += (float) $b['nao_liquidado'];
        }
        foreach ($meses as &$m) {
            $m['receita_liquida'] = $m['receita'] + $m['deducoes'];
            $m['margem_contribuicao'] = $m['receita_liquida'] + $m['custos'];
            $m['ebitda'] = $m['margem_contribuicao'] + $m['despesas'];
            $m['lucro_liquido'] = $m['ebitda'] + $m['financeiro'];
            foreach ($m as $k => $v) if (is_float($v)) $m[$k] = round($v, 2);
        }
        responder(['meses' => array_values($meses)]);
    }

    // ============================================================ PARCELAS
    if ($r(0) === 'financeiro' && $r(1) === 'parcelas' && $r(2) === null && $metodo === 'GET') {
        exigirFinanceiro();
        $f = ['1=1']; $a = [];
        if (!empty($_GET['status'])) { $f[] = 'pa.status = ?'; $a[] = $_GET['status']; }
        if (!empty($_GET['mes']))    { $f[] = 'pa.mes_referencia = ?'; $a[] = $_GET['mes']; }
        if (!empty($_GET['atrasadas'])) $f[] = "pa.status IN ('aberta','atrasada') AND pa.data_vencimento < CURDATE()";
        responder(['itens' => linhas(
            "SELECT pa.*, a.registro, p.nome, p.nome_completo, p.cpf,
                    DATEDIFF(CURDATE(), pa.data_vencimento) AS dias_atraso
               FROM parcelas pa
               JOIN alunos a ON a.id = pa.aluno_id
               JOIN pessoas p ON p.id = a.pessoa_id
              WHERE " . implode(' AND ', $f) . "
              ORDER BY pa.data_vencimento DESC LIMIT 800", $a)]);
    }

    // POST /financeiro/parcelas/{id}/receber
    if ($r(0) === 'financeiro' && $r(1) === 'parcelas' && ctype_digit((string) $r(2)) && $r(3) === 'receber' && $metodo === 'POST') {
        exigirFinanceiro();
        responder(['ok' => true, 'lancamento_id' => receberParcela((int) $r(2), decimal('valor_recebido'),
            dataOuNulo('data_pagamento') ?? hoje(), texto('forma_pagamento', 20) ?: 'pix')]);
    }

    // ============================================================ NOTAS FISCAIS
    if ($r(0) === 'financeiro' && $r(1) === 'nf' && $r(2) === null && $metodo === 'GET') {
        exigirFinanceiro();
        $f = ['1=1']; $a = [];
        if (!empty($_GET['status'])) { $f[] = 'nf.status = ?'; $a[] = $_GET['status']; }
        if (!empty($_GET['mes']))    { $f[] = 'nf.mes_referencia = ?'; $a[] = $_GET['mes']; }
        responder(['itens' => linhas(
            "SELECT nf.*, a.registro, p.nome_completo FROM notas_fiscais nf
               JOIN alunos a ON a.id = nf.aluno_id JOIN pessoas p ON p.id = a.pessoa_id
              WHERE " . implode(' AND ', $f) . " ORDER BY nf.mes_referencia DESC, p.nome_completo", $a)]);
    }
    if ($r(0) === 'financeiro' && $r(1) === 'nf' && ctype_digit((string) $r(2)) && $r(3) === 'emitir' && $metodo === 'POST') {
        exigirFinanceiro();
        $numero = texto('numero_nf', 20);
        if ($numero === '') erro('Informe o número da NF.', 400);
        $antes = umaLinha("SELECT * FROM notas_fiscais WHERE id = ?", [(int) $r(2)]);
        if (!$antes) erro('NF não encontrada.', 404);
        q("UPDATE notas_fiscais SET status='emitida', numero_nf=?, data_emissao=? WHERE id=?",
          [$numero, dataOuNulo('data_emissao') ?? hoje(), (int) $r(2)]);
        auditar('notas_fiscais', (int) $r(2), 'emitir', $antes, ['numero_nf' => $numero]);
        responder(['ok' => true]);
    }

    // ============================================================ COBRANÇA
    if ($r(0) === 'cobranca' && $r(1) === 'inadimplentes' && $metodo === 'GET') {
        exigirGestor();   // coordenação vê situação, para conseguir fechar encerramento
        responder(['itens' => linhas(
            "SELECT a.id AS aluno_id, a.registro, a.status AS aluno_status, p.nome, p.nome_completo,
                    p.email, p.telefone,
                    COUNT(pa.id) AS meses_devendo,
                    SUM(pa.valor_previsto) AS total_aberto,
                    MIN(pa.data_vencimento) AS desde,
                    MAX(DATEDIFF(CURDATE(), pa.data_vencimento)) AS dias_atraso,
                    GROUP_CONCAT(DATE_FORMAT(pa.mes_referencia,'%m/%Y') ORDER BY pa.mes_referencia SEPARATOR ', ') AS meses,
                    (SELECT COUNT(*) FROM comunicados_cobranca cc WHERE cc.aluno_id = a.id) AS comunicados,
                    (SELECT MAX(enviado_em) FROM comunicados_cobranca cc WHERE cc.aluno_id = a.id) AS ultimo_contato,
                    (SELECT estagio FROM comunicados_cobranca cc WHERE cc.aluno_id = a.id
                      ORDER BY enviado_em DESC LIMIT 1) AS estagio
               FROM parcelas pa
               JOIN alunos a ON a.id = pa.aluno_id
               JOIN pessoas p ON p.id = a.pessoa_id
              WHERE pa.status IN ('aberta','atrasada') AND pa.data_vencimento < CURDATE()
              GROUP BY a.id ORDER BY total_aberto DESC")]);
    }

    if ($r(0) === 'cobranca' && $r(1) === 'comunicados') {
        if ($metodo === 'GET') {
            exigirGestor();
            $f = ['1=1']; $a = [];
            if (!empty($_GET['aluno_id'])) { $f[] = 'cc.aluno_id = ?'; $a[] = (int) $_GET['aluno_id']; }
            responder(['itens' => linhas(
                "SELECT cc.*, a.registro, p.nome FROM comunicados_cobranca cc
                   JOIN alunos a ON a.id = cc.aluno_id JOIN pessoas p ON p.id = a.pessoa_id
                  WHERE " . implode(' AND ', $f) . " ORDER BY cc.enviado_em DESC LIMIT 300", $a)]);
        }
        if ($metodo === 'POST') {
            $u = exigirGestor();
            $aluno = inteiro('aluno_id');
            if (!$aluno) erro('Informe o aluno.', 400);
            q("INSERT INTO comunicados_cobranca (aluno_id, canal, estagio, assunto, corpo,
                        valor_em_aberto, meses_referencia, enviado_por)
               VALUES (?,?,?,?,?,?,?,?)",
              [$aluno, texto('canal', 20) ?: 'email', texto('estagio', 30) ?: 'aviso',
               texto('assunto'), (string) campo('corpo', ''), decimal('valor_em_aberto'),
               texto('meses_referencia', 120), $u['id']]);
            $id = ultimoId();
            auditar('comunicados_cobranca', $id, 'insert', null, ['aluno_id' => $aluno, 'estagio' => campo('estagio')]);
            responder(['ok' => true, 'id' => $id], 201);
        }
    }
}

/**
 * Recebe uma parcela e lança no caixa.
 * O valor recebido pode ser MAIOR que o previsto — a diferença é multa de atraso
 * (≈R$70–75 aplicada pelo InfinitePay). A NF sai pelo recebido, não pelo de tabela.
 */
function receberParcela(int $parcelaId, ?float $recebido, string $dataPg, string $forma): ?int
{
    $pa = umaLinha("SELECT * FROM parcelas WHERE id = ?", [$parcelaId]);
    if (!$pa) erro('Parcela não encontrada.', 404);
    if ($pa['status'] === 'paga') erro('Esta parcela já está paga.', 409);

    $recebido = $recebido ?? (float) $pa['valor_previsto'];
    $multa = max(0, round($recebido - (float) $pa['valor_previsto'], 2));

    $pessoa = umaLinha("SELECT pessoa_id FROM alunos WHERE id = ?", [$pa['aluno_id']]);
    $nome = umaLinha("SELECT nome FROM pessoas WHERE id = ?", [$pessoa['pessoa_id']])['nome'] ?? '';
    $conta = umaLinha("SELECT id FROM plano_contas WHERE nome = 'Receita Bruta'")['id'] ?? null;
    $cc = umaLinha("SELECT id FROM centros_custo WHERE nome = 'Pagamento de Aluno'")['id'] ?? null;

    q("INSERT INTO lancamentos (tipo, descricao, pessoa_id, data_competencia, data_vencimento,
                data_pagamento, valor, plano_conta_id, centro_custo_id, status, forma_pagamento, origem, ref_origem_id)
       VALUES ('entrada', ?, ?, ?, ?, ?, ?, ?, ?, 'pago', ?, 'contrato', ?)",
      [sprintf('Mensalidade %s - %s', date('m/Y', strtotime($pa['mes_referencia'])), $nome),
       $pessoa['pessoa_id'], $pa['mes_referencia'], $pa['data_vencimento'], $dataPg,
       $recebido, $conta, $cc, $forma, $parcelaId]);
    $lanc = ultimoId();

    q("UPDATE parcelas SET status='paga', valor_recebido=?, multa=?, data_pagamento=?,
              forma_pagamento=?, lancamento_id=? WHERE id=?",
      [$recebido, $multa, $dataPg, $forma, $lanc, $parcelaId]);
    auditar('parcelas', $parcelaId, 'receber', $pa, ['valor_recebido' => $recebido, 'multa' => $multa]);

    // NF nasce pendente, com a competência do SERVIÇO e o valor RECEBIDO
    $ja = umaLinha("SELECT id FROM notas_fiscais WHERE parcela_id = ?", [$parcelaId]);
    if (!$ja) {
        $dados = umaLinha("SELECT p.cpf, m.modalidade, m.horas_mes FROM alunos a
                             JOIN pessoas p ON p.id = a.pessoa_id
                             LEFT JOIN matriculas m ON m.aluno_id = a.id AND m.status='ativa'
                            WHERE a.id = ? LIMIT 1", [$pa['aluno_id']]);
        $desc = sprintf('Serviços de ensino de inglês - %s - %s por semana',
            $dados['modalidade'] ?? 'Individual',
            ((float) ($dados['horas_mes'] ?? 9) >= 9 ? '2h' : '1h'));
        q("INSERT INTO notas_fiscais (aluno_id, parcela_id, mes_referencia, cpf, descricao_servico, valor, status)
           VALUES (?,?,?,?,?,?, 'pendente')",
          [$pa['aluno_id'], $parcelaId, $pa['mes_referencia'], $dados['cpf'] ?? null, $desc, $recebido]);
    }
    return $lanc;
}
