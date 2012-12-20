<?php
define( 'LIFT_PATH', plugin_dir_url( 'lift-search.php' ) );
$step_completed = array( );
$step_completed[2] = ( Lift_Search::get_access_key_id() && Lift_Search::get_secret_access_key() );
$step_completed[3] = ( Lift_Search::get_search_domain() );
$step_completed[4] = ( get_option( Lift_Search::INITIAL_SETUP_COMPLETE_OPTION, 0 ) );
$disabled = 'disabled="disabled"';
?>

<div class="wrap lift-admin" id="lift-setup-wrapper">
	<h2 class="lift-logo">Lift: Search <em>for</em> WordPress</h2>
	<div class="lift-setup">
		<div class="header-img">
			<img src="<?php echo LIFT_PATH . '/lift-search/img/lift-docs-header.png'; ?>"/>
		</div>
		<h2>Setup Instructions <a class="button button-secondary" href="http://getliftsearch.com/documentation/" target="_blank">Online Documentation</a></h2>
		<div class="ordered-list">
			<div class="lift-step lift-step-1">
				<p>
					<em>1</em> You will need an Amazon Web Services account, visit <a target="_blank" href="http://aws.amazon.com/">Amazon AWS</a>. <a href="http://aws.amazon.com/documentation/" class="button button-secondary" target="_blank">AWS Documentation</a>
				</p>
				<?php if ( ! $step_completed[4] ): ?>
					<br />
					<input type="button" value="Next" class="lift-next-step lift-step-button button-primary" data-lift_step="next" />
				<?php endif; ?>
			</div>
			<div class="lift-step lift-step-2 <?php echo ( ! $step_completed[4] ? 'hidden' : '' ); ?>">
				<p>
					<em>2</em> Add your account info. You can retrieve your access keys from the
					<a href="https://portal.aws.amazon.com/gp/aws/securityCredentials" target="_blank">Amazon security credentials page</a>. 
					You can also find this under the My Account dropdown menu when you are logged in to the AWS Console.
				</p>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">Access Key ID</th>
						<td>
							<input name="access-key-id" value="<?php echo esc_attr( Lift_Search::get_access_key_id() ); ?>" class="regular-text" type="text">
							<span class="lift-light-grey">
								example: AKIAJC2Y6SWP4F4IOFZQ
							</span>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Secret Access Key</th>
						<td>
							<input name="secret-access-key" value="<?php echo esc_attr( Lift_Search::get_secret_access_key() ); ?>" class="regular-text" type="text">
							<span class="lift-light-grey">
								example: bl/UjKudY/uWdsEiBM1RKZ1wuMxjTxPpYTs7K4Y
							</span>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><input type="button" value="Save Configuration" id="lift-test-access" class="button-primary"/></th>
						<td>
							<img src="<?php echo site_url( '/wp-admin/images/loading.gif' ); ?>" id="access-ajax-loader" class="hidden" />
							<span id="access-status-message" class="status-message success-message"></span>
						</td>
					</tr>
				</table>
				<?php if ( ! $step_completed[4] ): ?>
					<br />
					<input type="button" value="Back" class="lift-prev-step lift-step-button button-primary" data-lift_step="prev"/>
					<input type="button" value="Next" class="lift-next-step lift-step-button button-primary" data-lift_step="next" <?php echo ($step_completed[2] ? '' : $disabled); ?> />
				<?php endif; ?>
			</div>
			<div class="lift-step lift-step-3 <?php echo ( ! $step_completed[4] ? 'hidden' : '' ); ?>">
				<p><em>3</em> Please enter a search domain name. This must be a unique string to your AWS account. The domain name string can only contain the following characters: a-z (lowercase), 0-9, and - (hyphen). Uppercase letters and underscores are not allowed. The string has a max length of 28 characters.</p>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">Search Domain Name</th>
						<td>
							<input name="search-domain" value="<?php echo esc_attr( Lift_Search::get_search_domain() ); ?>" class="regular-text" type="text">
							<br><span class="lift-light-grey">
								If you have already configured a search domain in the AWS Console, enter it here. Otherwise, you will be prompted to create a new one after clicking Save Domain below.
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><input type="button" value="Save Domain" id="lift-test-domain" class="button-primary" />
						</th>
						<td>
							<img src="<?php echo site_url( '/wp-admin/images/loading.gif' ); ?>" id="domain-ajax-loader" class="hidden" />
							<span id="domain-status-message" class="status-message success-message"></span>
						</td>
					</tr>
				</table>
				<?php if ( ! $step_completed[4] ): ?>
					<br />
					<input type="button" value="Back" class="lift-prev-step lift-step-button button-primary" data-lift_step="prev"/>
					<input type="button" value="Next" class="lift-next-step lift-step-button button-primary" data-lift_step="next" <?php echo ($step_completed[3] ? '' : $disabled); ?> />
				<?php endif; ?>
			</div>
			<div class="lift-step lift-step-4 <?php echo ( ! $step_completed[4] ? 'hidden' : '' ); ?>">
				<p>
					<em>4</em> Your search domain is ready to go! If this is a new search domain, it will take approximately 30-40 minutes for it to become active and ready to index your documents.
				</p>
				<p>
					<?php if ( ! $step_completed[4] ): ?>
						<input type="button" value="Back" class="lift-prev-step lift-step-button button-primary"data-lift_step="prev" />
					<?php endif; ?>
					<a href="<?php echo admin_url( sprintf( 'options-general.php?page=%s', Lift_Search::ADMIN_STATUS_PAGE ) ); ?>" class="lift-admin-panel<?php echo ($step_completed[4] ? '-initial-completed' : ''); ?> button-primary">View Lift Search Dashboard</a> 
				</p>
			</div>
		</div><!-- end ordered-list -->
	</div> 

</div>