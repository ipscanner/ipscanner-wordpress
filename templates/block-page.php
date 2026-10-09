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
<?php wp_print_styles( 'ipscanner-block-page' ); ?>
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
