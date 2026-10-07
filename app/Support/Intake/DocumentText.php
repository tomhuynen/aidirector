<?php

declare(strict_types=1);

namespace App\Support\Intake;

use App\Models\Upload;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The text of a document the director shares in the intake chat, such as a
 * functional design, so the assistant can read it. PDFs go through
 * pdftotext; text files are read as they are.
 */
class DocumentText
{
    /** Enough for a long design document without flooding the conversation. */
    public const MAX_CHARACTERS = 60000;

    /** How a shared document opens in the intake conversation, so it can be found there again. */
    public const SHARED = 'The director shared the document';

    public function read(Upload $upload): string
    {
        $bytes = (string) Storage::disk($upload->disk->value)->get((string) $upload->path);

        $text = match ($upload->mime_type) {
            'application/pdf' => $this->pdf($bytes),
            'text/rtf', 'application/rtf' => $this->rtf($bytes),
            default => $bytes,
        };
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text)));

        if ($text === '') {
            throw new RuntimeException('The document has no readable text.');
        }

        return Str::limit($text, self::MAX_CHARACTERS, "\n[…]");
    }

    /**
     * The plain text of an RTF document: control words and formatting are
     * dropped, paragraphs, tabs and escaped characters kept, and groups such
     * as the font table, styles, metadata and pictures skipped entirely.
     */
    public function rtf(string $rtf): string
    {
        $skipped = ['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'footer', 'listtable', 'listoverridetable', 'themedata', 'datastore', 'latentstyles', 'xmlnstbl', 'rsidtbl', 'generator', 'object', 'fldinst'];
        $text = '';
        $depth = 0;
        $skipDepth = null;
        $fallbackCharacters = 1;
        $length = strlen($rtf);

        for ($i = 0; $i < $length; $i++) {
            $char = $rtf[$i];

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char === '}') {
                if ($skipDepth !== null && $depth <= $skipDepth) {
                    $skipDepth = null;
                }

                $depth--;

                continue;
            }

            if ($char === '\\') {
                $next = $rtf[$i + 1] ?? '';

                // A backslash at the end of a line is a paragraph break, as TextEdit writes them.
                if ($next === "\n" || $next === "\r") {
                    if ($skipDepth === null) {
                        $text .= "\n";
                    }
                    $i++;

                    continue;
                }

                if ($next === '\\' || $next === '{' || $next === '}') {
                    if ($skipDepth === null) {
                        $text .= $next;
                    }
                    $i++;

                    continue;
                }

                if ($next === '*') {
                    $skipDepth ??= $depth;
                    $i++;

                    continue;
                }

                if ($next === "'") {
                    if ($skipDepth === null) {
                        $text .= mb_convert_encoding(chr((int) hexdec(substr($rtf, $i + 2, 2))), 'UTF-8', 'Windows-1252');
                    }
                    $i += 3;

                    continue;
                }

                if (preg_match('/\G\\\\([a-z]+)(-?\d+)? ?/i', $rtf, $match, 0, $i) === 1) {
                    $word = strtolower($match[1]);
                    $i += strlen($match[0]) - 1;

                    if ($word === 'uc') {
                        $fallbackCharacters = max(0, (int) ($match[2] ?? 1));
                    } elseif (in_array($word, $skipped, true)) {
                        $skipDepth ??= $depth;
                    } elseif ($skipDepth === null) {
                        $text .= match ($word) {
                            'par', 'line', 'sect', 'page' => "\n",
                            'tab' => "\t",
                            'u' => mb_chr(((int) ($match[2] ?? 0) + 65536) % 65536, 'UTF-8'),
                            default => '',
                        };

                        // A \uN is followed by \ucN fallback characters for old readers, one by default.
                        if ($word === 'u') {
                            for ($skip = 0; $skip < $fallbackCharacters; $skip++) {
                                $i += substr($rtf, $i + 1, 2) === "\\'" ? 4 : 1;
                            }
                        }
                    }

                    continue;
                }

                $i++;

                continue;
            }

            if ($skipDepth === null && $char !== "\r" && $char !== "\n") {
                $text .= $char;
            }
        }

        return $text;
    }

    private function pdf(string $bytes): string
    {
        $path = sys_get_temp_dir() . '/document-' . Str::uuid()->toString() . '.pdf';
        file_put_contents($path, $bytes);

        try {
            $result = Process::timeout(60)->run([(string) Config::get('uploads.pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-']);

            if ($result->failed()) {
                throw new RuntimeException('The PDF could not be read.');
            }

            return $result->output();
        } finally {
            @unlink($path);
        }
    }
}
