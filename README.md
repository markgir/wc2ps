# wc2ps — WooCommerce → PrestaShop Migration Tool

Ferramenta standalone de migração directa entre bases de dados WooCommerce e PrestaShop. Corre no mesmo servidor do cliente (hosting partilhado), sem exports manuais, sem CSV, sem ferramentas intermédias.

## Funcionalidades

- Migração directa BD→BD via `127.0.0.1` (sem expor credenciais)
- Categorias, atributos, variações e combinações
- Preços com fallback `_regular_price → _sale_price → _price`
- Importação de imagens (cópia directa ou HTTP)
- Slugs normalizados (sufixos `-2` do WordPress)
- Pausa / Retomar / Reset sem perder estado
- Log em tempo real com filtros por nível
- Interface em 6 slides horizontais
- Credenciais guardadas em localStorage
- Sessão persistente derivada do nome da BD
- Progresso em micro-batches (barras animadas)

## Requisitos

- PHP 8.0+
- PDO + pdo_mysql
- MySQL/MariaDB
- Mesmo servidor (ou acesso a ambas as BDs via `127.0.0.1`)

## Instalação

1. Faz upload de todos os ficheiros para uma pasta no teu servidor (ex: `migration.example.com/`)
2. Abre no browser e preenche as credenciais das duas BDs

Não requer base de dados própria — todo o estado é guardado em ficheiros JSON em `migration_progress/`.

## Estrutura

```
wc2ps/
├── api.php                   ← API REST interna
├── index.php                 ← Interface (6 slides)
├── debug.php                 ← Diagnóstico
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── src/
│   ├── Database.php
│   ├── DebugLogger.php
│   ├── FieldMapper.php
│   ├── Migrator.php
│   ├── ImageMigrator.php
│   ├── PrestaShop/
│   │   ├── PSAnalyser.php
│   │   └── PSWriter.php
│   └── WooCommerce/
│       └── WCAnalyser.php
└── tools/
    ├── consolidate_ps_products.php
    ├── diagnose.php
    ├── fix_numeric_fields.php
    └── inspect_products.php
```

## Versão

**v2.5.4** — estável em produção

| Versão | Mudança |
|--------|---------|
| 2.5.4 | Fix: botões pausa/stop sem loop infinito (finally block) |
| 2.5.3 | Fix: categorias/atributos em micro-batches, rotate() desactivado, poll antes de migrar |
| 2.5.2 | Fix: slugs `-2` normalizados, preço fallback `_sale_price/_price`, `0x0C` removido |
| 2.5.1 | Fix: auto-detect caminhos WC/PS, session derivada do nome BD |
| 2.5.0 | Feature: slide imagens, detect_paths |
| 2.4.0 | Feature: layout horizontal em slides |
| 2.3.0 | Feature: guardar/limpar credenciais (localStorage) |
| 2.2.0 | Feature: pausar / parar / retomar |
| 2.1.x | Fix: duplo prefixo, session, reset em batches |
| 2.0.0 | Base: wizard 5 passos, migração, terminal |

## Licença

MIT
