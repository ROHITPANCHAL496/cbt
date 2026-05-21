<?php
// portal/api/docx_parser.php
// Pure PHP DOCX parser — no Python needed
// A .docx file is just a ZIP containing XML files

function parseQuestionDocx(string $filePath): array {
    $xml = extractDocxXml($filePath);
    if (!$xml) return [];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadXML($xml);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $questions      = [];
    $current_subject = null;
    $q_num          = 0;

    $subjectMap = [
        'PHYSICS'     => 'Physics',
        'CHEMISTRY'   => 'Chemistry',
        'BIOLOGY'     => 'Biology',
        'BOTANY'      => 'Biology',
        'ZOOLOGY'     => 'Biology',
        'MATHEMATICS' => 'Mathematics',
        'MATHS'       => 'Mathematics',
        'MATH'        => 'Mathematics',
    ];

    // ── Strategy 1: Table-based (bilingual side-by-side) ──
    $tables = $xpath->query('//w:tbl');
    if ($tables->length > 0) {
        foreach ($tables as $table) {
            $rows = $xpath->query('w:tr', $table);
            foreach ($rows as $row) {
                $cells = $xpath->query('w:tc', $row);
                if ($cells->length === 0) continue;

                $cell0 = trim(getNodeText($xpath, $cells->item(0)));
                $cell1 = $cells->length > 1 ? trim(getNodeText($xpath, $cells->item(1))) : '';

                // Subject header row detection
                $upperCell = strtoupper($cell0);
                foreach ($subjectMap as $key => $val) {
                    if (strpos($upperCell, $key) !== false) {
                        $current_subject = $val;
                        break;
                    }
                }

                // Question row detection: starts with number + dot
                if (preg_match('/^(\d{1,3})[\.]\s*(.*)/s', $cell0, $m)) {
                    $q_num++;
                    $en = extractOptions($m[2]);
                    // Strip leading number from gujarati cell
                    $gu_raw = preg_replace('/^\d{1,3}[\.]\s*/', '', $cell1);
                    $gu     = extractOptions($gu_raw);

                    $questions[] = buildQuestion($q_num, $current_subject, $en, $gu);
                }
            }
        }
    }

    // ── Strategy 2: Paragraph-based (single column) ──
    if (empty($questions)) {
        $paras  = $xpath->query('//w:p');
        $cur_q  = null;

        foreach ($paras as $p) {
            $text = trim(getNodeText($xpath, $p));
            if (empty($text)) continue;

            // Subject detection
            $upper = strtoupper($text);
            foreach ($subjectMap as $key => $val) {
                if (strpos($upper, $key) !== false) {
                    $current_subject = $val;
                }
            }

            // Question detection
            if (preg_match('/^(\d{1,3})[\.]\s+(.*)/s', $text, $m)) {
                if ($cur_q) $questions[] = $cur_q;
                $opts  = extractOptions($m[2]);
                $cur_q = buildQuestion(++$q_num, $current_subject, $opts, [
                    'question'=>'','A'=>'','B'=>'','C'=>'','D'=>''
                ]);
                continue;
            }

            // Option continuation for paragraph mode
            if ($cur_q && preg_match('/^\(\s*([1-4ABCD])\s*\)\s*(.*)/s', $text, $m)) {
                $keyMap = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D',
                           'A'=>'A','B'=>'B','C'=>'C','D'=>'D'];
                $key = $keyMap[strtoupper($m[1])] ?? '';
                if ($key) $cur_q["opt_{$key}_en"] = trim($m[2]);
            }
        }
        if ($cur_q) $questions[] = $cur_q;
    }

    return $questions;
}

function parseAnswerKeyDocx(string $filePath): array {
    $xml = extractDocxXml($filePath);
    if (!$xml) return [];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadXML($xml);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $answers = [];
    $map     = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];

    foreach ($xpath->query('//w:p') as $p) {
        $text = trim(getNodeText($xpath, $p));
        // Matches: "1. (2)" or "**1.** (2)" or "1.(B)"
        if (preg_match('/\*{0,2}(\d{1,3})\*{0,2}[\.]\s*[\(]?\s*([1-4ABCD])\s*[\)]?/i', $text, $m)) {
            $ans = strtoupper($m[2]);
            $answers[(int)$m[1]] = $map[$ans] ?? $ans;
        }
    }

    return $answers;
}

// ── Helpers ─────────────────────────────────────────

function extractDocxXml(string $filePath): ?string {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return null;
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    return $xml ?: null;
}

function getNodeText(DOMXPath $xpath, DOMNode $node): string {
    $texts = $xpath->query('.//w:t', $node);
    $out   = '';
    foreach ($texts as $t) $out .= $t->nodeValue;
    return $out;
}

function extractOptions(string $text): array {
    $result = ['question' => $text, 'A' => '', 'B' => '', 'C' => '', 'D' => ''];
    // Split on (1)/(2)/(3)/(4) or (A)/(B)/(C)/(D)
    $parts = preg_split('/\(\s*([1-4ABCD])\s*\)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (count($parts) < 3) return $result;

    $result['question'] = trim($parts[0]);
    $map = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D',
            'A'=>'A','B'=>'B','C'=>'C','D'=>'D'];

    for ($i = 1; $i + 1 < count($parts); $i += 2) {
        $key = $map[strtoupper(trim($parts[$i]))] ?? '';
        $val = trim($parts[$i + 1] ?? '');
        if ($key) $result[$key] = $val;
    }
    return $result;
}

function buildQuestion(int $num, ?string $subject, array $en, array $gu): array {
    return [
        'q_number'    => $num,
        'subject'     => $subject ?? 'Unknown',
        'question_en' => $en['question'] ?? '',
        'question_gu' => $gu['question'] ?? '',
        'opt_a_en'    => $en['A'] ?? '',
        'opt_b_en'    => $en['B'] ?? '',
        'opt_c_en'    => $en['C'] ?? '',
        'opt_d_en'    => $en['D'] ?? '',
        'opt_a_gu'    => $gu['A'] ?? '',
        'opt_b_gu'    => $gu['B'] ?? '',
        'opt_c_gu'    => $gu['C'] ?? '',
        'opt_d_gu'    => $gu['D'] ?? '',
    ];
}
