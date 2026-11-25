<?php
/*
 * Sentinelle XDR — actualités Free (flux RSS) et repérage des mises à jour annoncées.
 *
 * Free ne publie pas de calendrier officiel de ses mises à jour : la box et le Player se mettent
 * à jour seuls. Sentinelle lit les sites spécialisés, repère les numéros de version annoncés
 * (ex. 4.13.8) et les compare à la version installée sur votre Freebox.
 */
declare(strict_types=1);

final class News
{
    public static function get(bool $refresh = false): array
    {
        $file = data_path('cache/news.json');
        $cache = read_json($file);
        $age = time() - (int)($cache['ts'] ?? 0);
        if (!empty($cache['items']) && ($age < 1800 || ($refresh && $age < 60))) return $cache;
        $items = [];
        $sources = [];
        foreach ((array)Settings::get('news_sources') as $src) {
            $name = (string)($src['name'] ?? 'Source');
            try {
                $list = self::fetch((string)($src['url'] ?? ''));
                foreach ($list as &$it) $it['source'] = $name;
                unset($it);
                $items = array_merge($items, $list);
                $sources[] = ['name' => $name, 'ok' => true, 'count' => count($list)];
            } catch (Throwable $e) {
                $sources[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
                foreach ((array)($cache['items'] ?? []) as $old) {
                    if (($old['source'] ?? '') === $name) $items[] = $old;
                }
            }
        }
        $seen = [];
        $items = array_values(array_filter($items, function ($i) use (&$seen) {
            $k = strtolower($i['link']);
            if (isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        }));
        usort($items, fn($a, $b) => $b['date'] <=> $a['date']);
        $out = ['ts' => time(), 'items' => array_slice($items, 0, 90), 'sources' => $sources];
        if ($items) write_json($file, $out);
        return $out;
    }

    private static function fetch(string $url): array
    {
        $trusted = in_array($url, array_column(Settings::DEFAULTS['news_sources'], 'url'), true);
        for ($hop = 0; $hop < 4; $hop++) {
            if (!str_starts_with(strtolower($url), 'https://')) throw new RuntimeException('seules les adresses https:// sont acceptées');
            if (!$trusted && !Http::isPublicUrl($url)) throw new RuntimeException('adresse refusée (réseau interne ou introuvable)');
            $r = Http::request('GET', $url, null, ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'],
                ['timeout' => 10, 'max_bytes' => 3_000_000]);
            if ($r['error'] !== '') throw new RuntimeException($r['error']);
            if (in_array($r['status'], [301, 302, 303, 307, 308], true) && $r['location'] !== '') {
                $url = self::resolve($url, $r['location']);
                $trusted = false;
                continue;
            }
            if ($r['status'] !== 200) throw new RuntimeException("le site a répondu HTTP {$r['status']}");
            return self::parse($r['body']);
        }
        throw new RuntimeException('trop de redirections');
    }

    private static function resolve(string $base, string $loc): string
    {
        if (preg_match('~^https?://~i', $loc)) return $loc;
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        return str_starts_with($loc, '/') ? $root . $loc : $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $loc;
    }

    public static function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($x === false) throw new RuntimeException('flux illisible');
        $entries = isset($x->channel->item) ? $x->channel->item : (isset($x->entry) ? $x->entry : $x->item);
        $out = [];
        foreach ($entries ?? [] as $it) {
            $title = clean_text((string)$it->title, 220);
            $link = trim((string)$it->link);
            if ($link === '') {
                foreach ($it->link as $l) {
                    $rel = (string)$l['rel'];
                    if ($rel === '' || $rel === 'alternate') {
                        $link = trim((string)$l['href']);
                        break;
                    }
                }
            }
            if ($title === '' || !preg_match('~^https?://[^\s"<>]+$~i', $link)) continue;
            $dateStr = (string)($it->pubDate ?? '') ?: (string)($it->updated ?? '') ?: (string)($it->published ?? '');
            if ($dateStr === '') $dateStr = (string)$it->children('http://purl.org/dc/elements/1.1/')->date;
            $date = strtotime($dateStr) ?: time();
            $cats = [];
            foreach ($it->category as $c) {
                $v = clean_text((string)$c !== '' ? (string)$c : (string)$c['term'], 40);
                if ($v !== '') $cats[] = $v;
            }
            $desc = (string)($it->description ?? '') ?: (string)($it->summary ?? '');
            $content = (string)$it->children('http://purl.org/rss/1.0/modules/content/')->encoded;
            $excerpt = clean_text($desc !== '' ? $desc : $content, 330);
            $excerpt = preg_replace('/\s*(L[’\']article|The post)\s.*$/u', '', $excerpt) ?? $excerpt;
            $all = $title . ' ' . implode(' ', $cats) . ' ' . $excerpt;
            $out[] = [
                'title' => $title, 'link' => $link, 'date' => $date, 'categories' => array_slice($cats, 0, 4), 'excerpt' => $excerpt,
                'image' => self::image($it, $desc . ' ' . $content), 'tags' => self::tags($all), 'versions' => self::versions($title . ' ' . $excerpt),
            ];
            if (count($out) >= 40) break;
        }
        return $out;
    }

    private static function image(SimpleXMLElement $it, string $html): ?string
    {
        $cands = [];
        $media = $it->children('http://search.yahoo.com/mrss/');
        if (isset($media->content)) $cands[] = (string)$media->content->attributes()['url'];
        if (isset($media->thumbnail)) $cands[] = (string)$media->thumbnail->attributes()['url'];
        if (isset($it->enclosure) && str_starts_with((string)$it->enclosure['type'], 'image')) $cands[] = (string)$it->enclosure['url'];
        if (preg_match('~<img[^>]+src=["\'](https://[^"\'\s>]+)["\']~i', $html, $m)) $cands[] = html_entity_decode($m[1]);
        foreach ($cands as $c) {
            if (preg_match('~^https://[^\s"<>]+$~i', $c)) return $c;
        }
        return null;
    }

    /** Titre consacré à un concurrent sans parler de Free. */
    private static function rival(string $title): bool
    {
        $t = mb_strtolower($title);
        return (bool)preg_match('/livebox|\bbbox\b|\bsfr\b|\borange\b|bouygues|\bsosh\b|b&you/u', $t) && !preg_match('/\bfree\b|freebox/u', $t);
    }

    private static function tags(string $t): array
    {
        $t = mb_strtolower($t);
        $tags = [];
        if (preg_match('/mises? à jour|nouvelle version|firmware|freebox os|correctif|\b\d\.\d{1,2}\.\d{1,2}\b/u', $t)) $tags[] = 'maj';
        if (preg_match('/player|décodeur|oqee|télécommande/u', $t)) $tags[] = 'player';
        if (preg_match('/freebox|fibre|ultra|delta|\bpop\b|révolution|répéteur/u', $t)) $tags[] = 'freebox';
        if (preg_match('/free mobile|forfait|\b5g\b|\b4g\b|série free/u', $t)) $tags[] = 'mobile';
        if (preg_match('/piratage|pirat|fuite|faille|cyber|phishing|hameçonnage|arnaque|escroquer|sécurité|malveillan|virus|rançon/u', $t)) $tags[] = 'securite';
        if (preg_match('/chaîne|\btv\b|canal\+|netflix|disney|prime video|replay|streaming/u', $t)) $tags[] = 'tv';
        return $tags;
    }

    private static function versions(string $t): array
    {
        preg_match_all('/\b([4-9]\.\d{1,2}\.\d{1,2})\b/', $t, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /** Mises à jour : version installée comparée aux versions annoncées dans l'actualité. */
    public static function updates(string $current, string $model = ''): array
    {
        $news = self::get();
        // un article sur une box concurrente (Livebox, Bbox…) ne doit ni s'afficher ici, ni fournir un « dernier numéro de version »
        $mine = array_values(array_filter($news['items'] ?? [], fn($i) => !self::rival((string)$i['title'])));
        $maj = array_values(array_filter($mine, fn($i) => in_array('maj', $i['tags'], true)));
        $latest = '';
        $latestItem = null;
        foreach ($maj as $i) {
            foreach ($i['versions'] as $v) {
                if ($latest === '' || version_compare($v, $latest, '>')) {
                    $latest = $v;
                    $latestItem = $i;
                }
            }
        }
        $cur = preg_replace('/[^0-9.]/', '', $current) ?? '';
        $slim = fn($i) => ['title' => $i['title'], 'link' => $i['link'], 'date' => $i['date'], 'source' => $i['source'] ?? '', 'versions' => $i['versions']];
        return [
            'current' => $current,
            'latest_seen' => $latest,
            'latest_item' => $latestItem ? $slim($latestItem) : null,
            'behind' => $cur !== '' && $latest !== '' && version_compare($cur, $latest, '<'),
            'ahead' => $cur !== '' && $latest !== '' && version_compare($cur, $latest, '>='),
            'box' => array_map($slim, array_slice(array_values(array_filter($maj, fn($i) => !in_array('player', $i['tags'], true) || in_array('freebox', $i['tags'], true))), 0, 8)),
            'player' => array_map($slim, array_slice(array_values(array_filter($mine, fn($i) => in_array('player', $i['tags'], true))), 0, 8)),
            'checked' => (int)($news['ts'] ?? 0),
        ];
    }
}
