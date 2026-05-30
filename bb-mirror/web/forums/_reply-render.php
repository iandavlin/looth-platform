<?php
/**
 * Shared reply rendering — used by the feed teaser (_feed.php) and the lazy
 * full-thread endpoint (_topic-replies.php) so both emit identical .reply-stub
 * markup (same CSS + inline "… more" JS apply everywhere).
 *
 * Functions are function_exists-guarded so _feed.php (which also defines
 * bb_mirror_avatar/feed_rel_time historically) can require this safely.
 */

declare(strict_types=1);

if (!function_exists('bb_mirror_avatar')) {
    /** CSS initials avatar circle. $slug picks a stable palette colour. */
    function bb_mirror_avatar(string $display_name, string $slug, int $size = 32): string
    {
        $palette = ['#6b7c52','#c66845','#87986a','#4a5a36','#7a5a14','#2e3a23','#5a6a5a','#a0714f'];
        $idx = abs(crc32($slug)) % count($palette);
        $bg  = $palette[$idx];
        $initial = mb_strtoupper(mb_substr($display_name, 0, 1));
        $fs  = round($size * 0.42);
        return sprintf(
            '<span class="avatar-init" style="width:%dpx;height:%dpx;font-size:%dpx;background:%s" aria-hidden="true">%s</span>',
            $size, $size, $fs, htmlspecialchars($bg), htmlspecialchars($initial)
        );
    }
}

if (!function_exists('feed_rel_time')) {
    function feed_rel_time(string $ts): string
    {
        $unix = strtotime($ts . (str_contains($ts, '+') ? '' : ' UTC'));
        if (!$unix) return '—';
        $diff = time() - $unix;
        if ($diff <     60) return $diff . 's';
        if ($diff <   3600) return round($diff / 60)   . 'm';
        if ($diff <  86400) return round($diff / 3600)  . 'h';
        if ($diff < 604800) return round($diff / 86400) . 'd';
        return date('M j, Y', $unix);
    }
}

if (!function_exists('bb_mirror_render_reply_stub')) {
    /**
     * Render one .reply-stub row.
     *
     * @param array $r        keys: author_name, author_slug, excerpt, created_at, reply_image_url
     * @param bool  $is_child indented child styling
     * @param bool  $overflow hidden-until-expanded (only used inside the lazy thread)
     */
    function bb_mirror_render_reply_stub(array $r, bool $is_child = false, bool $overflow = false, bool $collapse_image = false): void
    {
        $ra          = htmlspecialchars($r['author_name'] ?: 'Anonymous');
        $rslug       = $r['author_slug'] ?? null;
        $raw_text    = trim(strip_tags($r['excerpt'] ?? ''));
        $reply_short = mb_substr($raw_text, 0, 160);
        $reply_rest  = mb_strlen($raw_text) > 160 ? mb_substr($raw_text, 160) : '';
        $rtime_r     = $r['created_at'] ? feed_rel_time((string)$r['created_at']) : '—';
        $av_slug     = $rslug ?: ($r['author_name'] ?: 'anonymous');
        $classes     = 'reply-stub'
                     . ($overflow ? ' reply-stub--overflow' : '')
                     . ($is_child ? ' reply-stub--child' : '');
        echo '<div class="' . $classes . '">';
        echo '<div class="reply-stub__head">';
        echo bb_mirror_avatar($r['author_name'] ?: 'Anonymous', $av_slug, $is_child ? 22 : 28);
        if ($rslug) {
            echo '<a class="reply-stub__author" href="' . htmlspecialchars('/members/' . $rslug . '/') . '">' . $ra . '</a>';
        } else {
            echo '<span class="reply-stub__author">' . $ra . '</span>';
        }
        echo '<time class="reply-stub__time">' . $rtime_r . '</time>';
        echo '</div>';
        echo '<div class="reply-stub__body">';
        if ($reply_short !== '') {
            echo '<span class="reply-stub__excerpt">' . htmlspecialchars($reply_short);
            if ($reply_rest) {
                echo '<span class="reply-stub__full" hidden>' . htmlspecialchars($reply_rest) . '</span>'
                   . '<button class="reply-stub__expand" type="button">… more</button>';
            }
            echo '</span>';
        }
        if (!empty($r['reply_image_url'])) {
            $iu = htmlspecialchars($r['reply_image_url']);
            if ($collapse_image) {
                // Teaser context: keep the image hidden AND unloaded (data-src, no
                // src) until the reader opens the reply — keeps the feed card compact.
                echo '<button class="reply-stub__img-open" type="button">&#128247; Show image</button>'
                   . '<img class="reply-stub__img reply-stub__img--deferred" data-src="' . $iu . '" alt="" hidden>';
            } else {
                echo '<img class="reply-stub__img" src="' . $iu . '" alt="" loading="lazy">';
            }
        }
        echo '</div></div>';
    }
}
