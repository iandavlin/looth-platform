<?php
/**
 * lg-comments-frame — minimal comments-only view for the standalone comments modal.
 *
 * When ?lg_comments=1 on a singular post, output ONLY the comments thread + WP's
 * native comment form (no theme chrome), styled to sit inside the iframe modal the
 * standalone page opens. Posting uses WP's wp-comments-post.php; the redirect keeps
 * the flag so the iframe reloads the thread (not the intercepted standalone permalink).
 *
 * Deployed to /var/www/dev/wp-content/mu-plugins/. Repo copy in archive-poc/deploy/.
 */
if (!defined('ABSPATH')) exit;

add_action('template_redirect', function () {
    if (empty($_GET['lg_comments']) || !is_singular()) return;
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comments</title>
<?php wp_head(); ?>
<style>
  html,body{margin:0;background:#fff;color:#1a1d1a;
            font-family:'Jost',system-ui,-apple-system,sans-serif;}
  body.lg-cframe{padding:18px 18px 48px;max-width:680px;margin:0 auto;}
  body.lg-cframe a{color:#87986a;}
  #wpadminbar{display:none!important;}
  body.lg-cframe .comment-respond textarea,
  body.lg-cframe .comment-respond input[type=text],
  body.lg-cframe .comment-respond input[type=email]{max-width:100%;box-sizing:border-box;}
</style>
</head>
<body class="lg-cframe">
<?php
    while (have_posts()) { the_post(); comments_template(); }
    wp_footer();
?>
</body>
</html><?php
    exit;
}, 1);

/* Stay in the comments-frame after posting (so the iframe reloads the thread, not
   the standalone permalink which has no inline comments). */
add_filter('comment_post_redirect', function ($location) {
    $ref = wp_get_referer();
    if ($ref && strpos($ref, 'lg_comments=1') !== false) {
        $location = add_query_arg('lg_comments', '1', $location);
    }
    return $location;
}, 10, 1);
