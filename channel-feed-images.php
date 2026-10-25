<?php
declare(strict_types=1);

/** Explicit image origins shared by RSS validation and the page's CSP. */
function aov_feed_image_hosts(): array {
    return [
        'npr.brightspotcdn.com', 'd3i6fh83elv35t.cloudfront.net', 's.abcnews.com',
        'assets1.cbsnewsstatic.com', 'assets2.cbsnewsstatic.com',
        'assets3.cbsnewsstatic.com', 'assets4.cbsnewsstatic.com',
        'i.guim.co.uk', 'lemmy.world', 'sportshub.cbsistatic.com',
        'www.goodnewsnetwork.org', 'www.positive.news', 'www.optimistdaily.com',
        'media.cntraveler.com', 'ichef.bbci.co.uk', 'img.lemde.fr',
        'cdn.arstechnica.net', 'static0.howtogeekimages.com', 'static0.makeuseofimages.com',
        'hackaday.com', 'www.raspberrypi.com',
        'th-thumbnailer.cdn-si-edu.com', 'daily.jstor.org', 'blogs.loc.gov',
        'lcm.loc.gov', 'www.nasa.gov', 'assets.science.nasa.gov',
        'images-assets.nasa.gov', 'science.nasa.gov',
        'variety.com', 'www.hollywoodreporter.com', 'www.indiewire.com',
        'www.billboard.com', 'media.pitchfork.com',
        'assets.bonappetit.com', 'images.immediate.co.uk', 'www.akc.org',
        'bookriot.com', 's26162.pcdn.co',
        'assets.vogue.com', 'media.allure.com', 'cdn.tinybuddha.com',
        'www.theminimalists.com', 'media.self.com',
        'www.parentmap.com', 'img.itch.zone', '9to5toys.com', 'media.stores.com',
        'cdnph.upi.com', 'e3.365dm.com',
        'globalnews.ca', 'i.cbc.ca',
        'www.whitehouse.gov',
    ];
}

/** Extract one publisher-provided thumbnail without proxying article content. */
function aov_feed_image(SimpleXMLElement $entry): ?string {
    $image = aov_feed_image_url((string) $entry->imageurl);
    if ($image !== null) return $image;
    foreach ($entry->enclosure as $enclosure) {
        $attrs = $enclosure->attributes();
        if (!str_starts_with(strtolower((string) $attrs->type), 'image/')) continue;
        $image = aov_feed_image_url((string) $attrs->url);
        if ($image !== null) return $image;
    }
    $media = $entry->children('http://search.yahoo.com/mrss/');
    foreach (['thumbnail', 'content'] as $tag) {
        foreach ($media->{$tag} as $candidate) {
            $attrs = $candidate->attributes();
            $medium = strtolower((string) $attrs->medium);
            $type = strtolower((string) $attrs->type);
            if (($medium !== '' && $medium !== 'image') || ($type !== '' && !str_starts_with($type, 'image/'))) continue;
            $image = aov_feed_image_url((string) $attrs->url);
            if ($image !== null) return $image;
        }
    }
    $image = aov_feed_image_url((string) $entry->image);
    if ($image !== null) return $image;
    $encoded = (string) $entry->children('http://purl.org/rss/1.0/modules/content/')->encoded;
    foreach ([$encoded, (string) $entry->description] as $html) {
        if (preg_match('~<img\b[^>]*\bsrc\s*=\s*[\x22\x27]([^\x22\x27]+)~i', $html, $match)) {
            $image = aov_feed_image_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($image !== null) return $image;
        }
    }
    return null;
}

function aov_feed_image_url(string $value): ?string {
    $url = trim($value);
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') return null;
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($host, aov_feed_image_hosts(), true) || str_contains(strtolower($url), 'tracking')) return null;
    return $url;
}
