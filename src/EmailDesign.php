<?php
declare(strict_types=1);

/**
 * Block-based email designs.
 *
 * The composer edits a small JSON document ({settings, blocks}); this class
 * cleans it, and renders it to the email-safe HTML that is actually sent:
 * one 600px table, inline styles only, nothing that needs CSS support.
 *
 * Everything coming from the browser is re-validated here. Rich text goes
 * through an allow-list, so a design can never carry scripts or event handlers.
 */
final class EmailDesign
{
    public const BLOCK_TYPES = ['heading', 'text', 'button', 'image', 'box', 'divider', 'spacer', 'signature', 'html'];

    private const FALLBACK_BRAND = '#4f46e5';
    private const IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    private const MAX_IMAGE_BYTES = 3 * 1024 * 1024;

    /** Empty style values inherit the workspace brand from Settings. */
    public static function defaults(): array
    {
        return [
            'settings' => [
                'layout'      => 'card',     // card | none (legacy HTML shown exactly as written)
                'brand'       => '',
                'background'  => '#f4f4f5',
                'text_color'  => '#1f2933',
                'header_text' => '',
                'logo_url'    => '',
            ],
            'blocks' => [],
        ];
    }

    // ------------------------------------------------------------------ input

    /** Parse stored or submitted JSON into a clean design. Never trusts the shape. */
    public static function decode(?string $json): array
    {
        $raw = json_decode((string) $json, true);
        $raw = is_array($raw) ? $raw : [];
        $design = self::defaults();

        foreach ((array) ($raw['settings'] ?? []) as $key => $value) {
            if (array_key_exists($key, $design['settings']) && is_scalar($value)) {
                $design['settings'][$key] = trim((string) $value);
            }
        }
        $s = &$design['settings'];
        $s['layout'] = $s['layout'] === 'none' ? 'none' : 'card';
        foreach (['brand', 'background', 'text_color'] as $colour) {
            if ($s[$colour] !== '' && !self::isHex($s[$colour])) {
                $s[$colour] = $colour === 'brand' ? '' : self::defaults()['settings'][$colour];
            }
        }
        $s['header_text'] = mb_substr(strip_tags($s['header_text']), 0, 80);
        $s['logo_url'] = self::cleanUrl($s['logo_url'], true);

        foreach ((array) ($raw['blocks'] ?? []) as $block) {
            if (is_array($block) && in_array($block['type'] ?? null, self::BLOCK_TYPES, true)) {
                $design['blocks'][] = self::cleanBlock($block);
            }
        }
        return $design;
    }

    private static function cleanBlock(array $b): array
    {
        $align = in_array($b['align'] ?? '', ['left', 'center', 'right'], true) ? $b['align'] : 'left';

        return match ($b['type']) {
            'heading'   => ['type' => 'heading', 'text' => mb_substr(trim(strip_tags((string) ($b['text'] ?? ''))), 0, 200),
                            'level' => (int) ($b['level'] ?? 1) === 2 ? 2 : 1, 'align' => $align],
            'text'      => ['type' => 'text', 'html' => self::cleanHtml((string) ($b['html'] ?? '')), 'align' => $align],
            'box'       => ['type' => 'box', 'html' => self::cleanHtml((string) ($b['html'] ?? ''))],
            'button'    => ['type' => 'button', 'label' => mb_substr(trim(strip_tags((string) ($b['label'] ?? 'Click here'))), 0, 60),
                            'href' => self::cleanUrl((string) ($b['href'] ?? '')), 'align' => $align,
                            'color' => self::isHex((string) ($b['color'] ?? '')) ? (string) $b['color'] : ''],
            'image'     => ['type' => 'image', 'src' => self::cleanUrl((string) ($b['src'] ?? ''), true),
                            'alt' => mb_substr(trim(strip_tags((string) ($b['alt'] ?? ''))), 0, 120),
                            'href' => self::cleanUrl((string) ($b['href'] ?? '')),
                            'width' => max(10, min(100, (int) ($b['width'] ?? 100))), 'align' => $align],
            'divider'   => ['type' => 'divider'],
            'spacer'    => ['type' => 'spacer', 'height' => max(4, min(96, (int) ($b['height'] ?? 16)))],
            'signature' => ['type' => 'signature'],
            'html'      => ['type' => 'html', 'html' => self::cleanLooseHtml((string) ($b['html'] ?? ''))],
        };
    }

