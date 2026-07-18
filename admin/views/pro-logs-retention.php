<?php
/**
 * CrawlerToll Pro — log retention control. A write form (nonce'd POST), so it
 * stays server-rendered and sits above the React log browser (which owns the
 * read-only filter/table/export). Extracted from pro-logs.php so the React mount
 * can replace the browser without swallowing this form.
 *
 * @var int    $retention_days Current retention window in days.
 * @var string $base_url       Logs-tab URL (POST target).
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ct-card" style="padding:12px 24px;margin-bottom:12px;">
	<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
		<?php wp_nonce_field( 'crawlertoll_save_retention', 'crawlertoll_retention_nonce' ); ?>
		<label for="ct_retention_days" style="font-size:13px;font-weight:600;"><?php esc_html_e( 'Keep logs for', 'crawlertoll' ); ?></label>
		<input type="number" min="0" step="1" id="ct_retention_days" name="ct_retention_days" value="<?php echo esc_attr( (string) $retention_days ); ?>" style="width:80px;border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;" />
		<span style="font-size:13px;color:var(--ct-text-muted);"><?php esc_html_e( 'days — older entries are purged daily (0 = keep forever).', 'crawlertoll' ); ?></span>
		<button type="submit" class="button" style="font-size:13px;"><?php esc_html_e( 'Save', 'crawlertoll' ); ?></button>
	</form>
</div>
