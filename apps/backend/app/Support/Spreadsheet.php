<?php

namespace App\Support;

use RuntimeException;

/**
 * Jadval fayllarini o'qish/yozish (.xlsx va .csv) — tashqi kutubxonasiz va ZipArchive kengaytmasisiz
 * (shared hostingda zip kengaytmasi bo'lmasligi mumkin). Faqat birinchi varaq, qiymatlar matn sifatida.
 */
final class Spreadsheet
{
    /** Ochilgan (decompressed) fayl uchun chegara — "zip bomba"dan himoya. */
    private const MAX_UNPACKED = 40 * 1024 * 1024;

    /**
     * @return list<list<string>> qatorlar (bo'sh qatorlar tashlab yuboriladi)
     */
    public static function read(string $bytes, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isZip = str_starts_with($bytes, "PK\x03\x04");
        if ($ext === 'xlsx' || $isZip) {
            if (! $isZip) {
                throw new RuntimeException("Fayl .xlsx formatida emas.");
            }

            return self::readXlsx($bytes);
        }
        if (in_array($ext, ['csv', 'txt', ''], true)) {
            return self::readCsv($bytes);
        }
        throw new RuntimeException('Faqat .xlsx yoki .csv fayl yuklang.');
    }

    // ── CSV ──────────────────────────────────────────────────

