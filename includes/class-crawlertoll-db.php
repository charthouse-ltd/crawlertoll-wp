<?php
/**
 * Database layer — custom table creation, migration, and cleanup.
 *
 * Uses a dedicated custom table (NOT wp_options) for bot-request logs
 * to avoid autoload bloat. Indexed for fast queries by bot, time, and action.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_DB {

	/**
	 * Table name (with $wpdb->prefix).
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * @param wpdb $wpdb WordPress database abstraction.
	 */
	public function __construct( $wpdb ) {
		$this->table_name = $wpdb->prefix . 'crawlertoll_log';
	}

	/**
	 * Create or update the log table. Safe to call on every plugin load —
	 * dbDelta is idempotent.
	 *
	 * @return void
	 */
	public function maybe_create_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $GLOBALS['wpdb']->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			request_time DATETIME NOT NULL,
			bot_name VARCHAR(100) NOT NULL,
			bot_operator VARCHAR(100) NOT NULL DEFAULT '',
			bot_category VARCHAR(20) NOT NULL DEFAULT '',
			request_path VARCHAR(255) NOT NULL,
			request_method VARCHAR(10) NOT NULL DEFAULT 'GET',
			action VARCHAR(10) NOT NULL,
			price_micros INT UNSIGNED DEFAULT 0,
			currency VARCHAR(5) DEFAULT 'USD',
			rail VARCHAR(30) DEFAULT 'x402',
			content_hash CHAR(64) DEFAULT NULL,
			http_status SMALLINT UNSIGNED,
			user_agent TEXT,
			is_paid TINYINT(1) DEFAULT 0,
			paid_at DATETIME DEFAULT NULL,
			payment_rail VARCHAR(30) DEFAULT NULL,
			payment_id VARCHAR(100) DEFAULT NULL,
			INDEX idx_bot_time (bot_name, request_time),
			INDEX idx_time (request_time),
			INDEX idx_action (action, request_time),
			INDEX idx_hash (content_hash),
			INDEX idx_paid (is_paid, request_time)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a log entry. Returns the new row ID.
	 *
	 * @param array<string,mixed> $entry
	 * @return int|false Row ID on success, false on failure.
	 */
	public function insert( $entry ) {
		global $wpdb;

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'request_time'  => isset( $entry['request_time'] ) ? $entry['request_time'] : current_time( 'mysql' ),
				'bot_name'      => isset( $entry['bot_name'] ) ? $entry['bot_name'] : '',
				'bot_operator'  => isset( $entry['bot_operator'] ) ? $entry['bot_operator'] : '',
				'bot_category'  => isset( $entry['bot_category'] ) ? $entry['bot_category'] : '',
				'request_path'  => isset( $entry['request_path'] ) ? $entry['request_path'] : '/',
				'request_method' => isset( $entry['request_method'] ) ? $entry['request_method'] : 'GET',
				'action'        => isset( $entry['action'] ) ? $entry['action'] : 'allow',
				'price_micros'  => isset( $entry['price_micros'] ) ? (int) $entry['price_micros'] : 0,
				'currency'      => isset( $entry['currency'] ) ? $entry['currency'] : 'USD',
				'rail'          => isset( $entry['rail'] ) ? $entry['rail'] : 'x402',
				'content_hash'  => isset( $entry['content_hash'] ) ? $entry['content_hash'] : null,
				'http_status'   => isset( $entry['http_status'] ) ? (int) $entry['http_status'] : 200,
				'user_agent'    => isset( $entry['user_agent'] ) ? $entry['user_agent'] : '',
			),
			array(
				'%s', // request_time
				'%s', // bot_name
				'%s', // bot_operator
				'%s', // bot_category
				'%s', // request_path
				'%s', // request_method
				'%s', // action
				'%d', // price_micros
				'%s', // currency
				'%s', // rail
				'%s', // content_hash (nullable)
				'%d', // http_status
				'%s', // user_agent
			)
		);

		if ( $result === false ) {
			return false;
		}
		return $wpdb->insert_id;
	}

	/**
	 * Query logs with optional filters.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $bot       Filter by bot name.
	 *     @type string $action    Filter by action (allow|402|block).
	 *     @type string $from      Start date (Y-m-d).
	 *     @type string $to        End date (Y-m-d).
	 *     @type int    $page      Page number (1-based).
	 *     @type int    $per_page  Rows per page (default 50).
	 *     @type string $orderby   Sort column (default: request_time).
	 *     @type string $order     ASC or DESC (default: DESC).
	 * }
	 * @return array{entries: array, total: int, page: int, per_page: int}
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'bot'      => '',
			'action'   => '',
			'from'     => '',
			'to'       => '',
			'page'     => 1,
			'per_page' => 50,
			'orderby'  => 'request_time',
			'order'    => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['bot'] ) ) {
			$where[]  = 'bot_name = %s';
			$values[] = $args['bot'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$values[] = $args['action'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'request_time >= %s';
			$values[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'request_time <= %s';
			$values[] = $args['to'] . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Sanitise orderby to allowed columns.
		$allowed_cols = array( 'request_time', 'bot_name', 'action', 'price_micros', 'request_path' );
		if ( ! in_array( $args['orderby'], $allowed_cols, true ) ) {
			$args['orderby'] = 'request_time';
		}
		$order_dir = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		// Paged query.
		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * max( 1, (int) $args['per_page'] );
		$data_sql = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$args['orderby']} {$order_dir} LIMIT %d OFFSET %d";
		$values[] = max( 1, (int) $args['per_page'] );
		$values[] = $offset;
		$data_sql = $wpdb->prepare( $data_sql, $values );

		$entries = $wpdb->get_results( $data_sql, ARRAY_A );

		return array(
			'entries'  => $entries ? $entries : array(),
			'total'    => $total,
			'page'     => (int) $args['page'],
			'per_page' => (int) $args['per_page'],
		);
	}

	/**
	 * Return every log entry matching the given filters, up to a safety cap.
	 *
	 * Reuses query() (same filter + orderby semantics) with a single large
	 * page so the export and the on-screen logs can never disagree about what
	 * a filter means. The cap guards against streaming an unbounded result set
	 * for "keep forever" retention tables.
	 *
	 * @param array<string,mixed> $args Same filter keys as query(): bot, action,
	 *                                   from, to, orderby, order. Plus optional
	 *                                   int `limit` (default 50000).
	 * @return array<int,array<string,mixed>>
	 */
	public function export( $args = array() ) {
		$limit            = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50000;
		$args['page']     = 1;
		$args['per_page'] = $limit;

		$result = $this->query( $args );
		return isset( $result['entries'] ) ? $result['entries'] : array();
	}

	/**
	 * Get aggregate stats for a time period.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array<string,mixed>
	 */
	public function stats( $from, $to ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT
				COUNT(*) AS total_crawls,
				SUM(CASE WHEN action = 'allow' THEN 1 ELSE 0 END) AS allowed,
				SUM(CASE WHEN action = '402' THEN 1 ELSE 0 END) AS charged,
				SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END) AS blocked,
				SUM(CASE WHEN action = '402' THEN price_micros ELSE 0 END) AS total_revenue_micros
			FROM {$this->table_name}
			WHERE request_time >= %s AND request_time <= %s",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		);

		$row = $wpdb->get_row( $sql, ARRAY_A );

		// Top bots.
		$top_bots_sql = $wpdb->prepare(
			"SELECT bot_name, bot_operator, COUNT(*) AS crawls, SUM(price_micros) AS revenue_micros
			FROM {$this->table_name}
			WHERE request_time >= %s AND request_time <= %s AND action = '402'
			GROUP BY bot_name, bot_operator
			ORDER BY crawls DESC
			LIMIT 10",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		);
		$top_bots = $wpdb->get_results( $top_bots_sql, ARRAY_A );

		// Top paths.
		$top_paths_sql = $wpdb->prepare(
			"SELECT request_path, COUNT(*) AS crawls, SUM(price_micros) AS revenue_micros
			FROM {$this->table_name}
			WHERE request_time >= %s AND request_time <= %s AND action = '402'
			GROUP BY request_path
			ORDER BY crawls DESC
			LIMIT 10",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		);
		$top_paths = $wpdb->get_results( $top_paths_sql, ARRAY_A );

		return array(
			'totals'    => $row ? $row : array(),
			'top_bots'  => $top_bots ? $top_bots : array(),
			'top_paths' => $top_paths ? $top_paths : array(),
		);
	}

	/**
	 * Per-day activity buckets for a period — powers the dashboard trend charts.
	 * Days with no activity are omitted (the client zero-fills against the
	 * requested range). DATE()/GROUP BY work on both MySQL and the SQLite drop-in.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array<int,array<string,mixed>> Rows: day, crawls, allowed, charged, blocked, revenue_micros.
	 */
	public function timeseries( $from, $to ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT
				DATE(request_time) AS day,
				COUNT(*) AS crawls,
				SUM(CASE WHEN action = 'allow' THEN 1 ELSE 0 END) AS allowed,
				SUM(CASE WHEN action = '402' THEN 1 ELSE 0 END) AS charged,
				SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END) AS blocked,
				SUM(CASE WHEN action = '402' THEN price_micros ELSE 0 END) AS revenue_micros
			FROM {$this->table_name}
			WHERE request_time >= %s AND request_time <= %s
			GROUP BY DATE(request_time)
			ORDER BY day ASC",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Look up a log entry by content hash.
	 *
	 * @param string $hash SHA-256 hex string.
	 * @return array|null
	 */
	public function lookup_hash( $hash ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE content_hash = %s LIMIT 1",
			$hash
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Mark a log entry as paid.
	 *
	 * @param int    $log_id
	 * @param string $rail   Settlement rail.
	 * @param string $payment_id Payment identifier.
	 * @return bool
	 */
	public function mark_paid( $log_id, $rail, $payment_id ) {
		global $wpdb;

		$result = $wpdb->update(
			$this->table_name,
			array(
				'is_paid'      => 1,
				'paid_at'      => current_time( 'mysql' ),
				'payment_rail' => $rail,
				'payment_id'   => $payment_id,
			),
			array( 'id' => (int) $log_id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete old log entries beyond the retention period.
	 *
	 * @param int $retention_days
	 * @return int Number of rows deleted.
	 */
	public function purge_old( $retention_days = 90 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
		$sql = $wpdb->prepare(
			"DELETE FROM {$this->table_name} WHERE request_time < %s",
			$cutoff
		);
		return $wpdb->query( $sql );
	}

	/**
	 * Drop the log table. Used on uninstall when "remove all data" is checked.
	 *
	 * @return void
	 */
	public function drop_table() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table_name}" );
	}

	/**
	 * @return string
	 */
	public function get_table_name() {
		return $this->table_name;
	}
}
