<?php
/**
 * CrawlerToll Pro — Bot-request logs view.
 *
 * @var array   $entries      Log entries for the current page.
 * @var int     $total        Total matching entries.
 * @var int     $page         Current page number.
 * @var int     $total_pages  Total pages.
 * @var string  $bot          Current bot filter.
 * @var string  $action       Current action filter.
 * @var string  $from         Start date filter.
 * @var string  $to           End date filter.
 * @var string  $orderby      Sort column.
 * @var string  $order        Sort direction.
 * @var array   $all_bots     Full bot catalogue for filter dropdown.
 * @var string  $site_url     Site URL.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base_url = admin_url( 'options-general.php?page=crawlertoll&ct_tab=logs' );
?>

<!-- Filters -->
<div class="ct-card" style="padding:16px 24px;">
	<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<input type="hidden" name="page" value="crawlertoll" />
		<input type="hidden" name="ct_tab" value="logs" />

		<select name="ct_bot" style="border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;">
			<option value=""><?php esc_html_e( 'All bots', 'crawlertoll' ); ?></option>
			<?php foreach ( $all_bots as $b ) : ?>
				<option value="<?php echo esc_attr( $b['name'] ); ?>" <?php selected( $bot, $b['name'] ); ?>>
					<?php echo esc_html( $b['name'] . ' (' . $b['operator'] . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="ct_action" style="border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;">
			<option value=""><?php esc_html_e( 'All actions', 'crawlertoll' ); ?></option>
			<option value="allow" <?php selected( $action, 'allow' ); ?>><?php esc_html_e( 'Allowed', 'crawlertoll' ); ?></option>
			<option value="402" <?php selected( $action, '402' ); ?>><?php esc_html_e( 'Charged (402)', 'crawlertoll' ); ?></option>
			<option value="block" <?php selected( $action, 'block' ); ?>><?php esc_html_e( 'Blocked (403)', 'crawlertoll' ); ?></option>
		</select>

		<input type="date" name="ct_from" value="<?php echo esc_attr( $from ); ?>" style="border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;" />
		<span style="color:var(--ct-text-muted);">—</span>
		<input type="date" name="ct_to" value="<?php echo esc_attr( $to ); ?>" style="border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;" />

		<button type="submit" class="button" style="background:var(--ct-primary);color:#fff;border:none;border-radius:6px;padding:6px 16px;cursor:pointer;"><?php esc_html_e( 'Filter', 'crawlertoll' ); ?></button>
		<a href="<?php echo esc_url( $base_url ); ?>" style="font-size:12px;color:var(--ct-text-muted);text-decoration:underline;"><?php esc_html_e( 'Reset', 'crawlertoll' ); ?></a>
	</form>
</div>

<!-- Results count -->
<div style="margin-bottom:12px;font-size:13px;color:var(--ct-text-muted);">
	<?php
	printf(
		/* translators: %d: total entries */
		esc_html( _n( '%d entry found', '%d entries found', $total, 'crawlertoll' ) ),
		(int) $total
	);
	?>
</div>

