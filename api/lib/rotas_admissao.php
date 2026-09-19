<?php
declare(strict_types=1);

/**
 * Admissão e encerramento.
 * A entrada tem DUAS metades: o formulário traz o cadastral; quem fechou a venda
 * traz o comercial (valor, desconto, turma, datas). Só depois disso nasce o aluno.
 */

/** Rota pública: o formulário do site posta aqui. Sem login, com rate limiting. */
function solicitacaoPublica(): void
{
    $ip = ipCliente();
    if (limiteBatido(["form:ip:$ip"], 6, 3600)) erro('Muitas solicitações. Tente mais tarde.', 429);
    limiteRegistrar(["form:ip:$ip"], 3600);

    $nome = texto('nome_completo', 200);
    $email = mb_strtolower(texto('email', 160));
    if (mb_strlen($nome) < 5) erro('Informe o nome completo.', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Informe um e-mail válido.', 400);
    if (texto('site', 60) !== '') responder(['ok' => true]);  // honeypot: finge que aceitou

    q("INSERT INTO solicitacoes (nome_completo, cpf, data_nascimento, e_menor, responsavel_nome,
            responsavel_cpf, telefone, email, endereco, numero, cidade, estado, cep, pais,
            professor_pref, modalidade, dias_horarios, dia_vencimento, tipo_contrato,
            como_conheceu, observacoes, origem, status)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'formulario', 'nova')",
      [$nome, texto('cpf', 20), dataOuNulo('data_nascimento'),
       campo('e_menor') ? 1 : 0, texto('responsavel_nome', 200), texto('responsavel_cpf', 20),
       texto('telefone', 30), $email, texto('endereco', 200), texto('numero', 20),
       texto('cidade', 100), texto('estado', 80), texto('cep', 20), texto('pais', 60) ?: 'Brasil',
       texto('professor_pref', 120), texto('modalidade', 20), (string) campo('dias_horarios', ''),
       inteiro('dia_vencimento'), texto('tipo_contrato', 20),
       texto('como_conheceu', 160), (string) campo('observacoes', '')]);
    $id = ultimoId();

    // e-mail para a caixa compartilhada, só para registro
    $destino = parametro('email_equipe', 'joao@makrsschool.com');
    enviarEmail((string) $destino, "[Makrs Core] Nova solicitação de matrícula — $nome",
        templateEmail('Nova solicitação de matrícula',
            '<p>Chegou uma nova solicitação pelo formulário do site.</p>'
            . '<p><strong>' . htmlspecialchars($nome, ENT_QUOTES) . '</strong><br>'
            . htmlspecialchars($email, ENT_QUOTES) . '</p>'
            . '<p>Falta completar a parte comercial (valor, turma e datas) para gerar o contrato.</p>',
            (defined('APP_URL') ? APP_URL : '') . '/#/admissoes', 'Abrir no Makrs Core'));

    responder(['ok' => true, 'id' => $id, 'mensagem' => 'Recebemos seus dados. Em breve entramos em contato.'], 201);
}

