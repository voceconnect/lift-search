<?php

$cron_enabled = Lift_Batch_Queue::cron_enabled();
$status = Lift_Health::get_overall_status();

$overall_status_text = 'All Clear';
$overall_status_class = '';

if ( 1 == $status['severity'] ) {
    $overall_status_text = 'Warning';
    $overall_status_class = 'caution';
} else if ( 2 == $status['severity'] ) {
    $overall_status_text = 'Critical';
    $overall_status_class = 'error';
}

$overall_status_reasons = $status['reason'];
$remote_domain_status = ( isset( $status['remote_status'] ) ) ? $status['remote_status'] : false ;

if ( ! isset( $remote_domain_status['fatal'] ) ) {
    $remote_document_text = ( 1 != $status['remote_status']['num_searchable_docs'] ) ? 'documents' : 'document';
    $remote_partition_text = ( 1 != $status['remote_status']['search_partition_count'] ) ? 'partitions' : 'partition';
    $remote_instance_text = ( 1 != $status['remote_status']['search_instance_count'] ) ? 'instances' : 'instance';
}

$remote_domain_status_text = $remote_domain_status['text'];
$domain = Lift_Search::get_search_domain();

?>
<div class="wrap lift-admin" id="lift-status-page">
	<h2 class="lift-logo">Lift: Search <em>for</em> WordPress</h2>
	<div class="dashboard">
		<table class="lift-snapshot">
			<tr>
				<td class="status <?php echo esc_attr( $overall_status_class ); ?>">
					<h3><?php echo esc_html( $overall_status_text ); ?></h3>
                    <?php if ( $overall_status_reasons ) : ?>
                        <ul>
                        <?php foreach ( $overall_status_reasons as $reason ) : ?>
                            <li><?php echo esc_html( $reason ); ?></li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
					<p><a href="#lift-logs">view logs below</a></p>
				</td>
				<td class="lift-sync">
					<div class="misc-pub-section curtime alignright">
						<table class="lift-schedule">
							<tr>
								<td>Last: </td>
								<td>
									<strong id="last-cron"><?php echo esc_html(Lift_Batch_Queue::get_last_cron_time()); ?></strong>
								</td>
							</tr>
							<tr>
								<td>Next: </td>
								<td>
									<strong id="next-cron"><?php echo esc_html(Lift_Batch_Queue::get_next_cron_time()); ?></strong>
								</td>
							</tr>
						</table>
					</div>
					<h3>CloudSearch Index Sync</h3>
					<div class="clr"></div>
					<hr/>
					<div class="alignright width-30">
						<div id="set-cron-status" class="btn-group">
							<img src="<?php echo site_url( '/wp-admin/images/loading.gif' ); ?>" id="update-ajax-loader" class="hidden" />
							<span class="slider-button button<?php echo $cron_enabled ? '-primary on' : ' off'; ?>"><?php echo $cron_enabled ? 'ON' : 'OFF'; ?></span>
						</div>
						<div class="clr"></div>
						<div class="lift-index-now">
                            <form method="get" action="">
                                <input type="hidden" name="page" value="<?php echo esc_attr( Lift_Search::ADMIN_STATUS_PAGE ); ?>">
                                <input type="hidden" name="sync-queue" value="1">
                                <button class="button-primary" <?php echo ( ! Lift_Batch_Queue::ready_for_batch( Lift_Search::get_search_domain() ) ) ? 'disabled' : ''; ?>>Sync Queue Now</button>
                            </form>
						</div>
						<div class="clr"></div>
					</div>
					<div class="lift-auto-update-options alignleft width-70">
						<h4>Auto Update Every:</h4>
						<div class="alignleft">
							<div class="misc-pub-section alignleft">
								<input name="batch-interval" id="lift-search-settings-page-search-config-settings-batch-interval" class="regular-text cron-update" value="<?php echo Lift_Search::get_batch_interval_adjusted(); ?>" type="text">
								<select name="batch-interval-units" id="lift-search-settings-page-search-config-settings-batch-units">
									<option value="m" <?php selected( Lift_Search::get_batch_interval_unit(), 'm' ); ?>>Minutes</option>
									<option value="h" <?php selected( Lift_Search::get_batch_interval_unit(), 'h' ); ?>>Hours</option>
									<option value="d" <?php selected( Lift_Search::get_batch_interval_unit(), 'd' ); ?>>Days</option>
								</select>
								<input type="button" id="update-cron-interval" value="Save" class="button button-secondary"/>
								<div class="clr"></div>
							</div>
							<div class="clr"></div>
						</div>
					</div>
					<div class="clr"></div>
				</td>
				<td class="edit">
					<a class="button" href="<?php echo admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_LANDING_PAGE ); ?>">Settings</a>
					<div class="clr"></div>
					<br />
					<a class="button button-secondary" href="http://getliftsearch.com/documentation/" target="_blank">Documentation</a>
				</td>
			</tr>
		</table>
        
        <div id="lift-remote-status">
            <p>
                Amazon CloudSearch search domain status for <i><?php echo esc_html( $domain ); ?></i>: 
                <b><?php echo esc_html( strtoupper( $remote_domain_status_text ) ); ?></b><?php if ( $remote_domain_status && ! ( isset( $remote_domain_status['fatal'] ) ) && $remote_domain_status['needs_indexing'] ) : ?>
                <a href="<?php echo admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_STATUS_PAGE . '&lift-indexdocuments' ); ?>" class="button">Index Now</a><?php endif; ?>.
                <?php if ( ! isset( $remote_domain_status['fatal'] ) ) : ?>
                        Your index has <?php echo esc_html( $remote_domain_status['num_searchable_docs'] ); ?> searchable <?php echo $remote_document_text; ?>
                        using <?php echo esc_html( $remote_domain_status['search_partition_count'] ); ?> search <?php echo $remote_partition_text; ?> 
                        served by <?php echo esc_html( $remote_domain_status['search_instance_count'] ); ?> <i><?php echo esc_html( $remote_domain_status['search_instance_type'] ); ?></i> <?php echo $remote_instance_text; ?>.
                <?php endif; ?>
            </p>
        </div>
		
        <div class="indent">
			<h3><span class="alignright">Documents in Queue: <strong><?php echo number_format( Lift_Batch_Queue::get_queue_count() ); ?></strong></span>Documents to be Synced</h3>
			<?php echo Lift_Batch_Queue::get_queue_list(); ?>			
			<h3 class="alignleft" id="lift-logs">Recent Logs</h3> 
			<p class="alignleft" style="padding-top:3px ;margin-left:15px;">
				<a href="<?php echo esc_attr( admin_url( sprintf( 'edit.php?post_type=%s', Voce_Error_Logging::POST_TYPE ) ) ); ?>" class="alignleft">View All</a>
			</p>
			<?php if ( Voce_Error_Logging::get_log_count() && Voce_Error_Logging::get_log_count() > 0 ): ?>
				<div class="alignright" style="margin-bottom:5px;"><p>
						<span class="alignleft status-message" id="clear-log-status-message" style="padding-top:3px; margin-right:15px;"></span>
						<img id="clear-logs-loader" style="padding-top:3px; margin-right:15px;" src="<?php echo site_url( '/wp-admin/images/loading.gif' ); ?>" class="alignleft hidden"/>
						<input type="button" class="button alignright" id="voce-lift-admin-settings-clear-status-logs" value="Clear Logs" />
					</p></div>
			<?php endif; ?>
			<?php echo Lift_Search::RecentLogTable(); ?>
		</div><!-- end indent -->
	</div> <!-- end dashboard -->
</div>