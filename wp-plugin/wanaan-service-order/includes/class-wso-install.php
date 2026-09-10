<?php
/**
 * Activation / deactivation: the custom table, the rewrite rule for the public
 * page, and a self-healing schema check on every load.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Install {

	/** Bumped when the table schema changes; drives maybe_upgrade(). */
	const SCHEMA_VERSION = '2';

	const SCHEMA_OPTION = 'wanaan_so_schema_version';

	/**
	 * Runs once on activation: create the table and register + flush the
	 * /service-order/{token} rewrite rule.
	 */
	public static function activate() {
		self::create_table();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );

		WSO_Page::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * On deactivation, drop the rewrite rule (the table + its data are kept, so
	 * deactivating never destroys payment history — removal is manual).
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Re-run the schema build if the stored version is behind. Cheap on every
	 * request; dbDelta is a no-op when the table already matches.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			self::create_table();
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}
	}

	/**
	 * The service-order table. One row per ERP payment link ("partition");
	 * erp_payment_id is UNIQUE so a re-send from the ERP updates in place and a
	 * replayed webhook can never create a duplicate.
	 */
	private static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = wanaan_so_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			erp_payment_id BIGINT UNSIGNED NOT NULL,
			erp_booking_id BIGINT UNSIGNED NULL,
			erp_leg_id BIGINT UNSIGNED NULL,
			ws INT NULL,
			token VARCHAR(64) NOT NULL,
			confirmation_no VARCHAR(64) NULL,
			booking_no VARCHAR(64) NULL,
			so_date VARCHAR(32) NULL,
			customer_name VARCHAR(191) NULL,
			customer_email VARCHAR(191) NULL,
			telephone VARCHAR(64) NULL,
			service_date VARCHAR(32) NULL,
			service_time VARCHAR(32) NULL,
			vehicle VARCHAR(191) NULL,
			flight_number VARCHAR(64) NULL,
			pax_name VARCHAR(191) NULL,
			pax_contact VARCHAR(64) NULL,
			pickup TEXT NULL,
			dropoff TEXT NULL,
			remark TEXT NULL,
			amount DECIMAL(12,3) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'BHD',
			status VARCHAR(16) NOT NULL DEFAULT 'unpaid',
			woo_order_id BIGINT UNSIGNED NULL,
			transaction_ref VARCHAR(191) NULL,
			consent_at DATETIME NULL,
			consent_ip VARCHAR(64) NULL,
			consent_ua TEXT NULL,
			paid_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY erp_payment_id (erp_payment_id),
			UNIQUE KEY token (token),
			KEY woo_order_id (woo_order_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