function rotasAdmissao(array $p, string $metodo): void
{
    $r = fn(int $i) => $p[$i] ?? null;

    // ---------------------------------------------------------- SOLICITAÇÕES
    if ($r(0) === 'solicitacoes' && $r(1) === null && $metodo === 'GET') {
        exigirGestor();
        $f = ['1=1']; $a = [];
        if (!empty($_GET['status'])) { $f[] = 's.status = ?'; $a[] = $_GET['status']; }
        responder(['itens' => linhas(
            "SELECT s.*, t.class_id AS turma, c.codigo AS curso
               FROM solicitacoes s
               LEFT JOIN turmas t ON t.id = s.turma_id
               LEFT JOIN cursos c ON c.id = s.curso_id
              WHERE " . implode(' AND ', $f) . " ORDER BY s.criado_em DESC", $a)]);
    }

    if ($r(0) === 'solicitacoes' && ctype_digit((string) $r(1)) && $r(2) === null && $metodo === 'GET') {
        exigirGestor();
        $s = umaLinha("SELECT * FROM solicitacoes WHERE id = ?", [(int) $r(1)]);
        if (!$s) erro('Solicitação não encontrada.', 404);
        responder(['solicitacao' => $s]);
    }

    // PUT /solicitacoes/{id}/comercial — a segunda metade da entrada
    if ($r(0) === 'solicitacoes' && ctype_digit((string) $r(1)) && $r(2) === 'comercial' && $metodo === 'PUT') {
        exigirGestor();
        $id = (int) $r(1);
        $antes = umaLinha("SELECT * FROM solicitacoes WHERE id = ?", [$id]);
        if (!$antes) erro('Solicitação não encontrada.', 404);

        $valor = decimal('valor_parcela');
        // regra confirmada: valor cheio = promocional + 75
        $cheio = decimal('valor_cheio') ?? ($valor !== null ? $valor + (float) parametro('diferenca_valor_cheio', 75) : null);

        q("UPDATE solicitacoes SET valor_parcela=?, valor_cheio=?, desconto_motivo=?, turma_id=?,
                  curso_id=?, data_inicio=?, data_encerramento=?, primeira_parcela=?,
                  dia_vencimento=?, tipo_contrato=?, obs_contrato=?, status='comercial'
            WHERE id=?",
          [$valor, $cheio, texto('desconto_motivo', 200), inteiro('turma_id'), inteiro('curso_id'),
           dataOuNulo('data_inicio'), dataOuNulo('data_encerramento'), dataOuNulo('primeira_parcela'),
           inteiro('dia_vencimento') ?? $antes['dia_vencimento'], texto('tipo_contrato', 20) ?: $antes['tipo_contrato'],
           (string) campo('obs_contrato', ''), $id]);
        auditar('solicitacoes', $id, 'comercial', $antes, corpo());
        responder(['ok' => true]);
    }

    // POST /solicitacoes/{id}/converter — gera registro, aluno, contrato, parcelas e checklist
    if ($r(0) === 'solicitacoes' && ctype_digit((string) $r(1)) && $r(2) === 'converter' && $metodo === 'POST') {
        $u = exigirGestor();
        $id = (int) $r(1);
        $s = umaLinha("SELECT * FROM solicitacoes WHERE id = ?", [$id]);
        if (!$s) erro('Solicitação não encontrada.', 404);
        if ($s['status'] === 'convertida') erro('Esta solicitação já virou aluno.', 409);
        if ($s['valor_parcela'] === null || !$s['data_inicio']) {
            erro('Complete a parte comercial (valor e datas) antes de converter.', 400);
        }

        pdo()->beginTransaction();
        try {
            // pessoa (reaproveita se o CPF já existe — reativação mantém o registro)
            $pessoa = $s['cpf'] ? umaLinha("SELECT id FROM pessoas WHERE cpf = ?", [$s['cpf']]) : null;
            if ($pessoa) {
                $pessoaId = (int) $pessoa['id'];
                q("UPDATE pessoas SET nome_completo=?, email=?, telefone=? WHERE id=?",
                  [$s['nome_completo'], $s['email'], $s['telefone'], $pessoaId]);
            } else {
                $primeiro = explode(' ', trim((string) $s['nome_completo']))[0];
                q("INSERT INTO pessoas (nome, nome_completo, cpf, email, telefone, data_nascimento,
                        endereco, numero, cidade, estado, cep, pais)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                  [$primeiro, $s['nome_completo'], $s['cpf'] ?: null, $s['email'], $s['telefone'],
                   $s['data_nascimento'], $s['endereco'], $s['numero'], $s['cidade'],
                   $s['estado'], $s['cep'], $s['pais']]);
                $pessoaId = ultimoId();
            }

            // aluno: reativação mantém o registro original
            $aluno = umaLinha("SELECT * FROM alunos WHERE pessoa_id = ?", [$pessoaId]);
            if ($aluno) {
                $alunoId = (int) $aluno['id'];
                $registro = $aluno['registro'];
                q("UPDATE alunos SET status='ativo', data_saida=NULL, motivo_saida=NULL WHERE id=?", [$alunoId]);
            } else {
                $registro = proximoRegistro();
                q("INSERT INTO alunos (pessoa_id, registro, status, data_entrada, e_menor, origem)
                   VALUES (?,?, 'ativo', ?, ?, ?)",
                  [$pessoaId, $registro, $s['data_inicio'], (int) $s['e_menor'], $s['como_conheceu']]);
                $alunoId = ultimoId();
            }

            $turma = $s['turma_id'] ? umaLinha("SELECT * FROM turmas WHERE id = ?", [(int) $s['turma_id']]) : null;
            q("INSERT INTO matriculas (aluno_id, curso_id, turma_id, modalidade, horas_mes, data_inicio, data_fim, status)
               VALUES (?,?,?,?,?,?,?, ?)",
              [$alunoId, $s['curso_id'] ?: ($turma['curso_id'] ?? null), $s['turma_id'] ?: null,
               $s['modalidade'] ?: ($turma['modalidade'] ?? null), $turma['horas_mes'] ?? null,
               $s['data_inicio'], $s['data_encerramento'],
               $s['turma_id'] ? 'ativa' : 'aguardando_turma']);
            $matriculaId = ultimoId();

            $seq = (int) (umaLinha("SELECT COALESCE(MAX(sequencia),0)+1 s FROM contratos WHERE aluno_id=?", [$alunoId])['s'] ?? 1);
            $nParc = $s['tipo_contrato'] === 'mensal' ? 1 : ($s['tipo_contrato'] === 'trimestral' ? 3 : 6);
            q("INSERT INTO contratos (aluno_id, matricula_id, sequencia, rotulo, tipo, valor_parcela,
                        valor_cheio, desconto_motivo, num_parcelas, dia_vencimento, data_inicio,
                        data_expiracao, status, clausulas_especiais)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'a_fazer', ?)",
              [$alunoId, $matriculaId, $seq, $registro, $s['tipo_contrato'] ?: 'semestral',
               $s['valor_parcela'], $s['valor_cheio'], $s['desconto_motivo'], $nParc,
               $s['dia_vencimento'] ?: 10, $s['data_inicio'],
               $s['data_encerramento'] ?: date('Y-m-d', strtotime($s['data_inicio'] . " +$nParc months")),
               (string) $s['obs_contrato']]);
            $contratoId = ultimoId();
            gerarParcelas($contratoId, $s['primeira_parcela'] ?: null);

            $processoId = abrirAdmissao($alunoId, $id, (string) $s['nome_completo'], $registro);

            q("UPDATE solicitacoes SET status='convertida', aluno_id=? WHERE id=?", [$alunoId, $id]);
            pdo()->commit();
        } catch (Throwable $e) {
            pdo()->rollBack();
            erro('Não foi possível converter a solicitação.', 500, 'converter: ' . $e->getMessage());
        }

        auditar('solicitacoes', $id, 'converter', null, ['aluno_id' => $alunoId, 'registro' => $registro]);
        responder(['ok' => true, 'aluno_id' => $alunoId, 'registro' => $registro,
                   'contrato_id' => $contratoId, 'processo_id' => $processoId], 201);
    }

    // ---------------------------------------------------------- PROCESSOS
    if ($r(0) === 'processos' && $r(1) === null && $metodo === 'GET') {
        exigirLogin();
        $f = ['1=1']; $a = [];
        if (!empty($_GET['status'])) { $f[] = 'pr.status = ?'; $a[] = $_GET['status']; }
        if (!empty($_GET['tipo']))   { $f[] = 'pr.tipo = ?';   $a[] = $_GET['tipo']; }
        responder(['itens' => linhas(
            "SELECT pr.*, a.registro, p.nome,
                    (SELECT COUNT(*) FROM processo_etapas e WHERE e.processo_id = pr.id) AS etapas,
                    (SELECT COUNT(*) FROM processo_etapas e WHERE e.processo_id = pr.id
                       AND e.status IN ('concluida','nao_aplicavel')) AS feitas
               FROM processos pr
               LEFT JOIN alunos a ON a.id = pr.aluno_id
               LEFT JOIN pessoas p ON p.id = a.pessoa_id
              WHERE " . implode(' AND ', $f) . " ORDER BY pr.iniciado_em DESC", $a)]);
    }

    if ($r(0) === 'processos' && ctype_digit((string) $r(1)) && $metodo === 'GET') {
        exigirLogin();
        responder(['etapas' => linhas(
            "SELECT * FROM processo_etapas WHERE processo_id = ? ORDER BY ordem", [(int) $r(1)])]);
    }

    if ($r(0) === 'processos' && $r(1) === 'etapa' && ctype_digit((string) $r(2)) && $metodo === 'PUT') {
        $u = exigirLogin();
        $novo = texto('status', 20) ?: 'concluida';
        q("UPDATE processo_etapas SET status=?, observacao=?,
                  concluida_em = IF(? IN ('concluida','nao_aplicavel'), NOW(), NULL),
                  concluida_por = IF(? IN ('concluida','nao_aplicavel'), ?, NULL)
            WHERE id=?",
          [$novo, texto('observacao', 400), $novo, $novo, $u['id'], (int) $r(2)]);
        // processo fecha quando todas as etapas saem de pendente
        $e = umaLinha("SELECT processo_id FROM processo_etapas WHERE id=?", [(int) $r(2)]);
        if ($e) {
            $falta = (int) (umaLinha("SELECT COUNT(*) n FROM processo_etapas
                WHERE processo_id=? AND status NOT IN ('concluida','nao_aplicavel')", [$e['processo_id']])['n'] ?? 0);
            if ($falta === 0) {
                q("UPDATE processos SET status='concluido', concluido_em=NOW() WHERE id=? AND status<>'concluido'", [$e['processo_id']]);
                notificarProcessoConcluido((int) $e['processo_id']);
            }
        }
        responder(['ok' => true]);
    }

    // POST /processos/encerramento — abre o encerramento de um aluno
    if ($r(0) === 'processos' && $r(1) === 'encerramento' && $metodo === 'POST') {
        exigirGestor();
        $alunoId = inteiro('aluno_id');
        if (!$alunoId) erro('Informe o aluno.', 400);
        $a = umaLinha("SELECT a.registro, p.nome_completo FROM alunos a JOIN pessoas p ON p.id=a.pessoa_id WHERE a.id=?", [$alunoId]);
        if (!$a) erro('Aluno não encontrado.', 404);
        responder(['ok' => true, 'processo_id' => abrirEncerramento($alunoId, (string) $a['nome_completo'], (string) $a['registro'])], 201);
    }
}

/** Registro no formato AANNN, sequencial dentro do ano. */
function proximoRegistro(): string
{
    $ano = date('y');
    $r = umaLinha("SELECT MAX(CAST(SUBSTRING(registro,3) AS UNSIGNED)) m FROM alunos WHERE registro LIKE ?", [$ano . '%']);
    return $ano . str_pad((string) (((int) ($r['m'] ?? 0)) + 1), 3, '0', STR_PAD_LEFT);
}

function gerarParcelas(int $contratoId, ?string $primeira = null): int
{
    $c = umaLinha("SELECT * FROM contratos WHERE id = ?", [$contratoId]);
    if (!$c) return 0;
    $base = $primeira ?: $c['data_inicio'];
    $n = 0;
    for ($i = 0; $i < (int) $c['num_parcelas']; $i++) {
        $mesRef = date('Y-m-01', strtotime($c['data_inicio'] . " +$i months"));
        $mesVenc = date('Y-m', strtotime($base . " +$i months"));
        $ultimoDia = (int) date('t', strtotime($mesVenc . '-01'));
        $venc = $mesVenc . '-' . str_pad((string) min((int) $c['dia_vencimento'], $ultimoDia), 2, '0', STR_PAD_LEFT);
        q("INSERT IGNORE INTO parcelas (contrato_id, aluno_id, numero, mes_referencia, valor_previsto, data_vencimento)
           VALUES (?,?,?,?,?,?)", [$contratoId, $c['aluno_id'], $i + 1, $mesRef, $c['valor_parcela'], $venc]);
        $n++;
    }
    return $n;
}

/** Checklist real da Ficha de Admissão: 3 blocos, 23 etapas. */
function abrirAdmissao(int $alunoId, ?int $solicitacaoId, string $nome, string $registro): int
{
    q("INSERT INTO processos (tipo, titulo, aluno_id, solicitacao_id) VALUES ('admissao', ?, ?, ?)",
      ["Admissão $nome ($registro)", $alunoId, $solicitacaoId]);
    $pid = ultimoId();
    $etapas = [
        ['comercial', 'Contato inicial com o lead', 'Marcus / Izabele', 0],
        ['comercial', 'Aula experimental realizada', 'Marcus / Izabele', 0],
        ['comercial', 'Fechamento de negócio', 'Valores, horários, modalidade', 0],
        ['comercial', 'Dados iniciais para contrato solicitados', '', 0],
        ['comercial', 'Ficha de admissão preenchida', 'Marco final da venda', 1],
        ['administracao', 'Contrato produzido', 'João — modelo apropriado ao tipo', 0],
        ['administracao', 'Aluno cadastrado no sistema', '', 1],
        ['administracao', 'Matrícula e turma definidas', '', 1],
        ['administracao', 'Contrato e parcelas gerados', '', 1],
        ['administracao', 'Registro de Aula criado ou atualizado', 'Individual: criar. Grupo: modificar', 0],
        ['administracao', 'Contrato enviado ao Assinafy', 'João', 0],
        ['administracao', 'Link de pagamento enviado (InfinitePay)', 'João, em data oportuna', 0],
        ['educacional', 'Pasta do aluno criada', 'Marcus / Izabele', 0],
        ['educacional', 'Livro e workbook com marca d\'água', 'Arial 7, 50% transp., superior direito', 0],
        ['educacional', 'Contrato assinado na pasta do aluno', '', 0],
        ['educacional', 'Link da pasta enviado ao aluno', 'Pasta do aluno e da turma', 0],
        ['educacional', 'Aluno no grupo geral de WhatsApp', '', 0],
        ['educacional', 'Professor avisado: calendário e WhatsApp', '', 0],
        ['educacional', 'Contato etiquetado no WhatsApp', '', 0],
        ['educacional', 'Carta de boas-vindas produzida', 'Modelo no Canva', 0],
        ['educacional', 'Carta enviada ao aluno', 'Cópia para Izabele, João, Marcus e o professor', 0],
        ['administracao', 'Ficha de admissão arquivada', 'Encerra o processo', 0],
    ];
    foreach ($etapas as $i => $e) {
        q("INSERT INTO processo_etapas (processo_id, ordem, bloco, titulo, descricao, automatica, status)
           VALUES (?,?,?,?,?,?,?)",
          [$pid, $i + 1, $e[0], $e[1], $e[2], $e[3], $e[3] ? 'concluida' : 'pendente']);
    }
    return $pid;
}

function abrirEncerramento(int $alunoId, string $nome, string $registro): int
{
    q("INSERT INTO processos (tipo, titulo, aluno_id) VALUES ('encerramento', ?, ?)",
      ["Encerramento $nome ($registro)", $alunoId]);
    $pid = ultimoId();
    $etapas = [
        ['comercial', 'Motivo da saída registrado', ''],
        ['administracao', 'Situação financeira conferida', 'Débitos em aberto — visível à coordenação'],
        ['administracao', 'Multa rescisória calculada, se aplicável', '20% sobre as parcelas restantes'],
        ['administracao', 'Cobrança cancelada no InfinitePay', ''],
        ['administracao', 'Parcelas restantes tratadas', ''],
        ['educacional', 'Aluno removido da turma e dos grupos', ''],
        ['comercial', 'Pesquisa de saída enviada', 'Imprescindível'],
        ['administracao', 'Ficha de encerramento arquivada', ''],
    ];
    foreach ($etapas as $i => $e) {
        q("INSERT INTO processo_etapas (processo_id, ordem, bloco, titulo, descricao)
           VALUES (?,?,?,?,?)", [$pid, $i + 1, $e[0], $e[1], $e[2]]);
    }
    return $pid;
}

function notificarProcessoConcluido(int $processoId): void
{
    $pr = umaLinha("SELECT pr.*, a.registro, p.nome_completo FROM processos pr
                      LEFT JOIN alunos a ON a.id = pr.aluno_id
                      LEFT JOIN pessoas p ON p.id = a.pessoa_id WHERE pr.id = ?", [$processoId]);
    if (!$pr) return;
    $destino = (string) parametro('email_equipe', 'joao@makrsschool.com');
    $rotulo = $pr['tipo'] === 'admissao' ? 'Admissão concluída' : 'Encerramento concluído';
    enviarEmail($destino, "[Makrs Core] $rotulo — " . ($pr['nome_completo'] ?? ''),
        templateEmail($rotulo,
            '<p>Todas as etapas do processo foram concluídas.</p><p><strong>'
            . htmlspecialchars((string) $pr['nome_completo'], ENT_QUOTES) . '</strong> · registro '
            . htmlspecialchars((string) $pr['registro'], ENT_QUOTES) . '</p>',
            (defined('APP_URL') ? APP_URL : '') . '/#/admissoes', 'Ver no Makrs Core'));
}
