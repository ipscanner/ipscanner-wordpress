<?php
/**
 * Block page sent with a 403.
 *
 * @package IPScanner
 *
 * @var string $ipscanner_message Message for the visitor.
 * @var bool   $ipscanner_credit  Show the IPScanner link.
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e( 'Access denied', 'ipscanner' ); ?></title>
<style>
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f7;color:#1d2327;line-height:1.5}
main{max-width:32rem;margin:12vh auto 0;padding:2rem;background:#fff;border:1px solid #dcdcde;border-radius:8px}
h1{font-size:1.25rem;margin:0 0 .75rem}
p{margin:0 0 1rem}
.site{color:#50575e;font-size:.875rem}
.site a{color:inherit}
footer{max-width:32rem;margin:1rem auto;padding:0 2rem;font-size:.75rem;color:#787c82}
footer a{color:inherit}
@media (prefers-color-scheme:dark){body{background:#1d2327;color:#f0f0f1}main{background:#2c3338;border-color:#3c434a}.site{color:#c3c4c7}}
</style>
</head>
<body>
<main>
<h1><?php esc_html_e( 'Access denied', 'ipscanner' ); ?></h1>
<p><?php echo nl2br( esc_html( $ipscanner_message ) ); ?></p>
<p class="site"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></p>
</main>
<?php if ( $ipscanner_credit ) : ?>
<footer><a href="https://ipscanner.io" rel="noopener"><?php esc_html_e( 'Protected by IPScanner', 'ipscanner' ); ?></a></footer>
<?php endif; ?>
</body>
</html>
