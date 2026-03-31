<?php
/**
 * consolidate_ps_products.php
 *
 * Consolida produtos PrestaShop que foram importados separadamente mas pertencem
 * ao mesmo produto pai (detectado por título com padrão "NOME - COR").
 *
 * O que faz:
 *  1. Agrupa produtos PS por prefixo de título (antes do último " - COR")
 *  2. Para cada grupo: mantém o primeiro produto, converte os outros em combinações
 *  3. Para cada cor: cria atributo "Cor" com o valor da cor
 *  4. Para cada tamanho existente nas variações: mantém/cria atributo "Tamanho"
 *  5. Apaga os produtos duplicados após migrar as suas combinações
 *
 * Modo de execução:
 *   php tools/consolidate_ps_products.php --dry-run   (só mostra o que faria)
 *   php tools/consolidate_ps_products.php             (executa)
 *
 * Variáveis de ambiente:
 *   PS_DB_HOST, PS_DB_PORT, PS_DB_NAME, PS_DB_USER, PS_DB_PASS, PS_DB_PREFIX
 *   PS_ID_LANG (default: 1)
 *   PS_ID_SHOP (default: 1)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$dryRun = in_array('--dry-run', $argv ?? [], true);
$idLang = (int)(getenv('PS_ID_LANG') ?: 1);
$idShop = (int)(getenv('PS_ID_SHOP') ?: 1);

// ── Config ────────────────────────────────────────────────────────────────────

function ask(string $prompt, string $default = ''): string {
    fwrite(STDOUT, $prompt . ($default ? " [{$default}]" : '') . ': ');
    $v = trim(fgets(STDIN));
    return $v !== '' ? $v : $default;
}

$auto = (getenv('PS_DB_NAME') !== false);
$cfg = [
    'host'   => getenv('PS_DB_HOST')   ?: ($auto ? '127.0.0.1' : ask('PS host',   '127.0.0.1')),
    'port'   => getenv('PS_DB_PORT')   ?: ($auto ? '3306'       : ask('PS port',   '3306')),
    'db'     => getenv('PS_DB_NAME')   ?: ($auto ? ''           : ask('PS db name')),
    'user'   => getenv('PS_DB_USER')   ?: ($auto ? ''           : ask('PS user')),
    'pass'   => getenv('PS_DB_PASS')   ?: ($auto ? ''           : ask('PS password')),
    'prefix' => getenv('PS_DB_PREFIX') ?: ($auto ? 'ps_'        : ask('PS prefix', 'ps_')),
];

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  PS Product Consolidator\n";
echo ($dryRun ? "  [DRY RUN — nenhuma alteração será feita]\n" : "  [LIVE — irá modificar a base de dados PS]\n");
echo "═══════════════════════════════════════════════════════\n\n";

// ── Connect ───────────────────────────────────────────────────────────────────

try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'], $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "[OK] Ligado a {$cfg['db']}@{$cfg['host']}\n\n";
} catch (\PDOException $e) {
    die("[FATAL] Não foi possível ligar: " . $e->getMessage() . "\n");
}

$p = $cfg['prefix'];

// ── Helper functions ──────────────────────────────────────────────────────────

function q(PDO $pdo, string $sql, array $params = []): array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function q1(PDO $pdo, string $sql, array $params = []): ?array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $r = $s->fetch();
    return $r ?: null;
}

function ex(PDO $pdo, string $sql, array $params = []): int {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return (int)$pdo->lastInsertId();
}

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $t2 = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($t2) $text = $t2;
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
    return trim($text, '-') ?: 'item';
}

// ── Step 1: Load all PS products and detect groups ────────────────────────────

echo "Passo 1: A carregar produtos do PrestaShop...\n";

$rows = q($pdo,
    "SELECT p.id_product, pl.name, p.price, p.reference,
            p.id_category_default, p.active, p.date_add, p.date_upd
     FROM `{$p}product` p
     JOIN `{$p}product_lang` pl
       ON p.id_product = pl.id_product AND pl.id_lang = ? AND pl.id_shop = ?
     ORDER BY pl.name",
    [$idLang, $idShop]
);

echo "  Total de produtos no PS: " . count($rows) . "\n";

// ── Step 2: Group by base name (strip trailing " - COLOR") ────────────────────

// Pattern: "NOME DO PRODUTO - COR" → base="NOME DO PRODUTO", color="COR"
// We detect groups where multiple products share the same base name
$groups = [];
foreach ($rows as $r) {
    $name = $r['name'];
    // Match " - WORD(S)" at the end — the color suffix
    if (preg_match('/^(.+?)\s+-\s+([A-ZÁÀÂÃÉÊÍÓÔÕÚÇÜÑ][A-ZÁÀÂÃÉÊÍÓÔÕÚÇÜÑa-záàâãéêíóôõúçüña-z ]+)$/', $name, $m)) {
        $base  = trim($m[1]);
        $color = trim($m[2]);
        $groups[$base][] = array_merge($r, ['color' => $color, 'base' => $base]);
    }
}

// Only keep groups with 2+ products (real duplicates)
$toConsolidate = array_filter($groups, fn($g) => count($g) >= 2);

echo "  Grupos detectados para consolidar: " . count($toConsolidate) . "\n\n";

if (empty($toConsolidate)) {
    echo "Nenhum grupo encontrado. Nada a fazer.\n";
    echo "  (Verifica se os produtos têm o padrão 'NOME - COR' no título)\n";
    exit(0);
}

// Show what will be consolidated
foreach ($toConsolidate as $base => $products) {
    echo "  Grupo: '$base'\n";
    foreach ($products as $prod) {
        $combCount = (int)(q1($pdo,
            "SELECT COUNT(*) AS n FROM `{$p}product_attribute` WHERE id_product = ?",
            [$prod['id_product']]
        )['n'] ?? 0);
        echo "    → PS#{$prod['id_product']} [{$prod['color']}] price={$prod['price']} combinations={$combCount}\n";
    }
}

echo "\n";

if ($dryRun) {
    echo "[DRY RUN] Pararia aqui. Corre sem --dry-run para executar.\n\n";
    exit(0);
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "Continuar? [s/N]: ");
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 's') {
        echo "Cancelado.\n";
        exit(0);
    }
}

echo "\nPasso 2: A consolidar produtos...\n\n";

// ── Step 3: Get or create "Cor" attribute group ───────────────────────────────

function getOrCreateAttributeGroup(PDO $pdo, string $p, string $name, int $idLang, int $idShop): int {
    // Search by name in attribute_group_lang
    $row = q1($pdo,
        "SELECT ag.id_attribute_group FROM `{$p}attribute_group` ag
         JOIN `{$p}attribute_group_lang` agl ON ag.id_attribute_group = agl.id_attribute_group
         WHERE agl.name = ? AND agl.id_lang = ? LIMIT 1",
        [$name, $idLang]
    );
    if ($row) return (int)$row['id_attribute_group'];

    // Create
    $id = ex($pdo,
        "INSERT INTO `{$p}attribute_group` (is_color_group, group_type, position) VALUES (0, 'select', 0)"
    );
    ex($pdo,
        "INSERT INTO `{$p}attribute_group_lang` (id_attribute_group, id_lang, name, public_name)
         VALUES (?, ?, ?, ?)",
        [$id, $idLang, $name, $name]
    );
    // attribute_group_shop
    $s = $pdo->prepare("SHOW TABLES LIKE " . $pdo->quote($p . 'attribute_group_shop'));
    $s->execute();
    if ($s->fetch()) {
        $pdo->prepare("INSERT IGNORE INTO `{$p}attribute_group_shop` (id_attribute_group, id_shop) VALUES (?, ?)")
            ->execute([$id, $idShop]);
    }
    echo "  [CRIADO] Grupo de atributo '$name' → id={$id}\n";
    return $id;
}

function getOrCreateAttribute(PDO $pdo, string $p, string $name, int $groupId, int $idLang, int $idShop): int {
    $slug = strtolower(trim($name));
    // Search existing
    $row = q1($pdo,
        "SELECT a.id_attribute FROM `{$p}attribute` a
         JOIN `{$p}attribute_lang` al ON a.id_attribute = al.id_attribute
         WHERE a.id_attribute_group = ? AND LOWER(al.name) = ? AND al.id_lang = ? LIMIT 1",
        [$groupId, $slug, $idLang]
    );
    if ($row) return (int)$row['id_attribute'];

    $id = ex($pdo,
        "INSERT INTO `{$p}attribute` (id_attribute_group, color, position) VALUES (?, '', 0)",
        [$groupId]
    );
    ex($pdo,
        "INSERT INTO `{$p}attribute_lang` (id_attribute, id_lang, name) VALUES (?, ?, ?)",
        [$id, $idLang, $name]
    );
    $s = $pdo->prepare("SHOW TABLES LIKE " . $pdo->quote($p . 'attribute_shop'));
    $s->execute();
    if ($s->fetch()) {
        $pdo->prepare("INSERT IGNORE INTO `{$p}attribute_shop` (id_attribute, id_shop) VALUES (?, ?)")
            ->execute([$id, $idShop]);
    }
    return $id;
}

// ── Step 4: Process each group ────────────────────────────────────────────────

$colorGroupId = getOrCreateAttributeGroup($pdo, $p, 'Cor', $idLang, $idShop);

$totalMerged   = 0;
$totalDeleted  = 0;
$totalCombMoved= 0;

foreach ($toConsolidate as $base => $products) {
    echo "Consolidando: '$base' (" . count($products) . " produtos)\n";

    // Sort: keep the one with the lowest id_product as master
    usort($products, fn($a, $b) => $a['id_product'] <=> $b['id_product']);
    $master   = $products[0];
    $masterId = (int)$master['id_product'];
    $slaves   = array_slice($products, 1);

    echo "  Master: PS#{$masterId} [{$master['color']}]\n";

    // Update master product name to base name (strip color suffix)
    $pdo->prepare("UPDATE `{$p}product_lang` SET name = ? WHERE id_product = ? AND id_lang = ?")
        ->execute([$base, $masterId, $idLang]);

    // Get or create color attribute for master's color
    $masterColorId = getOrCreateAttribute($pdo, $p, $master['color'], $colorGroupId, $idLang, $idShop);

    // Get all existing combinations of master and tag them with the master color
    $masterCombs = q($pdo,
        "SELECT id_product_attribute FROM `{$p}product_attribute` WHERE id_product = ?",
        [$masterId]
    );

    foreach ($masterCombs as $comb) {
        $paId = (int)$comb['id_product_attribute'];
        // Add color attribute to this combination (if not already linked)
        $exists = q1($pdo,
            "SELECT 1 FROM `{$p}product_attribute_combination`
             WHERE id_product_attribute = ? AND id_attribute = ?",
            [$paId, $masterColorId]
        );
        if (!$exists) {
            $pdo->prepare(
                "INSERT IGNORE INTO `{$p}product_attribute_combination`
                 (id_product_attribute, id_attribute) VALUES (?, ?)"
            )->execute([$paId, $masterColorId]);
        }
    }

    // If master has no combinations at all (was a simple product), create one
    if (empty($masterCombs)) {
        // Create a single combination for the master color
        $paId = ex($pdo,
            "INSERT INTO `{$p}product_attribute`
                (id_product, reference, price, ecotax, quantity, weight,
                 unit_price_impact, minimal_quantity, default_on, available_date)
             VALUES (?, ?, 0, 0, 0, 0, 0, 1, 1, '0000-00-00')",
            [$masterId, $master['reference']]
        );
        $pdo->prepare(
            "INSERT IGNORE INTO `{$p}product_attribute_combination`
             (id_product_attribute, id_attribute) VALUES (?, ?)"
        )->execute([$paId, $masterColorId]);

        // Stock
        $qty = (int)(q1($pdo,
            "SELECT quantity FROM `{$p}stock_available` WHERE id_product = ? AND id_product_attribute = 0 LIMIT 1",
            [$masterId]
        )['quantity'] ?? 0);
        $pdo->prepare(
            "INSERT INTO `{$p}stock_available`
                (id_product, id_product_attribute, id_shop, id_shop_group, quantity, depends_on_stock, out_of_stock)
             VALUES (?, ?, ?, 0, ?, 0, 2)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)"
        )->execute([$masterId, $paId, $idShop, $qty]);

        echo "    [MASTER] Combinação criada para cor '{$master['color']}' (sem tamanhos)\n";
    }

    echo "    [MASTER] " . count($masterCombs) . " combinações existentes → cor '{$master['color']}' adicionada\n";

    // ── Process each slave product ────────────────────────────────────────────
    foreach ($slaves as $slave) {
        $slaveId  = (int)$slave['id_product'];
        $slaveColor = $slave['color'];
        echo "  Slave: PS#{$slaveId} [{$slaveColor}] → a mover para PS#{$masterId}\n";

        $slaveColorId = getOrCreateAttribute($pdo, $p, $slaveColor, $colorGroupId, $idLang, $idShop);

        // Get slave combinations
        $slaveCombs = q($pdo,
            "SELECT pa.*, pas.default_on AS shop_default
             FROM `{$p}product_attribute` pa
             LEFT JOIN `{$p}product_attribute_shop` pas
               ON pa.id_product_attribute = pas.id_product_attribute AND pas.id_shop = ?
             WHERE pa.id_product = ?",
            [$idShop, $slaveId]
        );

        if (empty($slaveCombs)) {
            // Slave had no combinations — create one combination for this color
            $paId = ex($pdo,
                "INSERT INTO `{$p}product_attribute`
                    (id_product, reference, price, ecotax, quantity, weight,
                     unit_price_impact, minimal_quantity, default_on, available_date)
                 VALUES (?, ?, ?, 0, 0, 0, 0, 1, NULL, '0000-00-00')",
                [$masterId, $slave['reference'], $slave['price'] - $master['price']]
            );
            $pdo->prepare(
                "INSERT IGNORE INTO `{$p}product_attribute_combination`
                 (id_product_attribute, id_attribute) VALUES (?, ?)"
            )->execute([$paId, $slaveColorId]);

            $qty = (int)(q1($pdo,
                "SELECT quantity FROM `{$p}stock_available`
                 WHERE id_product = ? AND id_product_attribute = 0 LIMIT 1",
                [$slaveId]
            )['quantity'] ?? 0);
            $pdo->prepare(
                "INSERT INTO `{$p}stock_available`
                    (id_product, id_product_attribute, id_shop, id_shop_group, quantity, depends_on_stock, out_of_stock)
                 VALUES (?, ?, ?, 0, ?, 0, 2)
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)"
            )->execute([$masterId, $paId, $idShop, $qty]);

            echo "    [SLAVE] Combinação criada para cor '{$slaveColor}' (sem tamanhos)\n";
            $totalCombMoved++;
        } else {
            // Move each combination to master, adding the color attribute
            foreach ($slaveCombs as $comb) {
                $oldPaId = (int)$comb['id_product_attribute'];

                // Create new product_attribute row on master
                $priceImpact = (float)$comb['price'];
                $newPaId = ex($pdo,
                    "INSERT INTO `{$p}product_attribute`
                        (id_product, reference, supplier_reference, location, ean13, isbn, upc, mpn,
                         wholesale_price, price, ecotax, quantity, weight, unit_price_impact,
                         minimal_quantity, low_stock_threshold, low_stock_alert, default_on, available_date)
                     VALUES (?, ?, '', '', '', '', '', '', 0, ?, 0, 0, ?, 0, 1, 0, 0, NULL, '0000-00-00')",
                    [$masterId, $comb['reference'] ?? '', $priceImpact, $comb['weight'] ?? 0]
                );

                // Copy combination attributes (sizes etc.) and add color
                $oldLinks = q($pdo,
                    "SELECT id_attribute FROM `{$p}product_attribute_combination`
                     WHERE id_product_attribute = ?",
                    [$oldPaId]
                );
                foreach ($oldLinks as $link) {
                    $pdo->prepare(
                        "INSERT IGNORE INTO `{$p}product_attribute_combination`
                         (id_product_attribute, id_attribute) VALUES (?, ?)"
                    )->execute([$newPaId, $link['id_attribute']]);
                }
                // Add color
                $pdo->prepare(
                    "INSERT IGNORE INTO `{$p}product_attribute_combination`
                     (id_product_attribute, id_attribute) VALUES (?, ?)"
                )->execute([$newPaId, $slaveColorId]);

                // product_attribute_shop
                $hasShopTable = !!q1($pdo,
                    "SHOW TABLES LIKE " . $pdo->quote($p . 'product_attribute_shop')
                );
                if ($hasShopTable) {
                    $pdo->prepare(
                        "INSERT IGNORE INTO `{$p}product_attribute_shop`
                            (id_product_attribute, id_product, id_shop, wholesale_price, price,
                             ecotax, weight, unit_price_impact, default_on, minimal_quantity,
                             low_stock_threshold, low_stock_alert, available_date)
                         VALUES (?, ?, ?, 0, ?, 0, ?, 0, NULL, 1, 0, 0, '0000-00-00')"
                    )->execute([$newPaId, $masterId, $idShop, $priceImpact, $comb['weight'] ?? 0]);
                }

                // Move stock
                $qty = (int)(q1($pdo,
                    "SELECT quantity FROM `{$p}stock_available`
                     WHERE id_product = ? AND id_product_attribute = ? LIMIT 1",
                    [$slaveId, $oldPaId]
                )['quantity'] ?? 0);
                $pdo->prepare(
                    "INSERT INTO `{$p}stock_available`
                        (id_product, id_product_attribute, id_shop, id_shop_group,
                         quantity, depends_on_stock, out_of_stock)
                     VALUES (?, ?, ?, 0, ?, 0, 2)
                     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)"
                )->execute([$masterId, $newPaId, $idShop, $qty]);

                $totalCombMoved++;
            }
            echo "    [SLAVE] " . count($slaveCombs) . " combinações movidas → cor '{$slaveColor}' adicionada a cada uma\n";
        }

        // ── Delete slave product ──────────────────────────────────────────────
        echo "    [DELETE] A apagar PS#{$slaveId}...\n";

        // Delete slave combinations (already moved)
        $slavePaIds = array_column(q($pdo,
            "SELECT id_product_attribute FROM `{$p}product_attribute` WHERE id_product = ?",
            [$slaveId]
        ), 'id_product_attribute');

        foreach ($slavePaIds as $paId) {
            $pdo->prepare("DELETE FROM `{$p}product_attribute_combination` WHERE id_product_attribute = ?")
                ->execute([$paId]);
            $pdo->prepare("DELETE FROM `{$p}product_attribute_shop` WHERE id_product_attribute = ?")
                ->execute([$paId]);
        }
        $pdo->prepare("DELETE FROM `{$p}product_attribute` WHERE id_product = ?")
            ->execute([$slaveId]);

        // Images
        $imgIds = array_column(q($pdo,
            "SELECT id_image FROM `{$p}image` WHERE id_product = ?",
            [$slaveId]
        ), 'id_image');
        foreach ($imgIds as $imgId) {
            $pdo->prepare("DELETE FROM `{$p}image_lang` WHERE id_image = ?")->execute([$imgId]);
            $pdo->prepare("DELETE FROM `{$p}image_shop` WHERE id_image = ?")->execute([$imgId]);
        }
        $pdo->prepare("DELETE FROM `{$p}image` WHERE id_product = ?")->execute([$slaveId]);

        // Stock, specific prices, category links
        $pdo->prepare("DELETE FROM `{$p}stock_available` WHERE id_product = ?")->execute([$slaveId]);
        $pdo->prepare("DELETE FROM `{$p}specific_price` WHERE id_product = ?")->execute([$slaveId]);
        $pdo->prepare("DELETE FROM `{$p}category_product` WHERE id_product = ?")->execute([$slaveId]);
        $pdo->prepare("DELETE FROM `{$p}product_lang` WHERE id_product = ?")->execute([$slaveId]);
        $pdo->prepare("DELETE FROM `{$p}product_shop` WHERE id_product = ?")->execute([$slaveId]);

        // Search index cleanup
        $hasSearchIdx = !!q1($pdo, "SHOW TABLES LIKE " . $pdo->quote($p . 'search_index'));
        if ($hasSearchIdx) {
            $pdo->prepare("DELETE FROM `{$p}search_index` WHERE id_product = ?")->execute([$slaveId]);
        }

        $pdo->prepare("DELETE FROM `{$p}product` WHERE id_product = ?")->execute([$slaveId]);

        $totalDeleted++;
        $totalMerged++;
    }

    // Update cache_default_attribute on master
    $firstComb = q1($pdo,
        "SELECT id_product_attribute FROM `{$p}product_attribute`
         WHERE id_product = ? ORDER BY id_product_attribute ASC LIMIT 1",
        [$masterId]
    );
    if ($firstComb) {
        $pdo->prepare(
            "UPDATE `{$p}product` SET cache_default_attribute = ? WHERE id_product = ?"
        )->execute([$firstComb['id_product_attribute'], $masterId]);
        $pdo->prepare(
            "UPDATE `{$p}product_shop` SET cache_default_attribute = ?
             WHERE id_product = ? AND id_shop = ?"
        )->execute([$firstComb['id_product_attribute'], $masterId, $idShop]);
    }

    echo "  [OK] '$base' consolidado em PS#{$masterId}\n\n";
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "═══════════════════════════════════════════════════════\n";
echo "  Consolidação concluída\n";
echo "  Grupos processados : " . count($toConsolidate) . "\n";
echo "  Produtos eliminados: {$totalDeleted}\n";
echo "  Combinações movidas: {$totalCombMoved}\n";
echo "\n";
echo "  Próximo passo: vai ao backoffice PS e corre\n";
echo "  Catálogo → Produtos → [regenerar índice de pesquisa]\n";
echo "  ou via CLI: php bin/console prestashop:product:index\n";
echo "═══════════════════════════════════════════════════════\n\n";
