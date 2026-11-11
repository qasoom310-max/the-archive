<?php
/**
 * Settings → Fare Finder
 *
 * Two jobs: choose where prices come from, and — while they come from here —
 * let them be edited. When the ERP is the source the fare inputs are shown
 * read-only, because two systems editing the same number is the exact bug this
 * plugin was built to remove.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WNF_Admin {

	const SLUG  = 'wanaan-fare-finder';
	const NONCE = 'wnf_save';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WNF_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Settings</a>';
		return $links;
	}

	public static function menu() {
		add_options_page(
			'Wanaan Fare Finder',
			'Fare Finder',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'page' )
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wanaan-fare-finder' ) );
		}

		$notice = '';
		$error  = '';

		if ( isset( $_POST['wnf_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['wnf_nonce'] ) );

			if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
				$error = 'That form expired. Nothing was saved — please try again.';
			} elseif ( isset( $_POST['wnf_sync'] ) ) {
				$result = WNF_Pricing::refresh_from_erp();
				if ( $result['ok'] ) {
					$notice = 'Synced. ' . $result['message'];
				} else {
					$error = $result['message'];
				}
			} else {
				$notice = self::save();
			}
		}

		$settings = wnf_settings();
		$status   = get_option( WNF_OPT_STATUS, array() );
		$data     = wnf_builtin_data();
		$live     = WNF_Pricing::get_payload();
		$is_erp   = ( 'erp' === $settings['source'] );
		$has_key  = ( '' !== WNF_Signature::secret() );
		$sig_test = WNF_Signature::self_test();

		?>
		<div class="wrap">
			<h1>Wanaan Fare Finder</h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( $is_erp ) : ?>
			<div class="notice notice-warning inline" style="margin:16px 0;padding:12px 14px">
				<p style="margin:0 0 6px"><strong>The ERP owns every value on this page.</strong></p>
				<p style="margin:0">
					Fares, offers, seats, extra-hour rates, return rates, the WhatsApp number
					and the minimum notice are all set in the Wanaan ERP. Nothing here can
					change them — that is the point. Everything below is shown for reference,
					so you can see at a glance whether this site and the ERP agree.
				</p>
			</div>
			<?php endif; ?>

			<div class="notice notice-info inline" style="margin:16px 0;padding:10px 14px">
				<p style="margin:0">
					<strong>Showing customers right now:</strong>
					<?php
					$origin_label = array(
						'erp-fresh' => 'prices from the ERP',
						'erp-stale' => 'prices from the ERP (local copy, refreshing in the background)',
						'builtin'   => 'the fare table on this page',
					);
					$origin = isset( $live['origin'] ) ? $live['origin'] : 'builtin';
					echo esc_html( isset( $origin_label[ $origin ] ) ? $origin_label[ $origin ] : $origin );
					?>
				</p>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE, 'wnf_nonce' ); ?>

				<h2 class="title">Where prices come from</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Pricing source</th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:8px">
									<input type="radio" name="wnf[source]" value="builtin" <?php checked( ! $is_erp ); ?>>
									<strong>Fare table on this page</strong> —
									edit the numbers below. Works with no ERP.
								</label>
								<label style="display:block">
									<input type="radio" name="wnf[source]" value="erp" <?php checked( $is_erp ); ?>>
									<strong>Wanaan ERP</strong> —
									prices are set in the ERP and this site follows them.
								</label>
							</fieldset>
							<p class="description" style="margin-top:10px">
								Switching to the ERP does not risk the site. If the ERP is
								unreachable the widget keeps showing the last prices it received,
								and if it has never answered it falls back to the table below.
								A customer never sees a blank fare.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wnf_erp_url">ERP endpoint</label></th>
						<td>
							<input type="url" id="wnf_erp_url" class="regular-text code" name="wnf[erp_url]"
								value="<?php echo esc_attr( $settings['erp_url'] ); ?>">
							<p class="description">Must be https.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Shared secret</th>
						<td>
							<?php if ( $has_key ) : ?>
								<span style="color:#1a7f37;font-weight:600">Found in wp-config.php.</span>
								<p class="description">
									Read from <code><?php echo esc_html( WNF_Signature::secret_source() ); ?></code>.
								</p>
							<?php else : ?>
								<span style="color:#b32d2e;font-weight:600">Not set.</span>
								<p class="description">
									Add this line to <code>wp-config.php</code>, above
									<code>/* That's all, stop editing! */</code> — using the SAME secret the
									service-order portal already uses. The ERP runs one HMAC scheme with
									one secret; there is no separate key to generate for fares.
								</p>
								<p><code>define( 'WANAAN_PORTAL_SECRET', 'the-existing-portal-secret' );</code></p>
							<?php endif; ?>
							<p class="description">The secret is never stored in the database and never appears on the website.</p>
							<p class="description">
								Signature self-test:
								<?php if ( $sig_test['ok'] ) : ?>
									<span style="color:#1a7f37;font-weight:600">passing</span>
									(<?php echo esc_html( $sig_test['passed'] . '/' . $sig_test['total'] ); ?>
									ERP vectors, workspace binding held)
								<?php else : ?>
									<span style="color:#b32d2e;font-weight:600">FAILING</span> —
									<?php echo esc_html( implode( '; ', $sig_test['failures'] ) ); ?>.
									Do not switch the source to ERP until this passes.
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wnf_whatsapp">WhatsApp number</label></th>
						<td>
							<input type="text" id="wnf_whatsapp" class="regular-text code" name="wnf[whatsapp]"
								value="<?php echo esc_attr( $is_erp && ! empty( $live['whatsapp'] ) ? $live['whatsapp'] : $settings['whatsapp'] ); ?>"
								<?php disabled( $is_erp ); ?>>
							<p class="description">Digits only, with country code. Currently 97317474949.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wnf_lead">Minimum notice</label></th>
						<td>
							<input type="number" id="wnf_lead" min="0" max="168" step="1" style="width:80px"
								name="wnf[lead_hours]"
								value="<?php echo esc_attr( $is_erp && isset( $live['lead_hours'] ) ? $live['lead_hours'] : $settings['lead_hours'] ); ?>"
								<?php disabled( $is_erp ); ?>> hours
							<p class="description">
								A trip sooner than this cannot be booked through the widget — the
								button is disabled and the customer is pointed at WhatsApp instead,
								so an urgent job still reaches your team. Set to 0 to allow any time.
							</p>
							<p class="description">
								Checked twice: in the browser, and again on the server when the
								booking reaches the cart. A hand-edited link cannot get around it.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Pay part now</th>
						<td>
							<label>Inside Bahrain
								<input type="number" min="0" max="99" step="1" style="width:70px"
									name="wnf[deposit_inside]" value="<?php echo esc_attr( $settings['deposit_inside'] ); ?>"> %
							</label>
							&nbsp;&nbsp;
							<label>Leaving Bahrain (Saudi, Qatar, Kuwait)
								<input type="number" min="0" max="99" step="1" style="width:70px"
									name="wnf[deposit_outside]" value="<?php echo esc_attr( $settings['deposit_outside'] ); ?>"> %
							</label>
							<p class="description">
								At checkout the customer must choose: pay this part now by card and
								the rest to the driver when they get in the car, or pay the full fare
								now. 0 = no choice, the full fare is paid online.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Booking</th>
						<td>
							<?php
							$booking = class_exists( 'WNF_Cart' ) && class_exists( 'WooCommerce' )
								? (int) WNF_Cart::product_id()
								: 0;
							if ( $booking ) :
								?>
								<p>
									<strong>Book this car</strong> puts the trip straight into the cart
									and sends the customer to checkout — the fare, the car and the trip
									details already filled in.
								</p>
								<p class="description">
									Every booking attaches to one hidden product,
									<a href="<?php echo esc_url( get_edit_post_link( $booking ) ); ?>">#<?php echo (int) $booking; ?> Wanaan Booking</a>,
									which carries no price of its own. The price on each order is
									calculated here, from the fare table above, at the moment the
									customer books. Nothing is read from the product page, so the
									car product pages no longer set any fare.
								</p>
							<?php else : ?>
								<p class="description" style="color:#b32d2e">
									WooCommerce is not active, so "Book this car" falls back to the old
									product pages and their own prices.
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2 class="title">ERP connection</h2>
				<table class="widefat striped" style="max-width:760px">
					<tbody>
						<tr>
							<td style="width:220px"><strong>Pricing version held</strong></td>
							<td><?php echo isset( $status['version'] ) ? esc_html( $status['version'] ) : '—'; ?></td>
						</tr>
						<tr>
							<td><strong>Last successful sync</strong></td>
							<td><?php echo isset( $status['last_success'] ) ? esc_html( $status['last_success'] ) : 'Never'; ?></td>
						</tr>
						<tr>
							<td><strong>Last attempt</strong></td>
							<td><?php echo isset( $status['last_attempt'] ) ? esc_html( $status['last_attempt'] ) : 'Never'; ?></td>
						</tr>
						<tr>
							<td><strong>Result</strong></td>
							<td><?php echo isset( $status['last_message'] ) ? esc_html( $status['last_message'] ) : '—'; ?></td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" name="wnf_sync" value="1" class="button">Sync now</button>
					<span class="description" style="margin-inline-start:8px">Fetches from the ERP immediately instead of waiting for the hourly check.</span>
				</p>

				<h2 class="title">Fares</h2>
				<?php if ( $is_erp ) : ?>
					<p class="description" style="font-size:13px">
						The ERP is in charge of prices, so these are shown for reference only.
						Change a fare in the ERP and it appears here within the hour — or instantly,
						if the ERP is set up to ping this site on save.
					</p>
				<?php else : ?>
					<p class="description" style="font-size:13px">
						BHD, per car, driver included. Leave a box empty to use the plugin's built-in figure.
					</p>
				<?php endif; ?>

				<?php foreach ( $data['services'] as $service ) : ?>
					<?php
					$sid   = $service['id'];
					$offer = isset( $settings['offers'][ $sid ] )
						? array_merge( wnf_empty_offer(), $settings['offers'][ $sid ] )
						: wnf_empty_offer();

					// Only the vehicles this service actually offers. A bus has no
					// business appearing in the airport grid.
					$svc_cars = $data['cars'];
					if ( ! empty( $service['cars'] ) ) {
						$svc_cars = array_values(
							array_filter(
								$data['cars'],
								function ( $c ) use ( $service ) {
									return in_array( $c['id'], $service['cars'], true );
								}
							)
						);
					}
					?>
					<h3 style="margin-top:26px"><?php echo esc_html( $service['en'] ); ?></h3>
					<?php
					$has_price = false;
					foreach ( $service['options'] as $o ) {
						foreach ( (array) $o['prices'] as $amt ) {
							if ( is_numeric( $amt ) && $amt > 0 ) {
								$has_price = true;
								break 2;
							}
						}
					}
					$switched_off = ( isset( $service['active'] ) && ! $service['active'] );
					?>
					<?php if ( $switched_off || ! $has_price ) : ?>
						<p class="description" style="color:#b32d2e;font-size:13px;margin:0 0 8px">
							<strong>Not shown to customers.</strong>
							Every fare here is still zero, so the widget hides this service entirely
							rather than quoting 0 BHD. Set the real prices in the ERP and it appears
							on its own.
						</p>
					<?php elseif ( ! empty( $service['estimated'] ) ) : ?>
						<p class="description" style="color:#b32d2e;font-size:13px;margin:0 0 8px">
							<strong>These are estimates, not your prices.</strong>
							No fare for this service existed anywhere on the website, so these were
							worked out from your own numbers — each bus's 12-hour rate, scaled by the
							destination multipliers your car fares already use. The shape follows how
							you price Saudi runs; the level is an assumption.
							<strong>Replace every one of them in the ERP before this goes live.</strong>
						</p>
					<?php endif; ?>
					<table class="widefat striped" style="max-width:760px">
						<thead>
							<tr>
								<th>Option</th>
								<?php foreach ( $svc_cars as $car ) : ?>
									<th style="width:110px"><?php echo esc_html( $car['en'] ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $service['options'] as $option ) : ?>
							<tr>
								<td><?php echo esc_html( $option['en'] ); ?></td>
								<?php foreach ( $svc_cars as $car ) : ?>
									<?php
									$cid    = $car['id'];
									$code   = $option['code'];
									$saved  = isset( $settings['overrides'][ $sid ][ $code ][ $cid ] )
										? $settings['overrides'][ $sid ][ $code ][ $cid ]
										: '';
									$builtin = isset( $option['prices'][ $cid ] ) ? $option['prices'][ $cid ] : '';
									?>
									<td>
										<input type="number" step="0.001" min="0" style="width:92px"
											name="wnf[overrides][<?php echo esc_attr( $sid ); ?>][<?php echo esc_attr( $code ); ?>][<?php echo esc_attr( $cid ); ?>]"
											value="<?php echo esc_attr( $saved ); ?>"
											placeholder="<?php echo esc_attr( $builtin ); ?>"
											<?php disabled( $is_erp ); ?>>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<table class="form-table" role="presentation" style="max-width:760px">
						<?php if ( ! empty( $service['extra_hour'] ) ) : ?>
						<tr>
							<th scope="row" style="width:150px">Extra hour</th>
							<td>
								<?php foreach ( $svc_cars as $car ) : ?>
									<?php
									$cid = $car['id'];
									$val = isset( $settings['extra_hours'][ $sid ][ $cid ] )
										? $settings['extra_hours'][ $sid ][ $cid ]
										: '';
									$ph  = isset( $service['extra_hour'][ $cid ] ) ? $service['extra_hour'][ $cid ] : '';
									?>
									<label style="display:inline-block;margin-inline-end:16px;margin-bottom:6px">
										<?php echo esc_html( $car['en'] ); ?>
										<input type="number" step="0.001" min="0" style="width:80px"
											name="wnf[extra_hours][<?php echo esc_attr( $sid ); ?>][<?php echo esc_attr( $cid ); ?>]"
											value="<?php echo esc_attr( $val ); ?>"
											placeholder="<?php echo esc_attr( $ph ); ?>"
											<?php disabled( $is_erp ); ?>>
									</label>
								<?php endforeach; ?>
								<p class="description">
									Charged for every hour past the booked block, and shown on the widget
									so nobody argues about it at the end of the night.
								</p>
								<p class="description" style="color:#b32d2e">
									These were seeded from your own 4-hour rates. They are a guess, not your
									prices — confirm them before this goes live.
								</p>
							</td>
						</tr>
						<?php endif; ?>
						<?php if ( ! empty( $service['return_factor'] ) ) : ?>
						<tr>
							<th scope="row" style="width:150px">Return trip</th>
							<td>
								<?php
								$rf = isset( $settings['return_factors'][ $sid ] )
									? $settings['return_factors'][ $sid ]
									: '';
								?>
								One way &times;
								<input type="number" step="0.01" min="1" max="2" style="width:80px"
									name="wnf[return_factors][<?php echo esc_attr( $sid ); ?>]"
									value="<?php echo esc_attr( $rf ); ?>"
									placeholder="<?php echo esc_attr( $service['return_factor'] ); ?>"
									<?php disabled( $is_erp ); ?>>
								<p class="description">
									2 means a return costs two full one-way trips. 1.8 is a 10% return
									discount, 1.9 is 5%. The widget names the saving to the customer.
								</p>
								<p class="description" style="color:#b32d2e">
									Currently 1.8, which is a placeholder. You said returns are discounted
									but have not given me the rate.
								</p>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th scope="row" style="width:150px">Offer</th>
							<td>
								<label style="margin-inline-end:16px">
									<input type="checkbox" value="1"
										name="wnf[offers][<?php echo esc_attr( $sid ); ?>][active]"
										<?php checked( ! empty( $offer['active'] ) ); ?>
										<?php disabled( $is_erp ); ?>> Running
								</label>
								<input type="number" min="0" max="90" step="1" style="width:70px"
									name="wnf[offers][<?php echo esc_attr( $sid ); ?>][percent]"
									value="<?php echo esc_attr( $offer['percent'] ); ?>"
									<?php disabled( $is_erp ); ?>> %
								<input type="text" class="regular-text" style="width:220px;margin-inline-start:10px"
									name="wnf[offers][<?php echo esc_attr( $sid ); ?>][label_en]"
									value="<?php echo esc_attr( $offer['label_en'] ); ?>"
									placeholder="Badge text, e.g. National Day 25%"
									<?php disabled( $is_erp ); ?>>
								<br>
								<label style="display:inline-block;margin-top:8px">
									From
									<input type="date" name="wnf[offers][<?php echo esc_attr( $sid ); ?>][starts]"
										value="<?php echo esc_attr( $offer['starts'] ); ?>" <?php disabled( $is_erp ); ?>>
								</label>
								<label style="display:inline-block;margin-top:8px;margin-inline-start:10px">
									until
									<input type="date" name="wnf[offers][<?php echo esc_attr( $sid ); ?>][ends]"
										value="<?php echo esc_attr( $offer['ends'] ); ?>" <?php disabled( $is_erp ); ?>>
								</label>
								<p class="description">
									The offer switches itself off after the end date. Nobody has to remember.
								</p>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>

				<?php submit_button( 'Save changes' ); ?>
			</form>

			<h2 class="title">Putting it on a page</h2>
			<p>Paste one of these into an Elementor <em>Shortcode</em> widget, or any WordPress block:</p>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><td><code>[wanaan_fare_finder service="airport"]</code></td><td>Airport transfer</td></tr>
					<tr><td><code>[wanaan_fare_finder service="chauffeur"]</code></td><td>Chauffeur in Bahrain</td></tr>
					<tr><td><code>[wanaan_fare_finder service="ksa"]</code></td><td>Bahrain ↔ Saudi Arabia</td></tr>
					<tr><td><code>[wanaan_fare_finder service="airport" lang="ar"]</code></td><td>Arabic, right-to-left</td></tr>
					<tr><td><code>[wanaan_fare_finder service="airport" theme="light"]</code></td><td>Light background</td></tr>
				</tbody>
			</table>
			<p class="description">
				The stylesheet and script load only on pages that contain the shortcode.
			</p>
		</div>
		<?php
	}

	/**
	 * @return string Notice text.
	 */
	private static function save() {
		$raw = isset( $_POST['wnf'] ) ? wp_unslash( $_POST['wnf'] ) : array();
		if ( ! is_array( $raw ) ) {
			return 'Nothing to save.';
		}

		$settings = wnf_settings();

		$settings['source'] = ( isset( $raw['source'] ) && 'erp' === $raw['source'] ) ? 'erp' : 'builtin';

		if ( isset( $raw['erp_url'] ) ) {
			$settings['erp_url'] = esc_url_raw( trim( (string) $raw['erp_url'] ) );
		}

		// A disabled input posts nothing. Without this guard, saving the page
		// while the ERP is the source would blank the local fallbacks.
		if ( isset( $raw['whatsapp'] ) && '' !== trim( (string) $raw['whatsapp'] ) ) {
			$settings['whatsapp'] = preg_replace( '/\D/', '', (string) $raw['whatsapp'] );
		}

		if ( isset( $raw['lead_hours'] ) && '' !== trim( (string) $raw['lead_hours'] ) ) {
			$settings['lead_hours'] = max( 0, min( 168, (int) $raw['lead_hours'] ) );
		}

		foreach ( array( 'deposit_inside', 'deposit_outside' ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				$settings[ $key ] = max( 0, min( 99, (int) $raw[ $key ] ) );
			}
		}

		$is_erp_now = ( isset( $raw['source'] ) && 'erp' === $raw['source'] );

		$overrides = array();
		if ( isset( $raw['overrides'] ) && is_array( $raw['overrides'] ) ) {
			foreach ( $raw['overrides'] as $sid => $options ) {
				if ( ! is_array( $options ) ) {
					continue;
				}
				$sid = sanitize_key( $sid );
				foreach ( $options as $code => $cars ) {
					if ( ! is_array( $cars ) ) {
						continue;
					}
					$code = sanitize_key( $code );
					foreach ( $cars as $cid => $amount ) {
						$cid    = sanitize_key( $cid );
						$amount = trim( (string) $amount );
						if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount < 0 ) {
							continue;
						}
						$overrides[ $sid ][ $code ][ $cid ] = round( (float) $amount, 3 );
					}
				}
			}
		}
		if ( ! $is_erp_now || $overrides ) {
			$settings['overrides'] = $overrides;
		}

		$extra = array();
		if ( isset( $raw['extra_hours'] ) && is_array( $raw['extra_hours'] ) ) {
			foreach ( $raw['extra_hours'] as $sid => $cars ) {
				if ( ! is_array( $cars ) ) {
					continue;
				}
				$sid = sanitize_key( $sid );
				foreach ( $cars as $cid => $amount ) {
					$cid    = sanitize_key( $cid );
					$amount = trim( (string) $amount );
					if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount < 0 ) {
						continue;
					}
					$extra[ $sid ][ $cid ] = round( (float) $amount, 3 );
				}
			}
		}
		if ( ! $is_erp_now || $extra ) {
			$settings['extra_hours'] = $extra;
		}

		$factors = array();
		if ( isset( $raw['return_factors'] ) && is_array( $raw['return_factors'] ) ) {
			foreach ( $raw['return_factors'] as $sid => $value ) {
				$value = trim( (string) $value );
				if ( '' === $value || ! is_numeric( $value ) ) {
					continue;
				}
				$value = (float) $value;
				if ( $value < 1 || $value > 2 ) {
					continue;
				}
				$factors[ sanitize_key( $sid ) ] = round( $value, 2 );
			}
		}
		if ( ! $is_erp_now || $factors ) {
			$settings['return_factors'] = $factors;
		}

		$offers = array();
		if ( isset( $raw['offers'] ) && is_array( $raw['offers'] ) ) {
			foreach ( $raw['offers'] as $sid => $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				$sid            = sanitize_key( $sid );
				$percent        = isset( $offer['percent'] ) ? (float) $offer['percent'] : 0;
				$offers[ $sid ] = array(
					'active'   => ! empty( $offer['active'] ),
					'percent'  => max( 0, min( 90, $percent ) ),
					'label_en' => isset( $offer['label_en'] ) ? sanitize_text_field( $offer['label_en'] ) : '',
					'label_ar' => isset( $offer['label_ar'] ) ? sanitize_text_field( $offer['label_ar'] ) : '',
					'starts'   => isset( $offer['starts'] ) ? sanitize_text_field( $offer['starts'] ) : '',
					'ends'     => isset( $offer['ends'] ) ? sanitize_text_field( $offer['ends'] ) : '',
				);
			}
		}
		if ( ! $is_erp_now || $offers ) {
			$settings['offers'] = $offers;
		}

		update_option( WNF_OPT_SETTINGS, $settings, false );
		delete_transient( WNF_TR_PRODUCTS );

		return 'Saved.';
	}
}