    public static function isHex(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-f]{6}$/i', $value);
    }

    /**
     * Links and image sources: web addresses, mail/phone links, merge fields
     * such as {{booking_link}}, or our own uploads/ folder. Everything else
     * (javascript:, data:, relative paths) is dropped.
     */
    public static function cleanUrl(string $url, bool $image = false): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $ok = $image
            ? preg_match('#^(https?://|uploads/[A-Za-z0-9._-]+$)#i', $url)
            : preg_match('#^(https?://|mailto:|tel:|\{\{[a-z_]+\}\})#i', $url);
        return $ok ? mb_substr($url, 0, 1000) : '';
    }

    /** Rich text allow-list: paragraphs, emphasis, links and lists only. */
    public static function cleanHtml(string $html): string
    {
        return self::sanitize($html, [
            'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
            'a' => ['href'], 'ul' => [], 'ol' => [], 'li' => [], 'div' => [], 'span' => [],
        ], strict: true);
    }

    /** Custom-HTML blocks: markup and styles allowed, anything executable removed. */
    public static function cleanLooseHtml(string $html): string
    {
        return self::sanitize($html, null, strict: false);
    }

    /**
     * @param array<string,array<int,string>>|null $allow tag => allowed attributes (null = any tag)
     */
    private static function sanitize(string $html, ?array $allow, bool $strict): string
    {
        if (trim($html) === '') {
            return '';
        }

        // libxml percent-encodes braces inside href/src, which would break
        // merge fields like {{booking_link}}. Park them as plain tokens first.
        $parked = [];
        $html = preg_replace_callback('/\{\{([a-z_]+)\}\}/', static function ($m) use (&$parked) {
            $key = 'MERGEFIELD' . count($parked) . 'X';
            $parked[$key] = $m[0];
            return $key;
        }, $html) ?? $html;

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $doc->getElementById('root');
        if (!$root) {
            return '';
        }

        $dangerous = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select',
                      'link', 'meta', 'base', 'frame', 'frameset', 'applet', 'svg', 'math'];

        $walk = static function (DOMNode $node) use (&$walk, $allow, $strict, $dangerous): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMComment) {
                    $node->removeChild($child);
                    continue;
                }
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->nodeName);

                if (in_array($tag, $dangerous, true) && ($strict || $tag !== 'style')) {
                    $node->removeChild($child);
                    continue;
                }
                if ($allow !== null && !array_key_exists($tag, $allow)) {
                    // Unknown tag: keep its text, drop the tag itself.
                    $walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->nodeName);
                    $value = trim($attr->nodeValue ?? '');
                    $keep = $allow === null ? !str_starts_with($name, 'on') : in_array($name, $allow[$tag], true);
                    if ($keep && in_array($name, ['href', 'src', 'action', 'background', 'xlink:href'], true)
                        && preg_match('#^\s*(javascript|vbscript|data):#i', $value)) {
                        $keep = false;
                    }
                    if ($keep && $name === 'style' && preg_match('/expression\s*\(|url\s*\(\s*[\'"]?\s*javascript:/i', $value)) {
                        $keep = false;
                    }
                    if (!$keep) {
                        $child->removeAttribute($attr->nodeName);
                    }
                }
                if ($tag === 'a' && $child->hasAttribute('href')
                    && !preg_match('#^(https?://|mailto:|tel:|MERGEFIELD\d+X)#i', $child->getAttribute('href'))) {
                    $child->removeAttribute('href');
                }
                $walk($child);
            }
        };
        $walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim(strtr($out, $parked));
    }

    // ----------------------------------------------------------------- output

    /** Email-safe HTML for a design. Merge fields are left for Mailer to fill. */
    public static function render(array $design, string $preheader = ''): string
    {
        $s = $design['settings'] ?? self::defaults()['settings'];
        $brand = self::brand($s);
        $text = self::isHex((string) ($s['text_color'] ?? '')) ? $s['text_color'] : '#1f2933';
        $bg = self::isHex((string) ($s['background'] ?? '')) ? $s['background'] : '#f4f4f5';

        $hidden = $preheader !== ''
            ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">' . e($preheader) . '</div>'
            : '';

        if (($s['layout'] ?? 'card') === 'none') {
            $body = '';
            foreach ($design['blocks'] ?? [] as $block) {
                $body .= self::block($block, $brand, $text);
            }
            return $hidden . $body;
        }

        [$logo, $header] = self::masthead($s);
        $head = '';
        if ($logo !== '') {
            $head = '<tr><td style="padding:24px 28px 8px;"><img src="' . e($logo) . '" alt="' . e($header ?: 'Logo')
                . '" height="44" style="display:block;height:44px;width:auto;border:0;"></td></tr>';
        } elseif ($header !== '') {
            $head = '<tr><td style="padding:22px 28px 4px;font-size:12px;font-weight:bold;letter-spacing:1.5px;color:' . $brand . ';">'
                . e(mb_strtoupper($header)) . '</td></tr>';
        }

        $rows = '';
        foreach ($design['blocks'] ?? [] as $block) {
            $rows .= self::block($block, $brand, $text);
        }

        return $hidden
            . '<div style="background:' . $bg . ';padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;border-top:4px solid ' . $brand . ';">'
            . $head
            . '<tr><td style="padding:8px 28px 28px;font-size:15px;line-height:1.6;color:' . $text . ';">'
            . $rows
            . '</td></tr></table></div>';
    }

    private static function block(array $b, string $brand, string $text): string
    {
        $align = $b['align'] ?? 'left';

        switch ($b['type']) {
            case 'heading':
                $size = ($b['level'] ?? 1) === 2 ? '18px' : '22px';
                return '<h' . ($b['level'] ?? 1) . ' style="margin:6px 0 12px;font-size:' . $size . ';line-height:1.3;font-weight:bold;color:'
                    . $text . ';text-align:' . $align . ';">' . e($b['text'] ?? '') . '</h' . ($b['level'] ?? 1) . '>';

            case 'text':
                return '<div style="text-align:' . $align . ';">' . self::styleRichText((string) ($b['html'] ?? ''), $brand, $text) . '</div>';

            case 'box':
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;background:'
                    . self::tint($brand, 0.92) . ';border-left:4px solid ' . $brand . ';border-radius:4px;">'
                    . '<tr><td style="padding:14px 16px;font-size:14px;line-height:1.7;color:' . $text . ';">'
                    . self::styleRichText((string) ($b['html'] ?? ''), $brand, $text, compact: true) . '</td></tr></table>';

            case 'button':
                $colour = ($b['color'] ?? '') !== '' ? $b['color'] : $brand;
                $table = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 14px;'
                    . ($align === 'center' ? 'margin-left:auto;margin-right:auto;' : ($align === 'right' ? 'margin-left:auto;' : '')) . '"><tr>'
                    . '<td style="background:' . $colour . ';border-radius:6px;">'
                    . '<a href="' . e(($b['href'] ?? '') !== '' ? $b['href'] : '#') . '" '
                    . 'style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">'
                    . e($b['label'] ?? '') . '</a></td></tr></table>';
                return $table;

            case 'image':
                if (($b['src'] ?? '') === '') {
                    return '';
                }
                $width = (int) ($b['width'] ?? 100);
                $img = '<img src="' . e(self::absoluteUrl($b['src'])) . '" alt="' . e($b['alt'] ?? '') . '" width="' . (int) round(544 * $width / 100)
                    . '" style="display:inline-block;width:' . $width . '%;max-width:100%;height:auto;border:0;border-radius:4px;">';
                if (($b['href'] ?? '') !== '') {
                    $img = '<a href="' . e($b['href']) . '">' . $img . '</a>';
                }
                return '<div style="margin:8px 0 14px;text-align:' . $align . ';">' . $img . '</div>';

            case 'divider':
                return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">';

            case 'spacer':
                $h = (int) ($b['height'] ?? 16);
                return '<div style="height:' . $h . 'px;line-height:' . $h . 'px;font-size:1px;">&nbsp;</div>';

            case 'signature':
                return '{{signature}}';

            case 'html':
                return (string) ($b['html'] ?? '');
        }
        return '';
    }

    /** Give cleaned rich text the inline styles email clients need. */
    private static function styleRichText(string $html, string $brand, string $text, bool $compact = false): string
    {
        if (trim($html) === '') {
            return '';
        }
        $styles = [
            'p'  => $compact ? 'margin:0 0 6px;' : 'margin:0 0 14px;',
            'ul' => 'margin:0 0 14px;padding-left:20px;',
            'ol' => 'margin:0 0 14px;padding-left:20px;',
            'li' => 'margin:0 0 4px;',
            'a'  => 'color:' . $brand . ';text-decoration:underline;',
        ];
        // Text written straight into the editor (no <p>) still gets spacing.
        if (!preg_match('/^\s*<(p|ul|ol|div)[\s>]/i', $html)) {
            $html = '<p>' . $html . '</p>';
        }
        return preg_replace_callback('/<(p|ul|ol|li|a)(\s[^>]*)?>/i', static function ($m) use ($styles) {
            $tag = strtolower($m[1]);
            $attrs = preg_replace('/\sstyle="[^"]*"/i', '', $m[2] ?? '');
            return '<' . $tag . $attrs . ' style="' . $styles[$tag] . '">';
        }, $html) ?? $html;
    }

    public static function brand(array $settings): string
    {
        foreach ([$settings['brand'] ?? '', Settings::get('brand_color')] as $candidate) {
            if (self::isHex((string) $candidate)) {
                return (string) $candidate;
            }
        }
        return self::FALLBACK_BRAND;
    }

    /**
     * What goes at the top: [logo URL or '', header text]. The email's own
     * choices win over the workspace defaults, in this order: its own logo,
     * its own header text, the workspace logo, then the workspace header.
     */
    private static function masthead(array $settings): array
    {
        if (($settings['logo_url'] ?? '') !== '') {
            return [self::absoluteUrl($settings['logo_url']), self::headerText($settings)];
        }
        if (trim((string) ($settings['header_text'] ?? '')) !== '') {
            return ['', trim((string) $settings['header_text'])];
        }
        $workspaceLogo = Settings::get('brand_logo');
        return [$workspaceLogo !== '' ? self::absoluteUrl($workspaceLogo) : '', self::headerText($settings)];
    }

    private static function headerText(array $settings): string
    {
        foreach ([$settings['header_text'] ?? '', Settings::get('brand_header'), Settings::get('company_name')] as $candidate) {
            if (trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }
        return '';
    }

    /** Uploaded images are stored as "uploads/x.png"; emails need a full address. */
    public static function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'uploads/') ? public_url($url) : $url;
    }

    /** Mix a colour towards white: $amount 0 = unchanged, 1 = white. */
    public static function tint(string $hex, float $amount): string
    {
        $rgb = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $mixed = array_map(static fn($c) => (int) round($c + (255 - $c) * $amount), $rgb);
        return sprintf('#%02x%02x%02x', ...$mixed);
    }

    // ---------------------------------------------------------------- uploads

    /** Store an email image in public/uploads (it must be reachable by email clients). */
    public static function storeImage(array $file): string|array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'The image upload failed. Please try again.';
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::IMAGE_TYPES[$ext])) {
            return 'Use a PNG, JPG, GIF or WebP image.';
        }
        if ((int) $file['size'] > self::MAX_IMAGE_BYTES) {
            return 'Images must be under 3 MB; smaller images load faster in inboxes.';
        }
        // The real content must be the type the name claims. A file that is a
        // GIF inside but named .png (a classic way to smuggle code) is refused.
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false || $info['mime'] !== self::IMAGE_TYPES[$ext] || $info[0] < 1 || $info[1] < 1) {
            return 'That file is not a valid ' . strtoupper($ext === 'jpeg' ? 'jpg' : $ext) . ' image.';
        }
        if (preg_match('/<\?php|<script/i', (string) file_get_contents((string) $file['tmp_name']))) {
            return 'That image contains code and was rejected.';
        }

        $name = bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $dir = BASE_PATH . '/public/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!move_uploaded_file((string) $file['tmp_name'], "$dir/$name")) {
            return 'Could not save the image.';
        }
        self::reencode("$dir/$name", $ext);
        return ['url' => 'uploads/' . $name, 'width' => $info[0], 'height' => $info[1]];
    }

    /**
     * Redraw PNG and JPEG uploads with GD. The saved file then holds only
     * pixels: any bytes hidden after the image data are gone. GIFs are kept
     * as uploaded so animation survives; they have already passed the checks.
     */
    private static function reencode(string $path, string $ext): void
    {
        if (!function_exists('imagecreatefromstring') || !in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            return;
        }
        $img = @imagecreatefromstring((string) file_get_contents($path));
        if ($img === false) {
            return;
        }
        if ($ext === 'png') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagepng($img, $path, 6);
        } else {
            imagejpeg($img, $path, 88);
        }
        imagedestroy($img);
    }

    // ---------------------------------------------------------------- content

    /** Wrap an old hand-written HTML email so it opens in the composer unchanged. */
    public static function fromLegacyHtml(string $html): array
    {
        $design = self::defaults();
        $design['settings']['layout'] = 'none';
        $design['blocks'][] = ['type' => 'html', 'html' => self::cleanLooseHtml($html)];
        return $design;
    }

    /** A design with a few starter blocks. */
    public static function blank(): array
    {
        $design = self::defaults();
        $design['blocks'] = [
            ['type' => 'heading', 'text' => 'Your headline', 'level' => 1, 'align' => 'left'],
            ['type' => 'text', 'html' => '<p>Hi {{first_name}},</p><p>Write your message here. Keep it short: one idea, one ask.</p>', 'align' => 'left'],
            ['type' => 'button', 'label' => 'Reply to me', 'href' => 'mailto:{{sender_email}}', 'align' => 'left', 'color' => ''],
            ['type' => 'signature'],
        ];
        return $design;
    }

    /** Legacy template name => sample key, used when upgrading existing emails. */
    public static function sampleKeyForLegacyName(string $name): ?string
    {
        return [
            'Workshop 1 - Invitation' => 'invite',
            'Workshop 2 - Follow-up'  => 'followup',
            'Workshop 3 - Last note'  => 'lastcall',
            'Workshop - Brochure & registration (send when interested)' => 'brochure',
        ][$name] ?? null;
    }

    /**
     * The workshop sequence as editable designs: the sample campaign every
     * workspace can start from. Colours and logo inherit the workspace brand.
     *
     * @return array<string, array{name:string, subject:string, preheader:string, design:array}>
     */
    public static function samples(): array
    {
        $event = static fn(bool $fees) =>
            '<strong>Corporate Communication, Stakeholder Engagement &amp; Sustainability Workshop</strong><br>'
            . '<strong>Date:</strong> 1&ndash;2 October 2026<br>'
            . '<strong>Venue:</strong> City Lodge Hotel, Fourways, Johannesburg<br>'
            . ($fees
                ? '<strong>In person:</strong> R10,999 excl. VAT<br><strong>Virtual:</strong> R5,999 excl. VAT<br>Includes refreshments, lunch and course materials.'
                : '<strong>Format:</strong> in person or virtual');

        $mk = static function (array $blocks): array {
            $d = self::defaults();
            $d['blocks'] = $blocks;
            return $d;
        };
        $text = static fn(string $html) => ['type' => 'text', 'html' => $html, 'align' => 'left'];
        $button = static fn(string $label, string $subject) => [
            'type' => 'button', 'label' => $label, 'align' => 'left', 'color' => '',
            'href' => 'mailto:{{sender_email}}?subject=' . rawurlencode($subject),
        ];

        return [
            'invite' => [
                'name'      => 'Workshop 1 - Invitation',
                'subject'   => 'Stakeholder engagement & sustainability workshop, 1-2 Oct (Fourways)',
                'preheader' => 'Two practical days for communication and CSR leaders. Shall I send the brochure?',
                'design'    => $mk([
                    $text('<p>Hi {{first_name}},</p><p>Is your team looking to sharpen how {{company}} handles stakeholder engagement and sustainability reporting?</p>'),
                    ['type' => 'box', 'html' => $event(false)],
                    $text('<p>Over two practical days, senior practitioners cover:</p><ul><li>Crisis communication when news breaks in minutes</li>'
                        . '<li>Measuring communication results your board cares about</li><li>Aligning CSR strategy with business objectives</li>'
                        . '<li>Sustainability reports stakeholders actually read</li></ul>'
                        . '<p>It is built for heads and managers of Corporate Communication, Corporate Affairs, CSR, Sustainability and Stakeholder Relations.</p>'
                        . '<p><strong>Would it help if I sent you the brochure and registration form?</strong> Just reply &ldquo;yes&rdquo;, or tap the button.</p>'),
                    $button('Yes, send me the brochure', 'Yes, please send the workshop brochure'),
                    ['type' => 'signature'],
                ]),
            ],
            'followup' => [
                'name'      => 'Workshop 2 - Follow-up',
                'subject'   => 'Re: Stakeholder engagement & sustainability workshop, 1-2 Oct (Fourways)',
                'preheader' => 'In person or virtual, with fees that include lunch and materials.',
                'design'    => $mk([
                    $text('<p>Hi {{first_name}},</p><p>Following up on my note about the workshop on 1&ndash;2 October. Two details that may help you decide:</p>'
                        . '<ul><li>Attend <strong>in person</strong> at City Lodge Fourways (R10,999 excl. VAT) or <strong>virtually</strong> (R5,999 excl. VAT).</li>'
                        . '<li>Fees include refreshments, lunch and all course materials.</li></ul>'
                        . '<p>Is this relevant for you or someone on your team? A one-word reply is perfect.</p>'),
                    $button('Send me the brochure', 'Please send the workshop brochure'),
                    ['type' => 'signature'],
                ]),
            ],
            'lastcall' => [
                'name'      => 'Workshop 3 - Last note',
                'subject'   => 'Last note: 1-2 October workshop',
                'preheader' => 'No more follow-ups after this one.',
                'design'    => $mk([
                    $text('<p>Hi {{first_name}},</p><p>I haven&rsquo;t heard back, so this is my last note about the Corporate Communication, '
                        . 'Stakeholder Engagement &amp; Sustainability Workshop (1&ndash;2 October, Fourways or virtual).</p>'
                        . '<p>If the timing isn&rsquo;t right, no problem &mdash; I won&rsquo;t follow up again.</p>'
                        . '<p>If someone else at {{company}} looks after communication or CSR training, I&rsquo;d be grateful if you could point me to them.</p>'),
                    $button('Send me the brochure', 'Please send the workshop brochure'),
                    ['type' => 'signature'],
                ]),
            ],
            'brochure' => [
                'name'      => 'Workshop - Brochure & registration (send when interested)',
                'subject'   => 'Brochure & registration form: 1-2 October workshop',
                'preheader' => 'The brochure is attached, with the registration form on the last page.',
                'design'    => $mk([
                    $text('<p>Hi {{first_name}},</p><p>Thank you for your interest. The brochure is attached, with the full two-day agenda and the registration form on the last page.</p>'),
                    ['type' => 'box', 'html' => $event(true)],
                    $text('<p><strong>To secure your seat:</strong></p><ol><li>Complete the registration form on the last page of the brochure (one form covers up to 4 delegates).</li>'
                        . '<li>Reply to this email with the form attached.</li><li>We confirm every booking within 1 business day.</li></ol>'
                        . '<p>Questions? Just reply here.</p>'),
                    ['type' => 'signature'],
                ]),
            ],
        ];
    }
}