    public static function readCsv(string $bytes): array
    {
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        // Excel ba'zan CSV ni Windows-1251 da saqlaydi.
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
        }
        $first = strtok($bytes, "\n") ?: '';
        $delims = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($delims);
        $delim = (string) array_key_first($delims);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $bytes);
        rewind($h);
        $rows = [];
        while (($row = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            $row = array_map(fn ($v) => trim((string) $v), $row);
            if (array_filter($row, fn ($v) => $v !== '')) {
                $rows[] = $row;
            }
        }
        fclose($h);

        return $rows;
    }

    // ── XLSX: o'qish ─────────────────────────────────────────

    public static function readXlsx(string $bytes): array
    {
        $files = self::unzip($bytes, fn ($name) => $name === 'xl/workbook.xml' || $name === 'xl/_rels/workbook.xml.rels'
            || $name === 'xl/sharedStrings.xml' || str_starts_with($name, 'xl/worksheets/sheet'));
        $sheetPath = self::firstSheetPath($files) ?? 'xl/worksheets/sheet1.xml';
        if (! isset($files[$sheetPath])) {
            $sheets = array_values(array_filter(array_keys($files), fn ($n) => str_starts_with($n, 'xl/worksheets/sheet')));
            sort($sheets, SORT_NATURAL);
            $sheetPath = $sheets[0] ?? throw new RuntimeException("Faylda varaq topilmadi.");
        }
        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $xml = self::xml($files['xl/sharedStrings.xml']);
            foreach ($xml->si as $si) {
                $shared[] = self::text($si);
            }
        }
        $xml = self::xml($files[$sheetPath]);
        $rows = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $out = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = $ref !== '' ? self::colIndex($ref) : count($out);
                $type = (string) $c['t'];
                $v = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => self::number((string) $c->v),
                };
                for ($i = count($out); $i < $col; $i++) {
                    $out[$i] = '';
                }
                $out[$col] = trim($v);
            }
            if (array_filter($out, fn ($v) => $v !== '')) {
                ksort($out);
                $rows[] = array_values($out);
            }
        }

        return $rows;
    }

    /** "9.98901234567E+11" kabi raqamlarni butun ko'rinishga keltirish; "12.0" → "12". */
    private static function number(string $v): string
    {
        if (preg_match('/^-?\d+(\.\d+)?E\+?\d+$/i', $v)) {
            return sprintf('%.0f', (float) $v);
        }
        if (preg_match('/^(-?\d+)\.0+$/', $v, $m)) {
            return $m[1];
        }

        return $v;
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r as $r) {
            $s .= (string) $r->t;
        }

        return $s;
    }

    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function firstSheetPath(array $files): ?string
    {
        if (! isset($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'])) {
            return null;
        }
        $wb = self::xml($files['xl/workbook.xml']);
        $sheet = $wb->sheets->sheet[0] ?? null;
        if (! $sheet) {
            return null;
        }
        $rid = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        foreach (self::xml($files['xl/_rels/workbook.xml.rels'])->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return null;
    }

    private static function xml(string $s): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new RuntimeException("Faylni o'qib bo'lmadi (buzilgan .xlsx).");
        }

        return $xml;
    }

    /** @return array<string, string> kerakli fayllar: nom → mazmun */
    private static function unzip(string $zip, callable $want): array
    {
        $len = strlen($zip);
        $eocd = strrpos(substr($zip, max(0, $len - 65557)), "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException("Faylni o'qib bo'lmadi (buzilgan .xlsx).");
        }
        $eocd += max(0, $len - 65557);
        $e = unpack('vdisk/vcdDisk/ventriesDisk/ventries/Vsize/Voffset', substr($zip, $eocd + 4, 16));
        $pos = $e['offset'];
        $out = [];
        $total = 0;
        if ($e['entries'] > 2000) {
            throw new RuntimeException('Fayl juda katta.');
        }
        for ($i = 0; $i < $e['entries']; $i++) {
            if (substr($zip, $pos, 4) !== "PK\x01\x02") {
                throw new RuntimeException("Faylni o'qib bo'lmadi (buzilgan .xlsx).");
            }
            $h = unpack('vmethod/Vtime/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($zip, $pos + 10, 36));
            $name = substr($zip, $pos + 46, $h['nlen']);
            $pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
            if (! $want($name)) {
                continue;
            }
            $l = unpack('vnlen/velen', substr($zip, $h['offset'] + 26, 4));
            $data = substr($zip, $h['offset'] + 30 + $l['nlen'] + $l['elen'], $h['csize']);
            // Sarlavhadagi usize fayl tomonidan aytiladi — haqiqiy ochilgan hajm bo'yicha cheklaymiz (zip-bomba).
            $data = match ($h['method']) {
                0 => $data,
                8 => @gzinflate($data, max(1, self::MAX_UNPACKED - $total)),
                default => throw new RuntimeException("Faylni o'qib bo'lmadi (siqish usuli qo'llab-quvvatlanmaydi)."),
            };
            if ($data === false) {
                throw new RuntimeException("Faylni o'qib bo'lmadi yoki juda katta (.xlsx).");
            }
            $total += strlen($data);
            if ($total > self::MAX_UNPACKED) {
                throw new RuntimeException('Fayl juda katta.');
            }
            $out[$name] = $data;
        }

        return $out;
    }

    // ── XLSX: yozish (shablon) ───────────────────────────────

    /** @param list<list<string>> $rows birinchi qator — sarlavha (qalin) */
    public static function writeXlsx(array $rows, string $sheetName = 'Import'): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $row) {
            $sheet .= '<row r="'.($ri + 1).'">';
            foreach (array_values($row) as $ci => $v) {
                $ref = self::colName($ci).($ri + 1);
                // Hamma qiymat matn: telefon raqamlari 9.98E+11 ga aylanib ketmasin.
                $sheet .= '<c r="'.$ref.'" t="inlineStr"'.($ri === 0 ? ' s="1"' : '').'><is><t xml:space="preserve">'.$esc($v).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$esc(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/></cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        return self::zip($files, deflate: true);
    }

    /** Oddiy ZIP yozuvchi (stored yoki deflate). Testlar va shablon uchun. */
    public static function zip(array $files, bool $deflate = true): string
    {
        $data = '';
        $central = '';
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $packed = $deflate ? gzdeflate($content, 6) : $content;
            $method = $deflate ? 8 : 0;
            $offset = strlen($data);
            $header = pack('vvvvvVVVvv', 20, 0x0800, $method, 0, 0x21, $crc, strlen($packed), strlen($content), strlen($name), 0);
            $data .= "PK\x03\x04".$header.$name.$packed;
            $central .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $method, 0, 0x21, $crc, strlen($packed), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $data.$central."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }
}
