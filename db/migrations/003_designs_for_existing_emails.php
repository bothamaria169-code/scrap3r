<?php
/**
 * Give every existing email a composer design so it opens in the visual editor.
 *
 * - The four workshop emails become the structured sample designs (same words,
 *   now editable block by block). Subjects and attachments are kept.
 * - Any other email is wrapped unchanged in a single custom-HTML block.
 * - The brand colour and header text found in the old emails become the
 *   workspace brand, so the new designs look the same as before.
 */
return static function (PDO $pdo): void {
    $rows = $pdo->query('SELECT id, name, body_html FROM templates WHERE design_json IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }

    $brand = null;
    $header = null;
    foreach ($rows as $t) {
        $html = (string) $t['body_html'];
        if (!$brand && preg_match('/border-top:4px solid (#[0-9a-fA-F]{6})/', $html, $m)) {
            $brand = $m[1];
        }
        if (!$header && preg_match('/letter-spacing:1\.5px;color:#[0-9a-fA-F]{6};">([^<]+)<\/td>/', $html, $m)) {
            $header = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
    }

    $setIfEmpty = $pdo->prepare(
        "INSERT INTO settings (skey, svalue) VALUES (:k, :v)
         ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue WHERE settings.svalue IS NULL OR settings.svalue = ''"
    );
    if ($brand) {
        $setIfEmpty->execute(['k' => 'brand_color', 'v' => $brand]);
    }
    if ($header) {
        $setIfEmpty->execute(['k' => 'brand_header', 'v' => ucwords(strtolower($header))]);
    }

    $samples = EmailDesign::samples();
    $update = $pdo->prepare(
        'UPDATE templates SET design_json = :d, body_html = :h, preheader = COALESCE(preheader, :p), is_sample = :s WHERE id = :id'
    );

    foreach ($rows as $t) {
        $key = EmailDesign::sampleKeyForLegacyName((string) $t['name']);
        if ($key !== null) {
            $design = $samples[$key]['design'];
            $preheader = $samples[$key]['preheader'];
            $isSample = 1;
        } else {
            $design = EmailDesign::fromLegacyHtml((string) $t['body_html']);
            $preheader = null;
            $isSample = 0;
        }
        $update->execute([
            'd'  => json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'h'  => EmailDesign::render($design, (string) $preheader),
            'p'  => $preheader,
            's'  => $isSample,
            'id' => $t['id'],
        ]);
    }
};
