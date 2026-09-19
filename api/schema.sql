-- =====================================================================
-- MAKRS CORE — schema (MariaDB / MySQL 8)
-- Fase 1: financeiro, alunos, matrículas, contratos, admissão, cobrança.
-- Este arquivo VIVE NO REPOSITÓRIO. É a fonte da verdade do schema.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- ---------------------------------------------------------------- IDENTIDADE
CREATE TABLE IF NOT EXISTS pessoas (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(120) NOT NULL,
  nome_completo   VARCHAR(200),
  cpf             VARCHAR(20) UNIQUE,
  email           VARCHAR(160),
  telefone        VARCHAR(30),
  data_nascimento DATE,
  endereco        VARCHAR(200),
  numero          VARCHAR(20),
  cidade          VARCHAR(100),
  estado          VARCHAR(80),
  cep             VARCHAR(20),
  pais            VARCHAR(60) DEFAULT 'Brasil',
  observacoes     TEXT,
  origem_externa  VARCHAR(60),
  criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (nome), INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
  pessoa_id            BIGINT NOT NULL,
  email                VARCHAR(160) NOT NULL UNIQUE,
  senha_hash           VARCHAR(255),
  papeis               VARCHAR(160) NOT NULL DEFAULT 'professor',  -- csv: socio,coordenacao,financeiro,professor
  ativo                TINYINT(1) NOT NULL DEFAULT 1,
  -- segundo fator
  totp_segredo_cif     VARBINARY(255),          -- cifrado com APP_SEGREDO, nunca em claro
  totp_ativo           TINYINT(1) NOT NULL DEFAULT 0,
  totp_confirmado_em   DATETIME,
  totp_ultimo_codigo   VARCHAR(10),             -- anti-replay dentro da janela
  -- vínculo Microsoft (Entra ID)
  ms_oid               VARCHAR(80) UNIQUE,
  -- invalidação de sessão (corrige o achado M4 do Buteco)
  sessoes_validas_apos DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_acesso        DATETIME,
  criado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pessoa_id) REFERENCES pessoas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuario_recuperacao_totp (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id  BIGINT NOT NULL,
  codigo_hash VARCHAR(255) NOT NULL,
  usado_em    DATETIME,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tokens (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id  BIGINT NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_atividade DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME NOT NULL,
  ip          VARCHAR(60),
  agente      VARCHAR(255),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limit (
  chave      VARCHAR(190) PRIMARY KEY,
  tentativas INT NOT NULL DEFAULT 0,
  janela_ate DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auditoria (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  tabela      VARCHAR(60) NOT NULL,
  registro_id BIGINT,
  acao        VARCHAR(20) NOT NULL,
  antes       JSON,
  depois      JSON,
  usuario_id  BIGINT,
  ip          VARCHAR(60),
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (tabela, registro_id), INDEX (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- ACADÊMICO
CREATE TABLE IF NOT EXISTS cursos (
  id     BIGINT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  nome   VARCHAR(120) NOT NULL,
  tipo   VARCHAR(30) NOT NULL DEFAULT 'book',
  ordem  SMALLINT DEFAULT 0,
  ativo  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS professores (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  pessoa_id    BIGINT NOT NULL,
  tipo_vinculo VARCHAR(20) NOT NULL DEFAULT 'pj',
  cnpj         VARCHAR(30),
  valor_hora   DECIMAL(10,2),
  dados_bancarios_cif VARBINARY(1024),
  data_entrada DATE, data_saida DATE,
  ativo        TINYINT(1) NOT NULL DEFAULT 1,
  e_coordenador TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (pessoa_id) REFERENCES pessoas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS turmas (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  class_id       VARCHAR(20) NOT NULL UNIQUE,     -- 261110
  nome_curto     VARCHAR(80),
  curso_id       BIGINT,
  professor_id   BIGINT,
  modalidade     VARCHAR(20),                     -- Individual | Group | Pair
  horas_mes      DECIMAL(5,2),                    -- 4.5 | 9 | 13.5
  dias           VARCHAR(40),                     -- csv de 0..6
  horario_inicio TIME, horario_fim TIME,
  vagas_total    SMALLINT DEFAULT 6,
  link_teams     VARCHAR(400),
  link_material  VARCHAR(400),
  status         VARCHAR(20) NOT NULL DEFAULT 'ativa',
  data_inicio    DATE, data_encerramento DATE,
  FOREIGN KEY (curso_id) REFERENCES cursos(id),
  FOREIGN KEY (professor_id) REFERENCES professores(id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alunos (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  pessoa_id     BIGINT NOT NULL,
  registro      VARCHAR(12) NOT NULL UNIQUE,      -- 26037
  status        VARCHAR(20) NOT NULL DEFAULT 'ativo',
  data_entrada  DATE, data_saida DATE,
  motivo_saida  VARCHAR(40),
  e_menor       TINYINT(1) NOT NULL DEFAULT 0,
  origem        VARCHAR(60),
  service_type  VARCHAR(200),
  link_pasta    VARCHAR(400),                     -- pasta do aluno no SharePoint
  link_material VARCHAR(400),
  observacoes   TEXT,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pessoa_id) REFERENCES pessoas(id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS responsaveis (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  aluno_id   BIGINT NOT NULL,
  pessoa_id  BIGINT NOT NULL,
  parentesco VARCHAR(40),
  e_signatario TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
  FOREIGN KEY (pessoa_id) REFERENCES pessoas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS matriculas (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  aluno_id    BIGINT NOT NULL,
  curso_id    BIGINT,
  turma_id    BIGINT,
  modalidade  VARCHAR(20),
  nivel_texto VARCHAR(80),
  horas_mes   DECIMAL(5,2),
  data_inicio DATE NOT NULL, data_fim DATE,
  status      VARCHAR(30) NOT NULL DEFAULT 'ativa',
  FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
  FOREIGN KEY (curso_id) REFERENCES cursos(id),
  FOREIGN KEY (turma_id) REFERENCES turmas(id),
  INDEX (turma_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- CONTRATOS
CREATE TABLE IF NOT EXISTS contratos (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  aluno_id        BIGINT NOT NULL,
  matricula_id    BIGINT,
  sequencia       SMALLINT NOT NULL DEFAULT 1,     -- 1=primeiro, 2=1a renovação...
  rotulo          VARCHAR(80),                     -- "26037" ou "Sarah Aline 2025.2"
  tipo            VARCHAR(20) NOT NULL DEFAULT 'semestral',
  valor_parcela   DECIMAL(10,2) NOT NULL DEFAULT 0,  -- promocional (o que o aluno paga)
  valor_cheio     DECIMAL(10,2),                     -- = promocional + 75
  desconto_motivo VARCHAR(200),
  num_parcelas    SMALLINT NOT NULL DEFAULT 6,
  dia_vencimento  TINYINT NOT NULL DEFAULT 10,
  data_assinatura DATE, data_inicio DATE NOT NULL, data_expiracao DATE NOT NULL,
  status          VARCHAR(30) NOT NULL DEFAULT 'a_fazer',
  renovacao       VARCHAR(20) NOT NULL DEFAULT 'manual',
  multa_percentual DECIMAL(5,2) NOT NULL DEFAULT 20,
  clausulas_especiais TEXT,
  link_assinatura VARCHAR(400),
  link_pagamento  VARCHAR(400),
  arquivo_pdf     VARCHAR(400),
  criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
  UNIQUE KEY (aluno_id, sequencia), INDEX (status), INDEX (data_expiracao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contrato_aditivos (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  contrato_id BIGINT NOT NULL,
  tipo        VARCHAR(40) NOT NULL,
  descricao   TEXT NOT NULL,
  data_efeito DATE NOT NULL,
  criado_por  BIGINT,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (contrato_id) REFERENCES contratos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- FINANCEIRO
CREATE TABLE IF NOT EXISTS plano_contas (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  nome      VARCHAR(80) NOT NULL UNIQUE,
  grupo_dre VARCHAR(40) NOT NULL,
  sinal     TINYINT NOT NULL DEFAULT 1,
  ordem     SMALLINT NOT NULL DEFAULT 0,
  ativo     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS centros_custo (
  id    BIGINT AUTO_INCREMENT PRIMARY KEY,
  nome  VARCHAR(80) NOT NULL UNIQUE,
  tipo  VARCHAR(40),
  ativo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A tabela única: contas a pagar, contas a receber e caixa são recortes dela.
CREATE TABLE IF NOT EXISTS lancamentos (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  tipo             VARCHAR(10) NOT NULL,            -- entrada | saida
  descricao        VARCHAR(255) NOT NULL,
  pessoa_id        BIGINT,
  fornecedor       VARCHAR(160),
  data_competencia DATE NOT NULL,                   -- mês do DRE
  data_vencimento  DATE,
  data_pagamento   DATE,                            -- preenchido = está no caixa
  valor            DECIMAL(12,2) NOT NULL,
  plano_conta_id   BIGINT,
  centro_custo_id  BIGINT,
  status           VARCHAR(12) NOT NULL DEFAULT 'previsto',  -- previsto|pago|cancelado
  forma_pagamento  VARCHAR(20),
  origem           VARCHAR(20) NOT NULL DEFAULT 'manual',
  ref_origem_id    BIGINT,
  nf_numero        VARCHAR(20),
  anexo            VARCHAR(400),
  observacoes      TEXT,
  criado_por       BIGINT,
  criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (plano_conta_id) REFERENCES plano_contas(id),
  FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id),
  INDEX (status, data_vencimento), INDEX (data_competencia), INDEX (data_pagamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parcelas (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  contrato_id     BIGINT NOT NULL,
  aluno_id        BIGINT NOT NULL,
  numero          SMALLINT NOT NULL,
  mes_referencia  DATE NOT NULL,                  -- competência do serviço
  valor_previsto  DECIMAL(10,2) NOT NULL,
  valor_recebido  DECIMAL(10,2),                  -- pode ser maior: multa
  multa           DECIMAL(10,2) NOT NULL DEFAULT 0,
  data_vencimento DATE NOT NULL,
  data_pagamento  DATE,
  status          VARCHAR(20) NOT NULL DEFAULT 'aberta', -- aberta|paga|atrasada|isenta|cancelada
  forma_pagamento VARCHAR(20),
  lancamento_id   BIGINT,
  isencao_motivo  VARCHAR(200),
  id_infinitepay  VARCHAR(80),
  FOREIGN KEY (contrato_id) REFERENCES contratos(id) ON DELETE CASCADE,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
  UNIQUE KEY (contrato_id, numero),
  INDEX (status, data_vencimento), INDEX (aluno_id), INDEX (mes_referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notas_fiscais (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  aluno_id         BIGINT NOT NULL,
  parcela_id       BIGINT,
  mes_referencia   DATE NOT NULL,                 -- competência do SERVIÇO
  cpf              VARCHAR(20),
  descricao_servico VARCHAR(255) NOT NULL,
  valor            DECIMAL(10,2) NOT NULL,        -- recebido, com multa
  numero_nf        VARCHAR(20),
  data_emissao     DATE,
  status           VARCHAR(20) NOT NULL DEFAULT 'pendente',
  antecipada       TINYINT(1) NOT NULL DEFAULT 0,
  observacoes      VARCHAR(255),
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (parcela_id) REFERENCES parcelas(id),
  INDEX (mes_referencia, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- CONCILIAÇÃO
CREATE TABLE IF NOT EXISTS conciliacoes (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  titulo      VARCHAR(120) NOT NULL,
  periodo_de  DATE, periodo_ate DATE,
  arq_extrato VARCHAR(200),
  arq_cobranca VARCHAR(200),
  total_linhas INT NOT NULL DEFAULT 0,
  casadas     INT NOT NULL DEFAULT 0,
  pendentes   INT NOT NULL DEFAULT 0,
  status      VARCHAR(20) NOT NULL DEFAULT 'aberta',
  criado_por  BIGINT,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conciliacao_itens (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  conciliacao_id BIGINT NOT NULL,
  origem         VARCHAR(20) NOT NULL,          -- extrato | cobranca
  data_mov       DATE,
  descricao_bruta VARCHAR(255),
  nome_pagador   VARCHAR(160),
  valor          DECIMAL(12,2) NOT NULL,
  aluno_id       BIGINT,
  parcela_id     BIGINT,
  confianca      VARCHAR(20),                   -- alta | media | baixa | nenhuma
  motivo         VARCHAR(255),
  status         VARCHAR(20) NOT NULL DEFAULT 'proposto', -- proposto|confirmado|recusado|ignorado
  decidido_por   BIGINT, decidido_em DATETIME,
  FOREIGN KEY (conciliacao_id) REFERENCES conciliacoes(id) ON DELETE CASCADE,
  INDEX (conciliacao_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- COBRANÇA
CREATE TABLE IF NOT EXISTS comunicados_cobranca (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  aluno_id   BIGINT NOT NULL,
  canal      VARCHAR(20) NOT NULL,              -- email | whatsapp | telefone | presencial
  estagio    VARCHAR(30) NOT NULL,              -- aviso|cobranca|negociacao|acordo|encerramento|perda
  assunto    VARCHAR(200),
  corpo      TEXT,
  valor_em_aberto DECIMAL(10,2),
  meses_referencia VARCHAR(120),
  resposta   TEXT,
  respondido_em DATE,
  enviado_por BIGINT,
  enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
  INDEX (aluno_id, enviado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- ADMISSÃO
CREATE TABLE IF NOT EXISTS solicitacoes (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  numero_externo VARCHAR(20),                  -- "#330" do formulário atual
  -- parte CADASTRAL (o aluno preenche)
  nome_completo  VARCHAR(200) NOT NULL,
  cpf            VARCHAR(20),
  data_nascimento DATE,
  e_menor        TINYINT(1) NOT NULL DEFAULT 0,
  responsavel_nome VARCHAR(200), responsavel_cpf VARCHAR(20),
  telefone       VARCHAR(30),
  email          VARCHAR(160),
  endereco VARCHAR(200), numero VARCHAR(20), cidade VARCHAR(100),
  estado VARCHAR(80), cep VARCHAR(20), pais VARCHAR(60) DEFAULT 'Brasil',
  documento_arq  VARCHAR(400),
  professor_pref VARCHAR(120),
  modalidade     VARCHAR(20),
  dias_horarios  TEXT,
  dia_vencimento TINYINT,
  tipo_contrato  VARCHAR(20),
  como_conheceu  VARCHAR(160),
  observacoes    TEXT,
  -- parte COMERCIAL (quem fechou preenche depois)
  valor_parcela  DECIMAL(10,2),
  valor_cheio    DECIMAL(10,2),
  desconto_motivo VARCHAR(200),
  turma_id       BIGINT,
  curso_id       BIGINT,
  data_inicio    DATE, data_encerramento DATE,
  primeira_parcela DATE,
  obs_contrato   TEXT,
  -- estado
  status         VARCHAR(30) NOT NULL DEFAULT 'nova', -- nova|comercial|aprovada|convertida|descartada
  aluno_id       BIGINT,
  origem         VARCHAR(20) NOT NULL DEFAULT 'formulario',
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS processos (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  tipo         VARCHAR(30) NOT NULL,            -- admissao | encerramento
  titulo       VARCHAR(200) NOT NULL,
  aluno_id     BIGINT,
  solicitacao_id BIGINT,
  status       VARCHAR(20) NOT NULL DEFAULT 'em_andamento',
  iniciado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  concluido_em DATETIME,
  INDEX (tipo, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS processo_etapas (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  processo_id   BIGINT NOT NULL,
  ordem         SMALLINT NOT NULL,
  bloco         VARCHAR(20) NOT NULL,           -- comercial | administracao | educacional
  titulo        VARCHAR(200) NOT NULL,
  descricao     VARCHAR(255),
  automatica    TINYINT(1) NOT NULL DEFAULT 0,
  status        VARCHAR(20) NOT NULL DEFAULT 'pendente',
  concluida_em  DATETIME, concluida_por BIGINT,
  observacao    VARCHAR(400),
  FOREIGN KEY (processo_id) REFERENCES processos(id) ON DELETE CASCADE,
  INDEX (processo_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- PARÂMETROS
CREATE TABLE IF NOT EXISTS parametros (
  chave     VARCHAR(80) PRIMARY KEY,
  valor     TEXT NOT NULL,
  descricao VARCHAR(255),
  categoria VARCHAR(40),
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
