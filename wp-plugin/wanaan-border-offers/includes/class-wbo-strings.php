<?php
/**
 * Every word the search box shows, in English and Arabic.
 *
 * No WordPress calls in this file, so it can be tested on its own.
 */

defined( 'ABSPATH' ) || exit;

final class WBO_Strings {

	/** @return array<string,string> */
	public static function all( $lang ) {
		return 'ar' === $lang ? self::arabic() : self::english();
	}

	/** @return array<string,string> */
	public static function english() {
		return array(
			'title'           => 'Bahrain ↔ Saudi rides',
			'round_trip'      => 'Round trip',
			'one_way'         => 'One way',
			'passengers'      => 'Passengers',
			'passenger_one'   => '1 passenger',
			'passenger_many'  => ':n passengers',
			'pickup_country'  => 'Pick-up country',
			'dropoff_country' => 'Drop-off country',
			'swap'            => 'Swap pick-up and drop-off',
			'departure'       => 'Departure',
			'return'          => 'Return',
			'prev_day'        => 'Previous day',
			'next_day'        => 'Next day',
			'search'          => 'Search',
			'searching'       => 'Searching…',
			'outbound'        => 'Going',
			'return_leg'      => 'Coming back',
			'pick_hour'       => 'Pick a departure time',
			'off'             => ':p% off',
			'cars_left'       => ':n cars',
			'legend_offer'    => 'Empty car going your way — :p% off',
			'legend_full'     => 'Full fare',
			'legend_closed'   => 'Too soon to book online',
			'no_offer_day'    => 'No empty car on this day. You can still book at the full fare.',
			'next_offers'     => 'Next empty cars this way',
			'nudge'           => 'Leave at :time instead and save :p%.',
			'nudge_btn'       => 'Leave at :time',
			'offer_explain'   => 'One of our cars is driving this way empty. Ride with it and pay :p% less.',
			'same_country'    => 'Choose two different countries.',
			'need_return'     => 'The return must be on or after the departure day.',
			'error_busy'      => 'Too many searches — try again in a minute.',
			'error_down'      => 'Live offers are not available right now. You can still book with us on WhatsApp.',
			'summary'         => 'Your trip',
			'book'            => 'Book on WhatsApp',
			'choose_hours'    => 'Choose a time for each way to book.',
			'choose_hour'     => 'Choose a time to book.',
			'full_fare'       => 'Full fare',
			'msg_hello'       => 'Hello Wanaan, I would like to book a ride:',
			'msg_offer'       => 'empty car offer, :p% off',
			'msg_full'        => 'full fare',
			'msg_return'      => 'Return',
			'msg_passengers'  => 'Passengers',
			'at'              => 'at',
		);
	}

	/** @return array<string,string> */
	public static function arabic() {
		return array(
			'title'           => 'رحلات البحرين ↔ السعودية',
			'round_trip'      => 'ذهاب وعودة',
			'one_way'         => 'ذهاب فقط',
			'passengers'      => 'الركاب',
			'passenger_one'   => 'راكب واحد',
			'passenger_many'  => ':n ركاب',
			'pickup_country'  => 'دولة الانطلاق',
			'dropoff_country' => 'دولة الوصول',
			'swap'            => 'تبديل الانطلاق والوصول',
			'departure'       => 'الذهاب',
			'return'          => 'العودة',
			'prev_day'        => 'اليوم السابق',
			'next_day'        => 'اليوم التالي',
			'search'          => 'بحث',
			'searching'       => 'جارٍ البحث…',
			'outbound'        => 'الذهاب',
			'return_leg'      => 'العودة',
			'pick_hour'       => 'اختر وقت الانطلاق',
			'off'             => 'خصم :p%',
			'cars_left'       => ':n سيارات',
			'legend_offer'    => 'سيارة فارغة في طريقك — خصم :p%',
			'legend_full'     => 'السعر الكامل',
			'legend_closed'   => 'وقت قريب جداً للحجز عبر الموقع',
			'no_offer_day'    => 'لا توجد سيارة فارغة في هذا اليوم. يمكنك الحجز بالسعر الكامل.',
			'next_offers'     => 'أقرب السيارات الفارغة في هذا الاتجاه',
			'nudge'           => 'انطلق الساعة :time ووفّر :p%.',
			'nudge_btn'       => 'الانطلاق :time',
			'offer_explain'   => 'إحدى سياراتنا متجهة في طريقك وهي فارغة. اركب معها وادفع أقل بـ :p%.',
			'same_country'    => 'اختر دولتين مختلفتين.',
			'need_return'     => 'يجب أن يكون يوم العودة في يوم الذهاب أو بعده.',
			'error_busy'      => 'عمليات بحث كثيرة — حاول بعد دقيقة.',
			'error_down'      => 'العروض المباشرة غير متاحة الآن. يمكنك الحجز معنا عبر واتساب.',
			'summary'         => 'رحلتك',
			'book'            => 'احجز عبر واتساب',
			'choose_hours'    => 'اختر وقتاً للذهاب والعودة للحجز.',
			'choose_hour'     => 'اختر وقتاً للحجز.',
			'full_fare'       => 'السعر الكامل',
			'msg_hello'       => 'مرحباً ونان، أرغب بحجز رحلة:',
			'msg_offer'       => 'عرض السيارة الفارغة، خصم :p%',
			'msg_full'        => 'السعر الكامل',
			'msg_return'      => 'العودة',
			'msg_passengers'  => 'عدد الركاب',
			'at'              => 'الساعة',
		);
	}

	/** @return array<int,array{code:string,name:string}> */
	public static function countries( $lang ) {
		$names = array(
			'BH' => array( 'Bahrain', 'البحرين' ),
			'SA' => array( 'Saudi Arabia', 'السعودية' ),
		);

		$list = array();
		foreach ( WBO_Query::COUNTRIES as $code ) {
			$list[] = array(
				'code' => $code,
				'name' => 'ar' === $lang ? $names[ $code ][1] : $names[ $code ][0],
			);
		}

		return $list;
	}
}