<!-- Logs table -->
<div class="ct-card" style="padding:0;overflow-x:auto;">
	<?php if ( empty( $entries ) ) : ?>
		<div style="padding:32px 24px;text-align:center;color:var(--ct-text-muted);">
			<?php esc_html_e( 'No log entries match your filters. AI crawler activity appears here as bots visit your site.', 'crawlertoll' ); ?>
		</div>
	<?php else : ?>
		<table style="width:100%;border-collapse:collapse;font-size:13px;">
			<thead>
				<tr style="background:#f8fafc;border-bottom:2px solid var(--ct-border);text-align:left;">
					<th style="padding:10px 12px;font-weight:600;color:var(--ct-text);white-space:nowrap;">
						<a href="<?php echo esc_url( add_query_arg( array( 'ct_orderby' => 'request_time', 'ct_order' => $orderby === 'request_time' && $order === 'ASC' ? 'DESC' : 'ASC' ) ) ); ?>" style="color:inherit;text-decoration:none;">
							<?php esc_html_e( 'Time', 'crawlertoll' ); ?>
							<?php if ( $orderby === 'request_time' ) echo $order === 'ASC' ? ' ↑' : ' ↓'; ?>
						</a>
					</th>
					<th style="padding:10px 12px;font-weight:600;color:var(--ct-text);">
						<a href="<?php echo esc_url( add_query_arg( array( 'ct_orderby' => 'bot_name', 'ct_order' => $orderby === 'bot_name' && $order === 'ASC' ? 'DESC' : 'ASC' ) ) ); ?>" style="color:inherit;text-decoration:none;">
							<?php esc_html_e( 'Bot', 'crawlertoll' ); ?>
							<?php if ( $orderby === 'bot_name' ) echo $order === 'ASC' ? ' ↑' : ' ↓'; ?>
						</a>
					</th>
					<th style="padding:10px 12px;font-weight:600;color:var(--ct-text);"><?php esc_html_e( 'Path', 'crawlertoll' ); ?></th>
					<th style="padding:10px 12px;font-weight:600;color:var(--ct-text);text-align:center;"><?php esc_html_e( 'Action', 'crawlertoll' ); ?></th>
					<th style="padding:10px 12px;font-weight:600;color:var(--ct-text);text-align:right;"><?php esc_html_e( 'Price', 'crawlertoll' ); ?></th>
					<th style="padding:10px 12px;font-weight:600;color:var(--ct-text);"><?php esc_html_e( 'Content Hash', 'crawlertoll' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) :
					$action_class = 'allow';
					$action_label = __( 'Allow', 'crawlertoll' );
					if ( $entry['action'] === '402' ) {
						$action_class = 'charged';
						$action_label = '402';
					} elseif ( $entry['action'] === 'block' ) {
						$action_class = 'blocked';
						$action_label = '403';
					}

					$price_display = '—';
					if ( $entry['action'] === '402' && ! empty( $entry['price_micros'] ) ) {
						$price_display = '$' . number_format( (int) $entry['price_micros'] / 1000000, 4 );
					}

					$hash_display = '—';
					if ( ! empty( $entry['content_hash'] ) ) {
						$hash_display = '<code style="font-size:11px;color:var(--ct-primary);">' . esc_html( substr( $entry['content_hash'], 0, 16 ) ) . '…</code>';
					}
				?>
					<tr style="border-bottom:1px solid #f1f5f9;">
						<td style="padding:8px 12px;white-space:nowrap;color:var(--ct-text-muted);">
							<?php echo esc_html( wp_date( 'M j, H:i', strtotime( $entry['request_time'] ) ) ); ?>
						</td>
						<td style="padding:8px 12px;">
							<div style="font-weight:600;color:var(--ct-text);"><?php echo esc_html( $entry['bot_name'] ); ?></div>
							<div style="font-size:11px;color:var(--ct-text-muted);"><?php echo esc_html( $entry['bot_operator'] ); ?></div>
						</td>
						<td style="padding:8px 12px;font-family:monospace;font-size:12px;color:var(--ct-text);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
							<?php echo esc_html( $entry['request_path'] ); ?>
						</td>
						<td style="padding:8px 12px;text-align:center;">
							<span style="display:inline-block;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;
								<?php if ( $action_class === 'allow' ) : ?>
									background:#d1fae5;color:#065f46;
								<?php elseif ( $action_class === 'charged' ) : ?>
									background:#fef3c7;color:#92400e;
								<?php else : ?>
									background:#fee2e2;color:#991b1b;
								<?php endif; ?>
							"><?php echo esc_html( $action_label ); ?></span>
						</td>
						<td style="padding:8px 12px;text-align:right;font-weight:600;color:<?php echo $entry['action'] === '402' ? 'var(--ct-success)' : 'var(--ct-text-muted)'; ?>;">
							<?php echo esc_html( $price_display ); ?>
						</td>
						<td style="padding:8px 12px;">
							<?php echo $hash_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — hash_display is already escaped. ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<!-- Pagination -->
<?php if ( $total_pages > 1 ) : ?>
<div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:16px;">
	<?php if ( $page > 1 ) : ?>
		<a href="<?php echo esc_url( add_query_arg( 'ct_page', $page - 1 ) ); ?>" style="padding:6px 14px;border:1px solid var(--ct-border);border-radius:6px;text-decoration:none;color:var(--ct-text);font-size:13px;">← <?php esc_html_e( 'Prev', 'crawlertoll' ); ?></a>
	<?php endif; ?>

	<span style="padding:6px 14px;font-size:13px;color:var(--ct-text-muted);">
		<?php echo esc_html( sprintf( __( 'Page %d of %d', 'crawlertoll' ), $page, $total_pages ) ); ?>
	</span>

	<?php if ( $page < $total_pages ) : ?>
		<a href="<?php echo esc_url( add_query_arg( 'ct_page', $page + 1 ) ); ?>" style="padding:6px 14px;border:1px solid var(--ct-border);border-radius:6px;text-decoration:none;color:var(--ct-text);font-size:13px;"><?php esc_html_e( 'Next', 'crawlertoll' ); ?> →</a>
	<?php endif; ?>
</div>
<?php endif; ?>

<!-- Export -->
<?php
// Carry the active filters into the export so it matches the on-screen view,
// and sign the request with a nonce verified by handle_export().
$ct_export_args = array(
	'action'     => 'crawlertoll_export_logs',
	'ct_bot'     => $bot,
	'ct_action'  => $action,
	'ct_from'    => $from,
	'ct_to'      => $to,
	'ct_orderby' => $orderby,
	'ct_order'   => $order,
);
$ct_export_csv  = wp_nonce_url( add_query_arg( array_merge( $ct_export_args, array( 'ct_export' => 'csv' ) ), admin_url( 'admin-ajax.php' ) ), 'crawlertoll_export_logs' );
$ct_export_json = wp_nonce_url( add_query_arg( array_merge( $ct_export_args, array( 'ct_export' => 'json' ) ), admin_url( 'admin-ajax.php' ) ), 'crawlertoll_export_logs' );
?>
<div style="margin-top:16px;text-align:right;">
	<a href="<?php echo esc_url( $ct_export_csv ); ?>" class="button" style="margin-right:8px;">
		<span class="dashicons dashicons-download" style="vertical-align:middle;font-size:16px;width:16px;height:16px;"></span>
		<?php esc_html_e( 'Export CSV', 'crawlertoll' ); ?>
	</a>
	<a href="<?php echo esc_url( $ct_export_json ); ?>" class="button">
		<span class="dashicons dashicons-download" style="vertical-align:middle;font-size:16px;width:16px;height:16px;"></span>
		<?php esc_html_e( 'Export JSON', 'crawlertoll' ); ?>
	</a>
</div>
