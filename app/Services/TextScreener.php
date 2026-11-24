<?php

namespace App\Services;

/**
 * Screens user-written text on shared records before it is saved (Pane#175).
 * Cheap checks only: a blocklist (matched through spacing and look-alike
 * characters), a limit on repeated characters, and links. A stronger checker,
 * such as a moderation API, can replace this class behind screen().
 */
class TextScreener
{
    public const string REASON_TERM = 'blocked_term';

    public const string REASON_REPEAT = 'repeated_characters';

    public const string REASON_LINK = 'link';

    /** Default terms; an install adds its own through PANE_SCREENING_BLOCKLIST. */
    private const array DEFAULT_BLOCKLIST = [
        'fuck', 'shit', 'cunt', 'bitch', 'bastard', 'asshole', 'nigger', 'faggot', 'retard', 'whore', 'slut',
        'kill yourself', 'kys', 'child porn',
    ];

    private const array LOOKALIKES = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i', '|' => 'i', '+' => 't',
    ];

    /**
     * @return string|null the reason the text is refused, or null when it is fine. Never the blocked term itself.
     */
    public function screen(string $text): ?string
    {
        if ($this->hasLink($text)) {
            return self::REASON_LINK;
        }
        $max = (int) config('pane.screening.max_repeated_characters', 6);
        if ($max > 0 && preg_match('/(.)\1{'.$max.',}/su', $text) === 1) {
            return self::REASON_REPEAT;
        }
        if ($this->hasBlockedTerm($text)) {
            return self::REASON_TERM;
        }

        return null;
    }

    private function hasLink(string $text): bool
    {
        $mode = (string) config('pane.screening.links', 'block');
        if ($mode === 'off') {
            return false;
        }
        $pattern = '~(?:https?://|ftp://|www\.)\S+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|co|uk|me|ly|xyz|info|biz|ru|cn|tk|app|dev|link|shop)\b\S*~iu';
        if (preg_match_all($pattern, $text, $matches) === 0) {
            return false;
        }
        if ($mode !== 'allow_list') {
            return true;
        }
        $allowed = array_map('strtolower', (array) config('pane.screening.allowed_link_hosts', []));
        foreach ($matches[0] as $link) {
            $host = strtolower((string) parse_url(str_contains($link, '://') ? $link : 'http://'.$link, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            if (! in_array($host, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasBlockedTerm(string $text): bool
    {
        $words = $this->words($text);
        $haystack = ' '.implode(' ', $words).' ';
        // "f u c k": runs of single characters are also read as one word
        $joined = ' '.implode(' ', $this->joinSingles($words)).' ';
        // "fffuuuccckkk": runs of one character count once
        $collapsed = ' '.$this->collapse(implode(' ', $words)).' ';

        foreach ($this->terms() as $term) {
            $needle = ' '.$term.' ';
            if (str_contains($haystack, $needle)
                || str_contains($joined, $needle)
                || str_contains($collapsed, ' '.$this->collapse($term).' ')) {
                return true;
            }
        }

        return false;
    }

    private function collapse(string $text): string
    {
        return preg_replace('/(.)\1+/u', '$1', $text) ?? $text;
    }

    /**
     * @return list<string>
     */
    private function terms(): array
    {
        $terms = (array) config('pane.screening.blocklist', []);
        if ((bool) config('pane.screening.use_default_blocklist', true)) {
            $terms = array_merge(self::DEFAULT_BLOCKLIST, $terms);
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($term) => implode(' ', $this->words((string) $term)),
            $terms
        ))));
    }

    /**
     * @return list<string> lowercase words, accents removed, look-alike characters mapped to letters
     */
    private function words(string $text): array
    {
        $text = mb_strtolower($text);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = $ascii !== false && $ascii !== '' ? $ascii : $text;
        $text = strtr($text, self::LOOKALIKES);
        $text = preg_replace('/[^a-z\s]+/u', ' ', $text) ?? '';

        return array_values(array_filter(preg_split('/\s+/', trim($text)) ?: []));
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function joinSingles(array $words): array
    {
        $out = [];
        $run = '';
        foreach ($words as $word) {
            if (strlen($word) === 1) {
                $run .= $word;

                continue;
            }
            if ($run !== '') {
                $out[] = $run;
                $run = '';
            }
            $out[] = $word;
        }
        if ($run !== '') {
            $out[] = $run;
        }

        return $out;
    }
}
