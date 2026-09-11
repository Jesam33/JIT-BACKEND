<?php

namespace App\Support;

/**
 * Converts a PowerPoint (.pptx) into an editable Word (.docx), pure PHP, no
 * libraries. Gamma's export API only offers pdf/pptx/png — there is no docx —
 * so the "Word (editable)" option in AI materials downloads the pptx export
 * (the editable master) and converts it here before storing.
 *
 * Both formats are ZIP archives of XML, so this is a read-XML/write-XML
 * pipeline over ZipArchive:
 *  • Read ppt/slides/slide{N}.xml in numeric order. Per slide: the title
 *    placeholder becomes a Heading 1, every other shape's text becomes
 *    paragraphs, and each embedded picture (resolved through the slide's
 *    .rels into ppt/media/) is re-embedded inline.
 *  • Emit a minimal valid docx: [Content_Types].xml, _rels/.rels,
 *    word/document.xml, word/_rels/document.xml.rels, word/styles.xml and
 *    the copied word/media/* files.
 *
 * Layout is necessarily lost (slides are canvases, Word is a flow) — titles,
 * body text and images survive in reading order, which is what an editable
 * handout needs. Returns null when the input can't be parsed; the caller
 * turns that into a retry-later message.
 */
class PptxToDocx
{
    /** Slide-text namespaces: presentationml (p), drawingml (a), rels (r). */
    private const NS = [
        'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    ];

    /** Media extensions we re-embed (also the docx Content_Types Defaults). */
    private const MEDIA_EXTS = ['png', 'jpeg', 'jpg', 'gif'];

    /** Max inline image width in EMU (914400/inch → 6 inches of usable page). */
    private const MAX_WIDTH_EMU = 5486400;

    public static function convert(string $pptxBytes): ?string
    {
        $model = self::readSlides($pptxBytes);
        if ($model === null || ! $model['slides']) {
            return null;
        }

        $built = self::buildDocument($model);
        if ($built === null) {
            return null;
        }
        [$documentXml, $imageRels] = $built;

        return self::zipDocx($documentXml, $imageRels, $model['media']);
    }

    /**
     * Parse the pptx into a render model:
     * ['slides' => [['title' => ?string, 'paragraphs' => string[],
     *                'images' => string[] media paths]]],
     *  'media' => [mediaPath => bytes]] — media at the top level so an image
     * used by several slides is stored once.
     */
    private static function readSlides(string $pptxBytes): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        if ($tmp === false || file_put_contents($tmp, $pptxBytes) === false) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            return null;
        }

