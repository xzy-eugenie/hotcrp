<?php
// mimetype.php -- HotCRP helper file for MIME types
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Mimetype {
    const TXT_TYPE = "text/plain";
    const BIN_TYPE = "application/octet-stream";
    const PDF_TYPE = "application/pdf";
    const PS_TYPE = "application/postscript";
    const PPT_TYPE = "application/vnd.ms-powerpoint";
    const JSON_TYPE = "application/json";
    const JPG_TYPE = "image/jpeg";
    const PNG_TYPE = "image/png";
    const GIF_TYPE = "image/gif";
    const TAR_TYPE = "application/x-tar";
    const ZIP_TYPE = "application/zip";
    const RAR_TYPE = "application/x-rar-compressed";

    const FLAG_INLINE = 1;
    const FLAG_UTF8 = 2;
    const FLAG_COMPRESSIBLE = 4;
    const FLAG_INCOMPRESSIBLE = 8;
    const FLAG_TEXTUAL = 16;

    /** @var string */
    public $mimetype;
    /** @var string */
    public $extension;
    /** @var ?string */
    public $description;
    /** @var int */
    public $flags;

    private static $tmap = [];

    /** @var array<string,array{0:string,1:?string,2:int,3?:string,4?:string,5?:string}> */
    private static $tinfo = [
        self::TXT_TYPE =>     [".txt", "text", self::FLAG_INLINE | self::FLAG_COMPRESSIBLE | self::FLAG_TEXTUAL],
        self::PDF_TYPE =>     [".pdf", "PDF", self::FLAG_INLINE],
        self::PS_TYPE =>      [".ps", "PostScript", self::FLAG_COMPRESSIBLE],
        self::PPT_TYPE =>     [".ppt", "PowerPoint", self::FLAG_INCOMPRESSIBLE, "application/mspowerpoint", "application/powerpoint", "application/x-mspowerpoint"],
        "application/vnd.openxmlformats-officedocument.presentationml.presentation" =>
                              [".pptx", "PowerPoint", self::FLAG_INCOMPRESSIBLE],
        "video/mp4" =>        [".mp4", null, self::FLAG_INCOMPRESSIBLE],
        "video/x-msvideo" =>  [".avi", null, self::FLAG_INCOMPRESSIBLE],
        self::JSON_TYPE =>    [".json", "JSON", self::FLAG_UTF8 | self::FLAG_COMPRESSIBLE | self::FLAG_TEXTUAL],
        self::JPG_TYPE =>     [".jpg", "JPEG", self::FLAG_INLINE, ".jpeg"],
        self::PNG_TYPE =>     [".png", "PNG", self::FLAG_INLINE],
        self::GIF_TYPE =>     [".gif", "GIF", self::FLAG_INLINE]
    ];

    private static $mime_types = null;
    private static $finfo = null;

    /** @param string $mimetype
     * @param string $extension
     * @param ?string $description
     * @param int $flags */
    function __construct($mimetype, $extension,
                         $description = null, $flags = 0) {
        $this->mimetype = $mimetype;
        $this->extension = $extension;
        $this->description = $description;
        $this->flags = $flags;
    }

    /** @param string|Mimetype $type
     * @return ?Mimetype */
    static function lookup($type) {
        if (!$type) {
            return null;
        }
        if (is_object($type)) {
            return $type;
        }
        if (empty(self::$tmap)) {
            foreach (self::$tinfo as $xtype => $data) {
                $m = new Mimetype($xtype, $data[0], $data[1], $data[2]);
                self::$tmap[$xtype] = self::$tmap[$m->extension] = $m;
                for ($i = 3; $i < count($data); ++$i) {
                    self::$tmap[$data[$i]] = $m;
                }
            }
        }
        if (array_key_exists($type, self::$tmap)) {
            return self::$tmap[$type];
        }
        if (self::$mime_types === null) {
            self::$mime_types = true;
            $t = (string) @file_get_contents(SiteLoader::find("lib/mime.types"));
            preg_match_all('/^(|#!!\s+)([-a-z0-9]+\/\S+)[ \t]*(.*)/m', $t, $ms, PREG_SET_ORDER);
            foreach ($ms as $mm) {
                if (isset(self::$tmap[$mm[2]])) {
                    continue;
                }
                if ($mm[1] === "") {
                    $exts = [""];
                    if ($mm[3]) {
                        $exts = array_map(function ($x) { return ".$x"; },
                                          preg_split('/\s+/', $mm[3]));
                    }
                    $m = new Mimetype($mm[2], $exts[0]);
                    self::$tmap[$m->mimetype] = $m;
                    foreach ($exts as $ext) {
                        if ($ext && !isset(self::$tmap[$ext]))
                            self::$tmap[$ext] = $m;
                    }
                } else {
                    $m = self::$tmap[trim($mm[3]) ? : self::BIN_TYPE] ?? null;
                    if ($m) {
                        self::$tmap[$mm[2]] = $m;
                    }
                }
            }
        }
        return self::$tmap[$type] ?? null;
    }

    /** @param string $type
     * @return Mimetype */
    static function checked_lookup($type) {
        if (($m = self::lookup($type))) {
            return $m;
        } else {
            throw new Exception("Unknown mimetype “{$type}”");
        }
    }

    /** @param string|Mimetype $type
     * @return string */
    static function type($type) {
        if ($type instanceof Mimetype) {
            return $type->mimetype;
        } else if (isset(self::$tinfo[$type])) {
            return $type;
        } else if (($x = self::lookup($type))) {
            return $x->mimetype;
        } else {
            return $type;
        }
    }

    /** @param string|Mimetype $type
     * @return string */
    static function type_with_charset($type) {
        if (($x = self::lookup($type))) {
            if (($x->flags & self::FLAG_UTF8) !== 0) {
                return $x->mimetype . "; charset=utf-8";
            } else {
                return $x->mimetype;
            }
        } else {
            return "";
        }
    }

    /** @param string|Mimetype $typea
     * @param string|Mimetype $typeb
     * @return bool */
    static function type_equals($typea, $typeb) {
        $ta = self::type($typea);
        $tb = self::type($typeb);
        return ($typea && $typea === $typeb)
            || ($ta !== null && $ta === $tb);
    }

    /** @param string|Mimetype $type
     * @return string */
    static function extension($type) {
        $x = self::lookup($type);
        return $x ? $x->extension : "";
    }

    /** @param string|Mimetype $type
     * @return string */
    static function description($type) {
        if (($x = self::lookup($type))) {
            if ($x->description) {
                return $x->description;
            } else if ($x->extension !== "") {
                return $x->extension;
            } else {
                return $x->mimetype;
            }
        } else {
            return $type;
        }
    }

    /** @param list<string|Mimetype> $types
     * @return string */
    static function list_description($types) {
        if (count($types) === 0) {
            return "any file";
        } else if (count($types) === 1) {
            return Mimetype::description($types[0]);
        } else {
            $m = array_unique(array_map("Mimetype::description", $types));
            return commajoin($m, "or");
        }
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function disposition_inline($type) {
        $x = self::lookup($type);
        return $x && ($x->flags & self::FLAG_INLINE) !== 0;
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function textual($type) {
        $x = self::lookup($type);
        if ($x && $x->flags !== 0) {
            return ($x->flags & self::FLAG_TEXTUAL) !== 0;
        } else {
            return str_starts_with($x ? $x->mimetype : $type, "text/");
        }
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function compressible($type) {
        $x = self::lookup($type);
        if ($x && $x->flags !== 0) {
            return ($x->flags & self::FLAG_COMPRESSIBLE) !== 0;
        } else {
            return str_starts_with($x ? $x->mimetype : $type, "text/");
        }
    }


    /** @return list<Mimetype> */
    static function builtins() {
        return array_map(function ($t) { return Mimetype::lookup($t); },
                         array_keys(self::$tinfo));
    }


    /** @param ?string $content
     * @return bool */
    static function pdf_content($content) {
        return $content && strncmp("%PDF-", $content, 5) == 0;
    }

    /** @param ?string $content
     * @param ?string $type
     * @return string */
    static function content_type($content, $type = null) {
        // null content must use type
        if ($content === null || $content === "") {
            return self::type($type ? : self::BIN_TYPE);
        }
        // reliable sniffs
        if (strlen($content) < 16) {
            // do not sniff
        } else if (substr($content, 0, 5) === "%PDF-") {
            return self::PDF_TYPE;
        } else if (strlen($content) > 516 && substr($content, 512, 4) === "\x00\x6E\x1E\xF0") {
            return self::PPT_TYPE;
        } else if (substr($content, 0, 4) === "\xFF\xD8\xFF\xD8"
                   || (substr($content, 0, 4) === "\xFF\xD8\xFF\xE0"
                       && substr($content, 6, 6) === "JFIF\x00\x01")
                   || (substr($content, 0, 4) === "\xFF\xD8\xFF\xE1"
                       && substr($content, 6, 6) === "Exif\x00\x00")) {
            return self::JPG_TYPE;
        } else if (substr($content, 0, 8) === "\x89PNG\r\n\x1A\x0A") {
            return self::PNG_TYPE;
        } else if ((substr($content, 0, 6) === "GIF87a"
                    || substr($content, 0, 6) === "GIF89a")
                   && str_ends_with($content, "\x00;")) {
            return self::GIF_TYPE;
        } else if (substr($content, 0, 7) === "Rar!\x1A\x07\x00"
                   || substr($content, 0, 8) === "Rar!\x1A\x07\x01\x00") {
            return self::RAR_TYPE;
        }
        // eliminate invalid types, canonicalize
        if ($type
            && !isset(self::$tinfo[$type])
            && ($tx = self::type($type))) {
            $type = $tx;
        }
        // unreliable sniffs
        if (!$type || $type === self::BIN_TYPE) {
            if (substr($content, 0, 5) === "%!PS-") {
                return self::PS_TYPE;
            } else if (substr($content, 0, 8) === "ustar\x0000"
                       || substr($content, 0, 8) === "ustar  \x00") {
                return self::TAR_TYPE;
            }
            self::$finfo = self::$finfo ?? new finfo(FILEINFO_MIME_TYPE);
            $type = self::$finfo->buffer(substr($content, 0, 2048));
        }
        // type obtained, or octet-stream if nothing else works
        return self::type($type ? : self::BIN_TYPE);
    }

    /** @param ?string $content
     * @param ?string $type
     * @return array{type:string,width?:int,height?:int} */
    static function content_info($content, $type = null) {
        $content = $content ?? "";
        $type = self::content_type($content, $type);
        if ($type === self::JPG_TYPE) {
            return self::jpeg_content_info($content);
        } else if ($type === self::PNG_TYPE) {
            return self::png_content_info($content);
        } else if ($type === self::GIF_TYPE) {
            return self::gif_content_info($content);
        } else {
            return ["type" => $type];
        }
    }

    /** @param string $s
     * @return array{type:string,width?:int,height?:int} */
    static private function jpeg_content_info($s) {
        $info = ["type" => self::JPG_TYPE];
        $pos = 0;
        $len = strlen($s);
        while ($pos + 2 <= $len && ord($s[$pos]) === 0xFF) {
            $ch = ord($s[$pos + 1]);
            if ($ch === 0xFF) {
                ++$pos;
                continue;
            } else if (($ch >= 0xD0 && $ch <= 0xD8) || $ch === 0x01) {
                $pos += 2;
                continue;
            } else if ($ch === 0xD9 || $pos + 4 > $len) {
                break;
            }
            $blen = (ord($s[$pos + 2]) << 8) + ord($s[$pos + 3]);
            if (($ch >= 0xC0 && $ch <= 0xCF) && $ch !== 0xC8) {
                // SOF
                if ($blen < 8 || $pos + 6 > $len) {
                    break;
                }
                $x = $pos + 8 <= $len ? (ord($s[$pos + 7]) << 8) + ord($s[$pos + 8]) : 0;
                $y = (ord($s[$pos + 5]) << 8) + ord($s[$pos + 6]);
                if ($x !== 0) {
                    $info["width"] = $x;
                }
                if ($y !== 0) {
                    $info["height"] = $y;
                }
                if ($x === 0 || $y !== 0) {
                    break;
                }
            } else if ($ch === 0xDC) {
                // DNL
                if ($blen !== 4 || $pos + 5 > $len) {
                    break;
                }
                $y = (ord($s[$pos + 4]) << 8) + ord($s[$pos + 5]);
                if ($y !== 0) {
                    $info["height"] = $y;
                }
                break;
            }
            $pos += 2 + $blen;
        }
        return $info;
    }

    /** @param string $s
     * @return array{type:string,width?:int,height?:int} */
    static private function gif_content_info($s) {
        $info = ["type" => self::GIF_TYPE];
        $pos = 6;
        $len = strlen($s);
        if ($pos + 3 > $len) {
            return $info;
        }
        $lw = ord($s[$pos]) + (ord($s[$pos + 1]) << 8);
        $lh = ord($s[$pos + 2]) + (ord($s[$pos + 3]) << 8);
        if ($lw !== 0) {
            $info["width"] = $lw;
        }
        if ($lh !== 0) {
            $info["height"] = $lh;
        }
        if (($lw !== 0 && $lh !== 0) || $pos + 6 > $len) {
            return $info;
        }
        $flags = ord($s[$pos + 4]);
        $pos += 6;
        if (($flags & 0x80) !== 0) {
            $pos += 3 * (1 << (($flags & 7) + 1));
        }
        while ($pos + 8 <= $len) {
            $ch = ord($s[$pos]);
            if ($ch === 0x21) {
                // extension
                $pos += 2;
                $blen = 1;
                while ($pos < $len && $blen !== 0) {
                    $blen = ord($s[$pos]);
                    $pos += $blen + 1;
                }
            } else if ($ch === 0x2C) {
                // image
                $left = ord($s[$pos + 1]) + (ord($s[$pos + 2]) << 8);
                $top = ord($s[$pos + 3]) + (ord($s[$pos + 4]) << 8);
                $w = ord($s[$pos + 5]) + (ord($s[$pos + 6]) << 8);
                $h = ord($s[$pos + 7]) + (ord($s[$pos + 8]) << 8);
                if ($w !== 0 && $left + $w > ($info["width"] ?? 0)) {
                    $info["width"] = $left + $w;
                }
                if ($h !== 0 && $top + $h > ($info["height"] ?? 0)) {
                    $info["height"] = $top + $h;
                }
                break;
            } else {
                // trailer/unknown
                break;
            }
        }
        return $info;
    }

    /** @param string $s
     * @param int $off
     * @return int */
    static private function net4at($s, $off) {
        return (ord($s[$off]) << 24) + (ord($s[$off + 1]) << 16)
            + (ord($s[$off + 2]) << 8) + ord($s[$off + 3]);
    }

    /** @param string $s
     * @return array{type:string,width?:int,height?:int} */
    static private function png_content_info($s) {
        $info = ["type" => self::PNG_TYPE];
        $pos = 8;
        $len = strlen($s);
        while ($pos + 8 <= $len) {
            $blen = self::net4at($s, $pos);
            $chunk = self::net4at($s, $pos + 4);
            if ($chunk === 0x49484452 /* IHDR */) {
                $w = $pos + 11 <= $len ? self::net4at($s, $pos + 8) : 0;
                $h = $pos + 15 <= $len ? self::net4at($s, $pos + 12) : 0;
                if ($w !== 0) {
                    $info["width"] = $w;
                }
                if ($h !== 0) {
                    $info["height"] = $h;
                }
                break;
            }
            $min = min($chunk >> 24, ($chunk >> 16) & 0xFF, ($chunk >> 8) & 0xFF, $chunk & 0xFF);
            $max = max($chunk >> 24, ($chunk >> 16) & 0xFF, ($chunk >> 8) & 0xFF, $chunk & 0xFF);
            if (($min | 0x20) < 0x61 || ($max | 0x20) > 0x7A) {
                break;
            }
            $pos += 12 + $blen;
        }
        return $info;
    }
}
