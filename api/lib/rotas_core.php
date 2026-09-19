<?php
declare(strict_types=1);

/** Alunos, turmas, contratos e parâmetros. */
function rotasCore(array $p, string $metodo): void
{
    $r = fn(int $i) => $p[$i] ?? null;

    // ---------------------------------------------------------- PARÂMETROS
    if ($r(0) === 'parametros' && $metodo === 'GET') {
        exigirLogin();
        responder(['itens' => linhas("SELECT chave, valor, descricao, categoria FROM parametros ORDER BY categoria, chave")]);
    }

    // ---------------------------------------------------------- TURMAS
    if ($r(0) === 'turmas' && $r(1) === null && $metodo === 'GET') {
        $u = exigirLogin();
        $where = '';
        $args = [];
        if (!temPapel('socio', 'coordenacao', 'financeiro')) {
            $t = minhasTurmas();
            if (!$t) responder(['itens' => []]);
            $where = 'WHERE t.id IN (' . implode(',', array_fill(0, count($t), '?')) . ')';
            $args = $t;
        }
        responder(['itens' => linhas(
            "SELECT t.*, c.codigo AS curso, c.nome AS curso_nome, pp.nome_completo AS professor,
                    (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') AS alunos_ativos
               FROM turmas t
               LEFT JOIN cursos c ON c.id = t.curso_id
               LEFT JOIN professores pr ON pr.id = t.professor_id
               LEFT JOIN pessoas pp ON pp.id = pr.pessoa_id
               $where ORDER BY t.status, t.class_id DESC", $args)]);
    }

    if ($r(0) === 'turmas' && ctype_digit((string) $r(1)) && $r(2) === 'alunos' && $metodo === 'GET') {
        exigirLogin();
        $tid = (int) $r(1);
        if (!temPapel('socio', 'coordenacao', 'financeiro') && !in_array($tid, minhasTurmas(), true)) {
            erro('Sem permissão para esta turma.', 403);
        }
        responder(['itens' => linhas(
            "SELECT a.id, a.registro, a.status, p.nome, p.nome_completo, p.email, p.telefone,
                    m.modalidade, m.nivel_texto, a.link_material
               FROM matriculas m
               JOIN alunos a ON a.id = m.aluno_id
               JOIN pessoas p ON p.id = a.pessoa_id
              WHERE m.turma_id = ? AND m.status = 'ativa' ORDER BY p.nome", [$tid])]);
    }

    // ---------------------------------------------------------- ALUNOS
    if ($r(0) === 'alunos' && $r(1) === null && $metodo === 'GET') {
        exigirLogin();
        $filtros = ['1=1'];
        $args = [];
        if (!empty($_GET['status'])) { $filtros[] = 'a.status = ?'; $args[] = $_GET['status']; }
        if (!empty($_GET['busca'])) {
            $filtros[] = '(p.nome LIKE ? OR p.nome_completo LIKE ? OR a.registro LIKE ?)';
            $b = '%' . $_GET['busca'] . '%';
            array_push($args, $b, $b, $b);
        }
        // professor só vê os alunos das turmas dele
        if (!temPapel('socio', 'coordenacao', 'financeiro')) {
            $t = minhasTurmas();
            if (!$t) responder(['itens' => []]);
            $filtros[] = 'a.id IN (SELECT aluno_id FROM matriculas WHERE turma_id IN ('
                       . implode(',', array_fill(0, count($t), '?')) . '))';
            $args = array_merge($args, $t);
        }
        $verFin = temPapel('socio', 'coordenacao', 'financeiro');
        $colFin = $verFin
            ? "(SELECT COUNT(*) FROM parcelas pa WHERE pa.aluno_id = a.id AND pa.status IN ('aberta','atrasada') AND pa.data_vencimento < CURDATE()) AS parcelas_atrasadas,
               ct.valor_parcela, ct.dia_vencimento, ct.data_expiracao, ct.status AS contrato_status,"
            : "NULL AS parcelas_atrasadas, NULL AS valor_parcela, NULL AS dia_vencimento,
               NULL AS data_expiracao, NULL AS contrato_status,";
        responder(['itens' => linhas(
            "SELECT a.id, a.registro, a.status, a.data_entrada, a.e_menor,
                    p.nome, p.nome_completo, p.email, p.telefone, p.cidade,
                    m.modalidade, m.nivel_texto, m.horas_mes,
                    t.id AS turma_id, t.class_id AS turma, t.nome_curto AS turma_nome,
                    pp.nome_completo AS professor, $colFin
                    c.codigo AS curso
               FROM alunos a
               JOIN pessoas p ON p.id = a.pessoa_id
               LEFT JOIN matriculas m ON m.id = (SELECT m2.id FROM matriculas m2 WHERE m2.aluno_id = a.id
                                                  ORDER BY (m2.status='ativa') DESC, m2.data_inicio DESC LIMIT 1)
               LEFT JOIN turmas t ON t.id = m.turma_id
               LEFT JOIN cursos c ON c.id = m.curso_id
               LEFT JOIN professores pr ON pr.id = t.professor_id
               LEFT JOIN pessoas pp ON pp.id = pr.pessoa_id
               LEFT JOIN contratos ct ON ct.id = (SELECT c3.id FROM contratos c3 WHERE c3.aluno_id = a.id
                                                   ORDER BY c3.sequencia DESC LIMIT 1)
              WHERE " . implode(' AND ', $filtros) . "
              ORDER BY p.nome", $args)]);
    }

    // GET /alunos/{id} — autorização AO NÍVEL DO OBJETO
    if ($r(0) === 'alunos' && ctype_digit((string) $r(1)) && $r(2) === null && $metodo === 'GET') {
        $id = (int) $r(1);
        exigirVerAluno($id);
        $a = umaLinha(
            "SELECT a.*, p.nome, p.nome_completo, p.cpf, p.email, p.telefone, p.data_nascimento,
                    p.endereco, p.numero, p.cidade, p.estado, p.cep, p.pais
               FROM alunos a JOIN pessoas p ON p.id = a.pessoa_id WHERE a.id = ?", [$id]);
        if (!$a) erro('Aluno não encontrado.', 404);

        // dado sensível só para quem tem motivo
        if (!temPapel('socio', 'coordenacao', 'financeiro')) {
            unset($a['cpf'], $a['endereco'], $a['numero'], $a['cep'], $a['observacoes']);
        }
        $out = [
            'aluno' => $a,
            'matriculas' => linhas(
                "SELECT m.*, t.class_id AS turma, t.nome_curto AS turma_nome, c.codigo AS curso
                   FROM matriculas m LEFT JOIN turmas t ON t.id = m.turma_id
                   LEFT JOIN cursos c ON c.id = m.curso_id
                  WHERE m.aluno_id = ? ORDER BY m.data_inicio DESC", [$id]),
        ];
        if (temPapel('socio', 'coordenacao', 'financeiro')) {
            $out['contratos'] = linhas("SELECT * FROM contratos WHERE aluno_id = ? ORDER BY sequencia DESC", [$id]);
            $out['parcelas'] = linhas("SELECT * FROM parcelas WHERE aluno_id = ? ORDER BY mes_referencia", [$id]);
            $out['notas'] = linhas("SELECT * FROM notas_fiscais WHERE aluno_id = ? ORDER BY mes_referencia DESC", [$id]);
            $out['cobrancas'] = linhas("SELECT * FROM comunicados_cobranca WHERE aluno_id = ? ORDER BY enviado_em DESC", [$id]);
        }
        responder($out);
    }

    // PUT /alunos/{id}
    if ($r(0) === 'alunos' && ctype_digit((string) $r(1)) && $metodo === 'PUT') {
        exigirGestor();
        $id = (int) $r(1);
        $antes = umaLinha("SELECT a.*, p.* FROM alunos a JOIN pessoas p ON p.id = a.pessoa_id WHERE a.id = ?", [$id]);
        if (!$antes) erro('Aluno não encontrado.', 404);
        // lista branca — nunca gravar o corpo inteiro
        $camposPessoa = ['nome','nome_completo','cpf','email','telefone','data_nascimento','endereco','numero','cidade','estado','cep','pais'];
        $camposAluno  = ['status','data_saida','motivo_saida','e_menor','origem','link_pasta','link_material','observacoes'];
        $sets = []; $args = [];
        foreach ($camposPessoa as $c) if (array_key_exists($c, corpo())) { $sets[] = "$c = ?"; $args[] = campo($c); }
        if ($sets) { $args[] = $antes['pessoa_id']; q("UPDATE pessoas SET " . implode(',', $sets) . " WHERE id = ?", $args); }
        $sets = []; $args = [];
        foreach ($camposAluno as $c) if (array_key_exists($c, corpo())) { $sets[] = "$c = ?"; $args[] = campo($c); }
        if ($sets) { $args[] = $id; q("UPDATE alunos SET " . implode(',', $sets) . " WHERE id = ?", $args); }
        auditar('alunos', $id, 'update', $antes, corpo());
        responder(['ok' => true]);
    }

    // ---------------------------------------------------------- CONTRATOS
    if ($r(0) === 'contratos' && $r(1) === null && $metodo === 'GET') {
        exigirGestor();
        responder(['itens' => linhas(
            "SELECT ct.*, a.registro, p.nome, p.nome_completo,
                    DATEDIFF(ct.data_expiracao, CURDATE()) AS dias_restantes
               FROM contratos ct
               JOIN alunos a ON a.id = ct.aluno_id
               JOIN pessoas p ON p.id = a.pessoa_id
              ORDER BY ct.data_expiracao")]);
    }
}