        try {
            $slideFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', (string) $name, $m)) {
                    $slideFiles[(int) $m[1]] = (string) $name;
                }
            }
            if (! $slideFiles) {
                return null;
            }
            ksort($slideFiles, SORT_NUMERIC);

            $slides = [];
            $media = [];

            foreach ($slideFiles as $slideFile) {
                $xml = simplexml_load_string((string) $zip->getFromName($slideFile));
                if ($xml === false) {
                    continue; // A broken slide is skipped, not fatal.
                }

                foreach (self::NS as $prefix => $uri) {
                    $xml->registerXPathNamespace($prefix, $uri);
                }

                $rels = self::slideRels($zip, $slideFile);

                $title = null;
                $paragraphs = [];
                $images = [];

                // Shapes in document order. The title placeholder (title /
                // ctrTitle) becomes the heading; other shapes contribute their
                // text runs, one paragraph per shape (runs inside a shape join).
                foreach ($xml->xpath('//p:sp') ?: [] as $shape) {
                    $texts = [];
                    foreach ($shape->xpath('.//a:t') ?: [] as $t) {
                        $texts[] = (string) $t;
                    }
                    $text = trim(implode('', $texts));
                    if ($text === '') {
                        continue;
                    }

                    $phType = null;
                    $ph = $shape->xpath('p:nvSpPr/p:nvPr/p:ph');
                    if ($ph && isset($ph[0]['type'])) {
                        $phType = (string) $ph[0]['type'];
                    }

                    if ($phType === 'title' || $phType === 'ctrTitle') {
                        $title = $title ?? $text;
                    } else {
                        $paragraphs[] = $text;
                    }
                }

                // Pictures: the blip's r:embed id resolves through the slide's
                // .rels to a ppt/media/ entry, copied once into the media map.
                foreach ($xml->xpath('//p:pic') ?: [] as $pic) {
                    $blip = $pic->xpath('.//a:blip');
                    if (! $blip) {
                        continue;
                    }
                    $rid = (string) ($blip[0]->attributes(self::NS['r'])['embed'] ?? '');
                    if ($rid === '' || ! isset($rels[$rid])) {
                        continue;
                    }
                    $mediaPath = $rels[$rid];
                    if (! isset($media[$mediaPath])) {
                        $bytes = $zip->getFromName($mediaPath);
                        if ($bytes === false) {
                            continue;
                        }
                        $media[$mediaPath] = $bytes;
                    }
                    $images[] = $mediaPath;
                }

                $slides[] = ['title' => $title, 'paragraphs' => $paragraphs, 'images' => $images];
            }

            return ['slides' => $slides, 'media' => $media];
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    /**
     * rId → normalized ppt/media/... path for one slide, from its .rels part.
     * Targets are relative to ppt/slides/, e.g. "../media/image1.png".
     */
    private static function slideRels(\ZipArchive $zip, string $slideFile): array
    {
        $relsXml = $zip->getFromName('ppt/slides/_rels/' . basename($slideFile) . '.rels');
        if ($relsXml === false) {
            return [];
        }

        $rels = simplexml_load_string((string) $relsXml);
        if ($rels === false) {
            return [];
        }

        $map = [];
        foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
            // Attributes() is required here: plain $rel['Id'] on an element
            // obtained through children(ns) resolves nothing (SimpleXML quirk).
            $attrs = $rel->attributes();
            $id = isset($attrs['Id']) ? (string) $attrs['Id'] : '';
            $target = isset($attrs['Target']) ? (string) $attrs['Target'] : '';
            if ($id === '' || $target === '') {
                continue;
            }

            // Resolve ../ segments against ppt/slides/ to a real zip path.
            $parts = ['ppt', 'slides'];
            foreach (explode('/', $target) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    array_pop($parts);
                    continue;
                }
                $parts[] = $segment;
            }

            $path = implode('/', $parts);
            if (str_starts_with($path, 'ppt/media/')
                && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MEDIA_EXTS, true)) {
                $map[$id] = $path;
            }
        }

        return $map;
    }

    /**
     * Build [word/document.xml body, image <Relationship> entries] from the
     * render model, assigning one docx rId per distinct media path. Returns
     * null when no slide produced anything (a text/image-free deck has
     * nothing to convert).
     */
    private static function buildDocument(array $model): ?array
    {
        $body = '';
        $imageRels = '';
        $rids = []; // mediaPath → docx rId, assigned on first use.
        $nextRid = 110; // rIds 1+ are the fixed document/styles parts.
        $docPrId = 1;
        $anyContent = false;

        foreach ($model['slides'] as $slide) {
            if ($slide['title'] !== null) {
                $body .= self::paragraph(self::esc($slide['title']), 'Heading1');
                $anyContent = true;
            }
            foreach ($slide['paragraphs'] as $text) {
                $body .= self::paragraph(self::esc($text));
                $anyContent = true;
            }
            foreach ($slide['images'] as $mediaPath) {
                if (! isset($rids[$mediaPath])) {
                    $rid = 'rId' . ($nextRid++);
                    $rids[$mediaPath] = $rid;
                    $imageRels .= '<Relationship Id="' . $rid . '"'
                        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
                        . ' Target="media/' . self::esc(basename($mediaPath)) . '"/>';
                }
                $body .= self::imageParagraph($rids[$mediaPath], $model['media'][$mediaPath], $docPrId++);
                $anyContent = true;
            }
        }

        if (! $anyContent) {
            return null;
        }

        return [$body, $imageRels];
    }

    private static function paragraph(string $escapedText, ?string $style = null): string
    {
        $pPr = $style !== null
            ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>'
            : '';

        return '<w:p>' . $pPr . '<w:r><w:t xml:space="preserve">' . $escapedText . '</w:t></w:r></w:p>';
    }

    /**
     * Inline-picture paragraph, sized to fit the page width while keeping the
     * aspect ratio. Dimensions come from the image header (PNG/GIF/JPEG); a
     * header we can't read falls back to a 16:9 placeholder box.
     */
    private static function imageParagraph(string $rid, string $bytes, int $docPrId): string
    {
        [$w, $h] = self::imageDimensions($bytes) ?? [960, 540];

        $cx = self::MAX_WIDTH_EMU;
        $cy = (int) round($cx * $h / max($w, 1));

        return '<w:p><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:docPr id="' . $docPrId . '" name="Picture ' . $docPrId . '"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $docPrId . '" name="image' . $docPrId . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline></w:drawing></w:r></w:p>';
    }

    /** PNG/GIF/JPEG pixel size straight from the header, or null. */
    private static function imageDimensions(string $bytes): ?array
    {
        $len = strlen($bytes);

        // PNG: 8-byte signature, then IHDR with big-endian width/height.
        if ($len > 24 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return [unpack('N', substr($bytes, 16, 4))[1], unpack('N', substr($bytes, 20, 4))[1]];
        }

        // GIF: little-endian width/height at offset 6.
        if ($len > 10 && in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return [unpack('v', substr($bytes, 6, 2))[1], unpack('v', substr($bytes, 8, 2))[1]];
        }

        // JPEG: walk the segments to an SOFn marker, height/width big-endian.
        if ($len > 4 && substr($bytes, 0, 2) === "\xFF\xD8") {
            $pos = 2;
            while ($pos + 9 < $len) {
                if ($bytes[$pos] !== "\xFF") {
                    $pos++;
                    continue;
                }
                $marker = ord($bytes[$pos + 1]);
                // SOF0..SOF15 except DHT(0xC4), JPG(0xC8), DAC(0xCC).
                if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                    return [unpack('n', substr($bytes, $pos + 7, 2))[1], unpack('n', substr($bytes, $pos + 5, 2))[1]];
                }
                $segmentLength = unpack('n', substr($bytes, $pos + 2, 2))[1];
                if ($segmentLength < 2) {
                    return null;
                }
                $pos += 2 + $segmentLength;
            }
        }

        return null;
    }

    /** Assemble the final docx zip. */
    private static function zipDocx(string $documentBody, string $imageRels, array $media): ?string
    {
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/>'
            . '<Default Extension="gif" ContentType="image/gif"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . $imageRels
            . '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/>'
            . '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
            . '<w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="280" w:after="140"/>'
            . '<w:outlineLvl w:val="0"/></w:pPr>'
            . '<w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
            . '</w:styles>';

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . $documentBody
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>'
            . '</w:body></w:document>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        if ($tmp === false) {
            return null;
        }

        try {
            $out = new \ZipArchive();
            if ($out->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            $out->addFromString('[Content_Types].xml', $contentTypes);
            $out->addFromString('_rels/.rels', $rootRels);
            $out->addFromString('word/document.xml', $document);
            $out->addFromString('word/_rels/document.xml.rels', $docRels);
            $out->addFromString('word/styles.xml', $styles);

            foreach ($media as $mediaPath => $bytes) {
                $out->addFromString('word/media/' . basename($mediaPath), $bytes);
            }

            $out->close();

            return file_get_contents($tmp) ?: null;
        } finally {
            @unlink($tmp);
        }
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
