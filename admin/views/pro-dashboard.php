<?php
/**
 * CrawlerToll Pro — Revenue dashboard view.
 *
 * @var array   $current       Current period stats (totals, top_bots, top_paths).
 * @var float   $change_pct    Period-over-period revenue change percentage.
 * @var float   $revenue_dollars Total potential revenue in dollars.
 * @var int     $total_crawls  Total crawls in period.
 * @var int     $charged       Number of 402 responses.
 * @var int     $blocked       Number of 403 responses.
 * @var int     $allowed       Number of allowed requests.
 * @var array   $top_bots      Top bots by crawl count.
 * @var array   $top_paths     Top paths by crawl count.
 * @var int     $max_bot_crawls Max crawls for bar chart scaling.
 * @var string  $from          Start date.
 * @var string  $to            End date.
 * @var string  $currency      Currency code.
 * @var string  $site_url      Site URL for export links.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$change_class = $change_pct >= 0 ? 'green' : 'red';
$change_arrow = $change_pct >= 0 ? '↑' : '↓';
?>

<!-- Revenue status cards -->
<div class="ct-status-bar">
	<div class="ct-stat-card">
		<div class="ct-stat-icon green">
			<span class="dashicons dashicons-money"></span>
		</div>
		<div class="ct-stat-value">$<?php echo esc_html( $revenue_dollars ); ?></div>
		<div class="ct-stat-label">
			<?php esc_html_e( 'Potential Revenue', 'crawlertoll' ); ?>
			<span style="color:<?php echo $change_pct >= 0 ? 'var(--ct-success)' : 'var(--ct-danger)'; ?>;font-weight:600;">
				<?php echo esc_html( $change_arrow . ' ' . abs( $change_pct ) . '%' ); ?>
			</span>
		</div>
	</div>
	<div class="ct-stat-card">
		<div class="ct-stat-icon purple">
			<span class="dashicons dashicons-chart-bar"></span>
		</div>
		<div class="ct-stat-value"><?php echo number_format_i18n( $total_crawls ); ?></div>
		<div class="ct-stat-label"><?php esc_html_e( 'Total AI Crawls', 'crawlertoll' ); ?></div>
	</div>
	<div class="ct-stat-card">
		<div class="ct-stat-icon blue">
			<span class="dashicons dashicons-tag"></span>
		</div>
		<div class="ct-stat-value"><?php echo number_format_i18n( $charged ); ?></div>
		<div class="ct-stat-label"><?php esc_html_e( 'Charged (402)', 'crawlertoll' ); ?></div>
	</div>
	<div class="ct-stat-card">
		<div class="ct-stat-icon amber">
			<span class="dashicons dashicons-dismiss"></span>
		</div>
		<div class="ct-stat-value"><?php echo number_format_i18n( $blocked ); ?></div>
		<div class="ct-stat-label"><?php esc_html_e( 'Blocked (403)', 'crawlertoll' ); ?></div>
	</div>
</div>

<!-- Date range selector -->
<div class="ct-card" style="padding:16px 24px;">
	<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<input type="hidden" name="page" value="crawlertoll" />
		<input type="hidden" name="ct_tab" value="revenue" />
		<label style="font-weight:600;font-size:13px;color:var(--ct-text);"><?php esc_html_e( 'Period:', 'crawlertoll' ); ?></label>
		<input type="date" name="ct_from" value="<?php echo esc_attr( $from ); ?>" style="border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;" />
		<span style="color:var(--ct-text-muted);">—</span>
		<input type="date" name="ct_to" value="<?php echo esc_attr( $to ); ?>" style="border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;" />
		<button type="submit" class="button" style="background:var(--ct-primary);color:#fff;border:none;border-radius:6px;padding:6px 16px;cursor:pointer;"><?php esc_html_e( 'Update', 'crawlertoll' ); ?></button>
	</form>
</div>

<!-- Top bots bar chart -->
<div class="ct-card">
	<h2>
		<span class="dashicons dashicons-networking"></span>
		<?php esc_html_e( 'Top AI Crawlers', 'crawlertoll' ); ?>
	</h2>
	<p class="ct-card-desc"><?php esc_html_e( 'Crawlers that hit your site most often. Revenue = crawls × your per-path price for disallowed paths.', 'crawlertoll' ); ?></p>

	<?php if ( empty( $top_bots ) ) : ?>
		<p style="color:var(--ct-text-muted);padding:24px 0;"><?php esc_html_e( 'No crawler activity recorded yet. Data appears as AI bots visit your site.', 'crawlertoll' ); ?></p>
	<?php else : ?>
		<div style="margin-top:12px;">
			<?php foreach ( $top_bots as $bot ) :
				$bar_width = round( ( (int) $bot['crawls'] / $max_bot_crawls ) * 100 );
				$bot_revenue = isset( $bot['revenue_micros'] ) ? number_format( (int) $bot['revenue_micros'] / 1000000, 2 ) : '0.00';
			?>
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
					<div style="width:160px;font-size:13px;font-weight:600;color:var(--ct-text);text-align:right;flex-shrink:0;"><?php echo esc_html( $bot['bot_name'] ); ?></div>
					<div style="flex:1;background:#f1f5f9;border-radius:6px;height:24px;overflow:hidden;position:relative;">
						<div style="background:linear-gradient(90deg,var(--ct-primary),#8b5cf6);height:100%;width:<?php echo (int) $bar_width; ?>%;border-radius:6px;min-width:2px;transition:width .3s;"></div>
					</div>
					<div style="font-size:12px;font-weight:600;color:var(--ct-text);width:80px;flex-shrink:0;"><?php echo number_format_i18n( (int) $bot['crawls'] ); ?> <?php esc_html_e( 'crawls', 'crawlertoll' ); ?></div>
					<div style="font-size:12px;color:var(--ct-success);font-weight:600;width:80px;flex-shrink:0;">$<?php echo esc_html( $bot_revenue ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<!-- Top paths -->
<div class="ct-card">
	<h2>
		<span class="dashicons dashicons-admin-page"></span>
		<?php esc_html_e( 'Most Crawled Pages', 'crawlertoll' ); ?>
	</h2>
	<p class="ct-card-desc"><?php esc_html_e( 'Pages AI crawlers target most. Higher crawl counts on premium pages = more potential revenue.', 'crawlertoll' ); ?></p>

	<?php if ( empty( $top_paths ) ) : ?>
		<p style="color:var(--ct-text-muted);padding:24px 0;"><?php esc_html_e( 'No page-level data yet.', 'crawlertoll' ); ?></p>
	<?php else : ?>
		<table style="width:100%;border-collapse:collapse;margin-top:12px;">
			<thead>
				<tr style="border-bottom:1px solid var(--ct-border);text-align:left;">
					<th style="padding:8px 12px;font-size:12px;color:var(--ct-text-muted);font-weight:600;"><?php esc_html_e( 'Path', 'crawlertoll' ); ?></th>
					<th style="padding:8px 12px;font-size:12px;color:var(--ct-text-muted);font-weight:600;text-align:right;"><?php esc_html_e( 'Crawls', 'crawlertoll' ); ?></th>
					<th style="padding:8px 12px;font-size:12px;color:var(--ct-text-muted);font-weight:600;text-align:right;"><?php esc_html_e( 'Revenue', 'crawlertoll' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $top_paths as $p ) :
					$path_rev = isset( $p['revenue_micros'] ) ? number_format( (int) $p['revenue_micros'] / 1000000, 2 ) : '0.00';
				?>
					<tr style="border-bottom:1px solid #f1f5f9;">
						<td style="padding:8px 12px;font-size:13px;font-family:monospace;color:var(--ct-text);"><?php echo esc_html( $p['request_path'] ); ?></td>
						<td style="padding:8px 12px;font-size:13px;color:var(--ct-text);text-align:right;font-weight:600;"><?php echo number_format_i18n( (int) $p['crawls'] ); ?></td>
						<td style="padding:8px 12px;font-size:13px;color:var(--ct-success);text-align:right;font-weight:600;">$<?php echo esc_html( $path_rev ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<!-- Pro upsell if not active -->
<?php if ( ! CrawlerToll_Pro_Admin::is_pro_active() ) : ?>
<div class="ct-card" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;">
	<h2 style="color:#fff;">
		<span class="dashicons dashicons-lock" style="color:rgba(255,255,255,.8);"></span>
		<?php esc_html_e( 'Unlock Full Revenue Dashboard', 'crawlertoll' ); ?>
	</h2>
	<p style="color:rgba(255,255,255,.85);margin-bottom:16px;">
		<?php esc_html_e( 'Get per-path pricing, email alerts, content provenance, and bot-request logs. $29/mo per site.', 'crawlertoll' ); ?>
	</p>
	<a href="https://crawlertoll.com/#pricing" target="_blank" rel="noopener" style="display:inline-block;background:#fff;color:#6366f1;padding:10px 24px;border-radius:8px;font-weight:600;text-decoration:none;font-size:14px;">
		<?php esc_html_e( 'Get CrawlerToll Pro →', 'crawlertoll' ); ?>
	</a>
</div>
<?php endif; ?>
