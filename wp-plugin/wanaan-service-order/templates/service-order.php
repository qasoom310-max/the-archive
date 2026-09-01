<?php
/**
 * The public Service Order page. Standalone (theme-independent) so it renders
 * the same on any WordPress install, mobile-first, matching the ERP PDF layout.
 *
 * @var object|null $wanaan_so_row     Set by WSO_Page::maybe_render().
 * @var string      $wanaan_so_notice  Error slug from the ?error= param.
 */

defined( 'ABSPATH' ) || exit;

$row    = isset( $GLOBALS['wanaan_so_row'] ) ? $GLOBALS['wanaan_so_row'] : null;
$notice = isset( $GLOBALS['wanaan_so_notice'] ) ? $GLOBALS['wanaan_so_notice'] : '';

$terms_url = 'https://www.wanaan-bh.com/terms-and-conditions/';

$messages = array(
	'expired'      => __( 'Your session expired. Please review and submit again.', 'wanaan-service-order' ),
	'already_paid' => __( 'This service order has already been paid.', 'wanaan-service-order' ),
	'terms'        => __( 'Please accept the terms and conditions before paying.', 'wanaan-service-order' ),
	'woo'          => __( 'We could not start the payment. Please try again or contact us.', 'wanaan-service-order' ),
);

/** Small helper: a labelled detail row, only when the value is present. */
$detail = function ( $label, $value ) {
	if ( '' === (string) $value || null === $value ) {
		return;
	}
	echo '<div class="wso-row"><span class="wso-label">' . esc_html( $label ) . '</span><span class="wso-value">' . esc_html( $value ) . '</span></div>';
};
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Service Order', 'wanaan-service-order' ); ?> &middot; Wanaan</title>
	<style>
		:root { --ink:#1a1a2e; --muted:#6b7280; --line:#e5e7eb; --brand:#0b1f3a; --gold:#b6862c; --ok:#137333; }
		* { box-sizing: border-box; }
		body { margin:0; background:#f3f4f6; color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; line-height:1.5; }
		.wso-wrap { max-width:640px; margin:0 auto; padding:16px; }
		.wso-card { background:#fff; border:1px solid var(--line); border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
		.wso-head { background:var(--brand); color:#fff; padding:22px 24px; }
		.wso-head h1 { margin:0; font-size:20px; letter-spacing:.02em; }
		.wso-head .wso-sub { opacity:.85; font-size:13px; margin-top:2px; }
		.wso-body { padding:20px 24px; }
		.wso-section-title { font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin:18px 0 8px; }
		.wso-row { display:flex; justify-content:space-between; gap:16px; padding:8px 0; border-bottom:1px dashed var(--line); }
		.wso-row:last-child { border-bottom:0; }
		.wso-label { color:var(--muted); font-size:14px; flex:0 0 auto; }
		.wso-value { font-weight:600; text-align:right; word-break:break-word; }
		.wso-amount { display:flex; justify-content:space-between; align-items:center; margin-top:16px; padding:16px; background:#faf6ec; border:1px solid #efe3c2; border-radius:12px; }
		.wso-amount .lbl { font-size:14px; color:var(--muted); }
		.wso-amount .val { font-size:24px; font-weight:800; color:var(--gold); }
		.wso-terms { display:flex; align-items:flex-start; gap:10px; margin:20px 0 8px; font-size:14px; }
		.wso-terms input { margin-top:3px; width:18px; height:18px; }
		.wso-terms a { color:var(--brand); }
		.wso-pay { width:100%; border:0; border-radius:12px; padding:15px 18px; font-size:16px; font-weight:700; color:#fff; background:var(--brand); cursor:pointer; margin-top:12px; }
		.wso-pay:disabled { opacity:.45; cursor:not-allowed; }
		.wso-note { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:10px 14px; font-size:14px; margin-bottom:14px; }
		.wso-paid { text-align:center; padding:26px 0; }
		.wso-paid .tick { font-size:44px; color:var(--ok); }
		.wso-foot { text-align:center; color:var(--muted); font-size:12px; padding:16px 24px 24px; line-height:1.7; }
		.wso-foot a { color:var(--muted); }
	</style>
</head>
<body>
	<div class="wso-wrap">
	<?php if ( ! $row ) : ?>
		<div class="wso-card">
			<div class="wso-head"><h1>Wanaan</h1><div class="wso-sub"><?php esc_html_e( 'Service Order', 'wanaan-service-order' ); ?></div></div>
			<div class="wso-body">
				<p><?php esc_html_e( 'This payment link is not valid or has expired.', 'wanaan-service-order' ); ?></p>
			</div>
		</div>
	<?php else : ?>
		<div class="wso-card">
			<div class="wso-head">
				<h1>Wanaan</h1>
				<div class="wso-sub"><?php esc_html_e( 'Service Order', 'wanaan-service-order' ); ?></div>
			</div>
			<div class="wso-body">

				<?php if ( $notice && isset( $messages[ $notice ] ) ) : ?>
					<div class="wso-note"><?php echo esc_html( $messages[ $notice ] ); ?></div>
				<?php endif; ?>

				<div class="wso-row">
					<span class="wso-label"><?php esc_html_e( 'Confirmation No', 'wanaan-service-order' ); ?></span>
					<span class="wso-value"><?php echo esc_html( $row->confirmation_no ); ?></span>
				</div>
				<?php
				$detail( __( 'Booking No', 'wanaan-service-order' ), $row->booking_no );
				$detail( __( 'Date', 'wanaan-service-order' ), $row->so_date );
				?>

				<div class="wso-section-title"><?php esc_html_e( 'Customer', 'wanaan-service-order' ); ?></div>
				<?php
				$detail( __( 'Customer', 'wanaan-service-order' ), $row->customer_name );
				$detail( __( 'Telephone', 'wanaan-service-order' ), $row->telephone );
				?>

				<div class="wso-section-title"><?php esc_html_e( 'Trip', 'wanaan-service-order' ); ?></div>
				<?php
				$detail( __( 'Service Date', 'wanaan-service-order' ), $row->service_date );
				$detail( __( 'Service Time', 'wanaan-service-order' ), $row->service_time );
				$detail( __( 'Vehicle', 'wanaan-service-order' ), $row->vehicle );
				$detail( __( 'Flight Number', 'wanaan-service-order' ), $row->flight_number );
				$detail( __( 'PAX Name', 'wanaan-service-order' ), $row->pax_name );
				$detail( __( 'PAX Contact', 'wanaan-service-order' ), $row->pax_contact );
				$detail( __( 'Pick up', 'wanaan-service-order' ), $row->pickup );
				$detail( __( 'Drop off', 'wanaan-service-order' ), $row->dropoff );
				$detail( __( 'Remark', 'wanaan-service-order' ), $row->remark );
				?>

				<div class="wso-amount">
					<span class="lbl"><?php esc_html_e( 'Amount due', 'wanaan-service-order' ); ?></span>
					<span class="val"><?php echo esc_html( number_format( (float) $row->amount, 3 ) . ' ' . $row->currency ); ?></span>
				</div>

				<?php if ( 'paid' === $row->status ) : ?>
					<div class="wso-paid">
						<div class="tick">&#10003;</div>
						<p><strong><?php esc_html_e( 'This service order has been paid.', 'wanaan-service-order' ); ?></strong></p>
					</div>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wso-pay-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( WSO_Page::PAY_ACTION ); ?>">
						<input type="hidden" name="token" value="<?php echo esc_attr( $row->token ); ?>">
						<?php wp_nonce_field( WSO_Page::NONCE_ACTION, '_wanaan_nonce' ); ?>

						<label class="wso-terms">
							<input type="checkbox" name="accept_terms" id="wso-accept" value="1">
							<span>
								<?php esc_html_e( 'I read and understand the', 'wanaan-service-order' ); ?>
								<a href="<?php echo esc_url( $terms_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'terms and conditions', 'wanaan-service-order' ); ?></a>.
							</span>
						</label>

						<button type="submit" class="wso-pay" id="wso-pay-btn" disabled>
							<?php esc_html_e( 'Pay now', 'wanaan-service-order' ); ?>
						</button>
					</form>
					<script>
						( function () {
							var cb  = document.getElementById( 'wso-accept' );
							var btn = document.getElementById( 'wso-pay-btn' );
							if ( cb && btn ) {
								cb.addEventListener( 'change', function () { btn.disabled = ! cb.checked; } );
							}
						} )();
					</script>
				<?php endif; ?>

			</div>
			<div class="wso-foot">
				Shop 2082, Road 5669, Block 356, Bahrain<br>
				Tel +973 17474949 &middot; <a href="https://www.wanaan-bh.com" target="_blank" rel="noopener">www.wanaan-bh.com</a>
			</div>
		</div>
	<?php endif; ?>
	</div>
</body>
</html>
<?php
// This page is a full document; nothing else should render after it.
