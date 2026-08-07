<?php

namespace Tool\Support;

/**
 * Excelからコピーしてテキストエリアに貼り付けたデータ(タブ区切り、1行目はヘッダー行)を
 * パース/生成する。武器/素材/防具/秘宝/レシピ/CPUパターンの貼り付けインポート・エクスポート
 * 機能で共通に使う(build()で作った出力がparse()にそのまま貼り戻せる形式)。
 * 列の並びはヘッダー名で対応付けるため、Excel側で列順が入れ替わっていても解釈できる。
 */
class TsvParser
{
    /**
     * @return array{headers: string[], rows: array<int, array<string, string>>}
     */
    public static function parse(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $lines = array_values(array_filter($lines, fn (string $line) => trim($line) !== ''));

        if (empty($lines)) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map('trim', explode("\t", array_shift($lines)));

        $rows = array_map(function (string $line) use ($headers) {
            $cells = explode("\t", $line);
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = trim($cells[$i] ?? '');
            }

            return $row;
        }, $lines);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  string[]  $headers
     * @param  array<int, array<string, mixed>>  $rows  各要素は$headersと同じキーを持つ連想配列
     */
    public static function build(array $headers, array $rows): string
    {
        $lines = [implode("\t", $headers)];

        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(
                fn (string $header) => self::sanitizeCell($row[$header] ?? ''),
                $headers,
            ));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * TSVはタブ・改行を値として表現できないため、含まれていた場合は半角スペースに置き換える。
     */
    private static function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        }

        return str_replace(["\t", "\r", "\n"], ' ', (string) $value);
    }
}
