<?php
    $to_from = 0;
    if ( $wp_query->current_post > -1 ) {
        $to_from = $wp_query->current_post ." - " . ( $wp_query->current_post + get_option( 'posts_per_page' ) );
    }
?>
<?php get_header(); ?>
<div class="lift-search">
	<?php lift_search_form(); ?>
	<div class="lift-filter-list">
		<h2>Results <?php echo esc_html( $to_from ); ?> of <?php echo esc_html( $wp_query->post_count ); ?></h2>
	</div>
	<?php lift_loop(); ?>
</div> <!-- end lift search -->
<?php get_footer(); ?>