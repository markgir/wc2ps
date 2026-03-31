<?php
declare(strict_types=1);

/**
 * FieldMapper — static helpers and mapping tables for WC → PS field conversion.
 */
class FieldMapper
{
    // WC postmeta key → PS ps_product column
    public const META_TO_PRODUCT = [
        '_regular_price' => 'price',
        '_sku'           => 'reference',
        '_weight'        => 'weight',
        '_length'        => 'depth',
        '_width'         => 'width',
        '_height'        => 'height',
    ];

    // PS ps_product defaults (used when WC data is absent)
    public const PRODUCT_DEFAULTS = [
        'id_tax_rules_group'   => 1,
        'id_category_default'  => 2,
        'active'               => 1,
        'available_for_order'  => 1,
        'show_price'           => 1,
        'indexed'              => 1,
        'visibility'           => 'both',
        'condition'            => 'new',
        'minimal_quantity'     => 1,
        'out_of_stock'         => 2,
        'redirect_type'        => '404',
        'on_sale'              => 0,
        'online_only'          => 0,
        'ecotax'               => 0,
        'unit_price_ratio'     => 0,
        'customizable'         => 0,
        'uploadable_files'     => 0,
        'text_fields'          => 0,
        'is_virtual'           => 0,
        'cache_is_pack'        => 0,
        'cache_has_attachments'=> 0,
        'pack_stock_type'      => 3,
    ];

    // Max lengths for PS lang fields
    public const MAX_LENGTHS = [
        'name'              => 128,
        'link_rewrite'      => 128,
        'reference'         => 64,
        'description_short' => 800,
        'meta_title'        => 128,
        'meta_description'  => 512,
        'meta_keywords'     => 255,
    ];

    // ── String helpers ──────────────────────────────────────────────────────

    public static function stripHtml(string $html): string
    {
        $result = preg_replace('/<(br|p|div|h[1-6]|li|tr|td|th|blockquote|pre)[^>]*>/i', "\n", $html) ?? $html;
        $text   = strip_tags($result);
        $text   = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text   = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text   = preg_replace('/(\r\n|\r|\n){3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    public static function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $t2   = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($t2 !== false && $t2 !== '') $text = $t2;
        $result = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        $result = trim($result, '-');
        return $result !== '' ? $result : 'item';
    }

    public static function truncate(string $value, int $max): string
    {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    // ── Numeric helpers ─────────────────────────────────────────────────────

    public static function toFloat($value): float
    {
        if ($value === null || $value === '') return 0.0;
        $str = str_replace(',', '.', (string) $value);
        $str = preg_replace('/[^0-9.\-]/', '', $str) ?? '0';
        return (float) $str;
    }

    public static function toInt($value): int
    {
        return (int) static::toFloat($value);
    }

    // ── Status / type maps ──────────────────────────────────────────────────

    public static function mapStockStatus(string $status): int
    {
        return match ($status) {
            'instock', 'onbackorder' => 1,
            'outofstock'             => 0,
            default                  => 2,
        };
    }

    public static function mapProductType(string $wcType): string
    {
        return match ($wcType) {
            'variable' => 'variable',
            'grouped'  => 'grouped',
            'external' => 'external',
            default    => 'simple',
        };
    }

    /**
     * Detect if a value is a PHP serialised string.
     */
    public static function isSerialized(string $value): bool
    {
        return strlen($value) > 1
            && in_array($value[0], ['a','O','s','i','d','b'], true)
            && $value[1] === ':';
    }

    /**
     * Safely unserialise a WC meta value — objects are NOT allowed.
     */
    public static function safeUnserialize(string $value)
    {
        if (!static::isSerialized($value)) return $value;
        $result = @unserialize($value, ['allowed_classes' => false]);
        return ($result !== false) ? $result : $value;
    }

    /**
     * Coerce empty strings to 0 for MySQL numeric columns.
     * NULL is preserved — it carries semantic meaning in PS
     * (e.g. default_on, cover).
     */
    public static function coerceNumeric($value, string $columnType)
    {
        if ($value === null) return null;
        $isNumeric = (bool) preg_match(
            '/\b(int|tinyint|smallint|mediumint|bigint|float|double|decimal|numeric|real)\b/',
            $columnType
        );
        if ($isNumeric && $value === '') return 0;
        return $value;
    }
}
