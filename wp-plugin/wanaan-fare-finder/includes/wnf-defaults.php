<?php
/**
 * The built-in fare table.
 *
 * This is what the plugin ships with and what it falls back to if the ERP has
 * never once answered. It uses exactly the same shape as the ERP's JSON, so
 * nothing downstream has to know or care where the prices came from.
 *
 * Figures confirmed by Qassim, 9 Sep 2026. BHD, per car, driver included.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array
 */
function wnf_builtin_data() {
	return array(
		'version'    => 1,
		'updated_at' => '2026-09-09T00:00:00+03:00',
		'currency'   => 'BHD',
		'source'     => 'builtin',

		/*
		 * pax / bags are shown to the customer as a capacity note under the car,
		 * so they pick the right vehicle instead of phoning to ask whether the
		 * luggage fits. CONFIRM THESE WITH QASSIM — they are sensible figures
		 * for each model, not measured from the actual fleet.
		 */
		'cars'       => array(
			array(
				'id'    => 'sedan',
				'en'    => 'Sedan',
				'ar'    => 'سيدان',
				'model' => 'Ford Taurus 2024',
				'pax'   => 3,
				'bags'  => 2,
			),
			array(
				'id'    => 'suv',
				'en'    => 'SUV',
				'ar'    => 'دفع رباعي',
				'model' => 'Ford Expedition',
				'pax'   => 6,
				'bags'  => 4,
			),
			array(
				'id'    => 'lsuv',
				'en'    => 'Luxury SUV',
				'ar'    => 'دفع رباعي فاخر',
				'model' => 'Mercedes Vito',
				'pax'   => 7,
				'bags'  => 5,
			),
			array(
				'id'    => 'luxury',
				'en'    => 'Luxury',
				'ar'    => 'فاخرة',
				'model' => 'BMW 7 Series 2024',
				'pax'   => 3,
				'bags'  => 3,
			),
			/*
			 * Buses. No 'bags' figure — luggage on a coach depends on the group,
			 * so the capacity note shows seats only rather than inventing a number.
			 * Seat counts confirmed by Qassim, 9 Sep 2026.
			 */
			array(
				'id'    => 'hiace',
				'en'    => 'Toyota Hiace',
				'ar'    => 'تويوتا هايس',
				'model' => 'Toyota Hiace 2023',
				'pax'   => 15,
			),
			array(
				'id'    => 'coaster',
				'en'    => 'Toyota Coaster',
				'ar'    => 'تويوتا كوستر',
				'model' => 'Toyota Coaster 2023',
				'pax'   => 30,
			),
			array(
				'id'    => 'coach',
				'en'    => 'Mercedes Coach',
				'ar'    => 'مرسيدس كوتش',
				'model' => 'Mercedes Coach',
				'pax'   => 50,
			),
			array(
				'id'    => 'sprinter',
				'en'    => 'Sprinter VIP',
				'ar'    => 'مرسيدس سبرينتر VIP',
				'model' => 'Mercedes Sprinter VIP',
				'pax'   => 12,
			),
		),

		'services'   => array(

			/* ---------------------------------------------------------- */
			array(
				'id'      => 'airport',
				'cars'    => array( 'sedan', 'suv', 'lsuv', 'luxury' ),
				'en'      => 'Airport Transfer',
				'ar'      => 'توصيل المطار',
				'ask_en'  => 'Where in Bahrain?',
				'ask_ar'  => 'إلى أي منطقة في البحرين؟',
				'note_en' => 'Fixed fare, driver included. We track your flight — no extra charge if you land late.',
				'note_ar' => 'سعر ثابت شامل السائق. نتابع رحلتك — لا رسوم إضافية عند تأخر الهبوط.',
				'fields'  => array( 'direction', 'date', 'flight' ),
				'directions' => array(
					array( 'v' => 'from', 'en' => 'From the airport', 'ar' => 'من المطار' ),
					array( 'v' => 'to',   'en' => 'To the airport',   'ar' => 'إلى المطار' ),
				),
				'slugs'   => array(
					'sedan'  => 'sedan-car-airport-service',
					'suv'    => 'suv-car-airport-service',
					'lsuv'   => 'luxury-suv-car-airport-service',
					'luxury' => 'luxury-car-airport-service',
				),
				'options' => array(
					array(
						'code'   => 'zone_main',
						// short_* is what fits in the dropdown; en/ar is the full
						// list, shown underneath so nobody has to guess whether
						// their area is included.
						'short_en' => 'Manama, Seef, Juffair and most areas',
						'short_ar' => 'المنامة، السيف، الجفير ومعظم المناطق',
						'en'     => 'Manama · Muharraq · Juffair · Seef · Sitra · Isa Town · Budaiya · Hamad Town',
						'ar'     => 'المنامة · المحرق · الجفير · السيف · سترة · مدينة عيسى · البديع · مدينة حمد',
						'prices' => array(
							'sedan'  => 15,
							'suv'    => 20,
							'lsuv'   => 30,
							'luxury' => 50,
						),
					),
					array(
						'code'   => 'zone_south',
						'en'     => 'Durrat, Askar, Jaww, Alzallaq',
						'ar'     => 'درة البحرين، عسكر، جو، الزلاق',
						'prices' => array(
							'sedan'  => 22,
							'suv'    => 33,
							'lsuv'   => 40,
							'luxury' => 80,
						),
					),
				),
				'offer'   => wnf_empty_offer(),
				/*
				 * The crawlable half of the widget. Where a service has copy the
				 * page shows this instead of a bare fare table: Google gets real
				 * writing about the service, and the fares are still in the text.
				 *
				 * {p:option:car} and {lead} are filled from the live fare table at
				 * render time, so the prose can never drift from what the widget
				 * quotes — change a fare in the ERP and this paragraph follows.
				 */
				'copy_en' => array(
					'title' => 'Airport transfer in Bahrain',
					'intro' => 'Wanaan drives you between Bahrain International Airport and anywhere on the island for a fare agreed before you get in. No meter, no surge, driver included, at any hour of the day or night.',
					'sections' => array(
						array(
							'h' => 'What an airport transfer costs',
							'p' => array(
								'Manama, Muharraq, Juffair, Seef, Sitra, Isa Town, Budaiya and Hamad Town are {p:zone_main:sedan} BHD in a sedan, {p:zone_main:suv} in an SUV, {p:zone_main:lsuv} in a luxury SUV and {p:zone_main:luxury} in a BMW 7 Series.',
								'Durrat Al Bahrain, Askar, Jaww and Alzallaq sit further south and cost {p:zone_south:sedan}, {p:zone_south:suv}, {p:zone_south:lsuv} and {p:zone_south:luxury} BHD. The price covers the car and the driver, and does not change because the road is busy or the flight lands at three in the morning.',
							),
						),
						array(
							'h' => 'We watch your flight',
							'p' => array(
								'Give us your flight number when you book and we follow it. If you land late the driver is there for the flight you actually arrive on, at no extra charge. It is the thing that goes wrong most often with an airport pick-up, and the easiest one to take off the table.',
							),
						),
						array(
							'h' => 'Which car to choose',
							'p' => array(
								'A Ford Taurus seats three with two bags and suits one or two travellers. A Ford Expedition takes six and four bags. A Mercedes Vito carries seven with five bags, which is the one for a family arriving together. The BMW 7 Series seats three and is the car to send when the person landing should be met in something quiet and black.',
							),
						),
						array(
							'h' => 'How to book',
							'p' => array(
								'Pick your area and your car, add the date, your flight number and the landing time, then pay online. Booking through the site needs {lead} hours\' notice; for anything sooner, message us on WhatsApp and we will confirm what is free.',
							),
						),
					),
					'faq_title' => 'Common questions',
					'faq' => array(
						array(
							'q' => 'How much is a taxi from Bahrain airport to Manama?',
							'a' => 'A Wanaan sedan is {p:zone_main:sedan} BHD, fixed, driver included. An SUV is {p:zone_main:suv} BHD and a BMW 7 Series is {p:zone_main:luxury} BHD.',
						),
						array(
							'q' => 'Is the fare fixed, or does it change with traffic?',
							'a' => 'Fixed. You agree the fare before you get in and it does not move for traffic, for the time of day, or because the flight landed at three in the morning.',
						),
						array(
							'q' => 'Do you cover Durrat Al Bahrain and Askar?',
							'a' => 'Yes. Durrat, Askar, Jaww and Alzallaq are {p:zone_south:sedan} BHD in a sedan and {p:zone_south:suv} BHD in an SUV.',
						),
						array(
							'q' => 'Can I book a car for somebody else?',
							'a' => 'Yes. Book in your own name and give us the passenger\'s flight details. The driver goes to that flight.',
						),
						array(
							'q' => 'What does the fare include?',
							'a' => 'The car, the driver and the fuel, one price. We also track your flight, so a late landing costs you nothing extra.',
						),
					),
				),
				'copy_ar' => array(
					'title' => 'توصيل مطار البحرين',
					'intro' => 'ونان تنقلك من مطار البحرين الدولي وإليه بسعر متفق عليه قبل الركوب. بلا عداد، بلا زيادة في أوقات الذروة، شامل السائق، وفي أي ساعة من الليل أو النهار.',
					'sections' => array(
						array(
							'h' => 'كم تكلفة التوصيل من المطار',
							'p' => array(
								'المنامة والمحرق والجفير والسيف وسترة ومدينة عيسى والبديع ومدينة حمد بـ {p:zone_main:sedan} د.ب بسيارة سيدان، و{p:zone_main:suv} بدفع رباعي، و{p:zone_main:lsuv} بدفع رباعي فاخر، و{p:zone_main:luxury} بسيارة BMW الفئة السابعة.',
								'أما درة البحرين وعسكر وجو والزلاق فتقع في الجنوب، وتكلفتها {p:zone_south:sedan} و{p:zone_south:suv} و{p:zone_south:lsuv} و{p:zone_south:luxury} د.ب. السعر يشمل السيارة والسائق، ولا يتغير مهما كان الازدحام أو كانت ساعة الوصول.',
							),
						),
						array(
							'h' => 'نتابع رحلتك',
							'p' => array(
								'أعطنا رقم رحلتك عند الحجز ونتابعها. إن تأخر الهبوط يكون السائق في انتظارك وقت وصولك الفعلي، دون أي رسوم إضافية. هذا أكثر ما يخطئ فيه التوصيل من المطار، وأسهل ما يمكن إزالته.',
							),
						),
						array(
							'h' => 'أي سيارة تختار',
							'p' => array(
								'فورد توروس تتسع لثلاثة ركاب وحقيبتين، وتناسب مسافراً أو اثنين. فورد إكسبديشن تتسع لستة ركاب وأربع حقائب. مرسيدس فيتو تتسع لسبعة ركاب وخمس حقائب، وهي الخيار للعائلة الواصلة معاً. أما BMW الفئة السابعة فتتسع لثلاثة ركاب، وهي السيارة التي ترسلها حين يستحق القادم استقبالاً لائقاً.',
							),
						),
						array(
							'h' => 'كيف تحجز',
							'p' => array(
								'اختر المنطقة والسيارة، ثم أضف التاريخ ورقم الرحلة ووقت الهبوط، وادفع عبر الموقع. الحجز الإلكتروني يحتاج {lead} ساعة مسبقاً؛ ولما هو أقرب راسلنا على واتساب ونؤكد لك المتاح.',
							),
						),
					),
					'faq_title' => 'أسئلة متكررة',
					'faq' => array(
						array(
							'q' => 'كم تكلفة التوصيل من مطار البحرين إلى المنامة؟',
							'a' => 'سيارة سيدان بـ {p:zone_main:sedan} د.ب، سعر ثابت شامل السائق. الدفع الرباعي {p:zone_main:suv} د.ب، وBMW الفئة السابعة {p:zone_main:luxury} د.ب.',
						),
						array(
							'q' => 'هل السعر ثابت أم يتغير حسب الازدحام؟',
							'a' => 'ثابت. تتفق على السعر قبل الركوب ولا يتغير بسبب الازدحام ولا وقت اليوم ولا لأن الرحلة هبطت في الثالثة فجراً.',
						),
						array(
							'q' => 'هل تغطون درة البحرين وعسكر؟',
							'a' => 'نعم. درة البحرين وعسكر وجو والزلاق بـ {p:zone_south:sedan} د.ب بالسيدان و{p:zone_south:suv} د.ب بالدفع الرباعي.',
						),
						array(
							'q' => 'هل أستطيع الحجز لشخص آخر؟',
							'a' => 'نعم. احجز باسمك وأعطنا تفاصيل رحلة المسافر، ويتوجه السائق إلى تلك الرحلة.',
						),
						array(
							'q' => 'ماذا يشمل السعر؟',
							'a' => 'السيارة والسائق والوقود بسعر واحد. ونتابع رحلتك أيضاً، فتأخر الهبوط لا يكلفك شيئاً إضافياً.',
						),
					),
				),
			),

			/* ---------------------------------------------------------- */
			array(
				'id'      => 'chauffeur',
				'cars'    => array( 'sedan', 'suv', 'lsuv', 'luxury' ),
				'en'      => 'Chauffeur in Bahrain',
				'ar'      => 'ليموزين داخل البحرين',
				'ask_en'  => 'How many hours?',
				'ask_ar'  => 'كم عدد الساعات؟',
				'note_en' => 'The car and driver stay with you for the whole block. Fuel included.',
				'note_ar' => 'السيارة والسائق معك طوال المدة. الوقود مشمول.',
				'fields'  => array( 'date', 'time', 'pickup' ),
				// Three blocks read better as buttons than as a dropdown.
				'option_style' => 'pills',
				/*
				 * Charged for every hour past the booked block.
				 * SEEDED FROM THE 4-HOUR RATE — Qassim to confirm the real
				 * figures before this goes live.
				 */
				/*
				 * Empty on purpose. These were 12 / 17 / 19 / 45 — my own figures,
				 * worked out as the hourly rate of each 4-hour block, never
				 * confirmed by Qassim. The widget shows this line to paying
				 * customers, so it stays off until he sets the real rates here or
				 * in Settings -> Fare Finder. An empty list hides the line.
				 */
				'extra_hour'   => array(),
				'slugs'   => array(
					'sedan'  => 'sedan-car-chauffeur-service',
					'suv'    => 'suv-car-chauffeur-service',
					'lsuv'   => 'luxury-suv-car-chauffeur-service',
					'luxury' => 'luxury-car-chauffeur-service',
				),
				'options' => array(
					array(
						'code'   => 'h4',
						'hours'  => 4,
						'en'     => '4 hours',
						'ar'     => '٤ ساعات',
						'prices' => array(
							'sedan'  => 45,
							'suv'    => 65,
							'lsuv'   => 75,
							'luxury' => 180,
						),
					),
					array(
						'code'   => 'h8',
						'hours'  => 8,
						'en'     => '8 hours',
						'ar'     => '٨ ساعات',
						'prices' => array(
							'sedan'  => 90,
							'suv'    => 120,
							'lsuv'   => 140,
							'luxury' => 400,
						),
					),
					array(
						'code'   => 'h12',
						'hours'  => 12,
						'en'     => '12 hours',
						'ar'     => '١٢ ساعة',
						'prices' => array(
							'sedan'  => 120,
							'suv'    => 170,
							'lsuv'   => 180,
							'luxury' => 450,
						),
					),
				),
				'offer'   => wnf_empty_offer(),
				/*
				 * Written for the VIP Chauffeur page. Deliberately does NOT repeat the
				 * five questions already in that page's own accordion (booking, airport
				 * pick-up, fixed prices, hourly service, car types) — Rank Math emits
				 * one FAQPage from those, and a second set would only compete with it.
				 *
				 * No extra-hour figure appears anywhere in this copy. 'extra_hour' is
				 * empty on purpose (see above) and prose must never quote a price the
				 * fare table cannot back.
				 */
				'copy_en' => array(
					'title' => 'Hiring a chauffeur in Bahrain by the hour',
					'intro' => 'A Wanaan chauffeur is a car and a driver booked for a block of hours, not for a single trip. He collects you where you ask, stays with you for the whole block, and drives you wherever the day goes — meetings, the mall, a wedding, four stops or forty. One price, agreed before he arrives.',
					'sections' => array(
						array(
							'h' => 'What a chauffeur costs by the hour',
							'p' => array(
								'Four hours is {p:h4:sedan} BHD in a sedan, {p:h4:suv} in an SUV, {p:h4:lsuv} in a luxury SUV and {p:h4:luxury} in a BMW 7 Series.',
								'Eight hours — a full working day, and the block most people take — is {p:h8:sedan}, {p:h8:suv}, {p:h8:lsuv} and {p:h8:luxury} BHD. Twelve hours is {p:h12:sedan}, {p:h12:suv}, {p:h12:lsuv} and {p:h12:luxury} BHD. Every figure covers the car, the driver and the fuel.',
							),
						),
						array(
							'h' => 'The car waits, and waiting is not charged',
							'p' => array(
								'This is the difference between a chauffeur and a transfer. Between stops the car does not leave. Your bags stay in the boot, the engine stays cool, and the driver is outside when you walk out. An hour spent in a meeting costs exactly what an hour spent driving costs — it is all inside the block you booked.',
								'If a single point-to-point run is all you need, our airport transfer and city-to-city fares are cheaper. The hourly car is for a day with more than one stop in it.',
							),
						),
						array(
							'h' => 'Which car to choose',
							'p' => array(
								'A Ford Taurus seats three and is the everyday business car. A Ford Expedition takes six, which is the one for a group moving together all day. A Mercedes Vito seats seven and is what a family books. The BMW 7 Series seats three and is the car for a wedding, a delegation, or a guest who should arrive in something quiet and black.',
							),
						),
						array(
							'h' => 'How to book',
							'p' => array(
								'Choose your block and your car above, set the date, the start time and the pick-up address, then pay online. Booking through the site needs {lead} hours\' notice; for anything sooner, message us on WhatsApp and we will tell you what is free.',
							),
						),
					),
					'faq_title' => 'Common questions',
					'faq' => array(
						array(
							'q' => 'What is included in the hourly price?',
							'a' => 'The car, the driver and the fuel. Waiting time inside your block costs nothing extra, and there is no charge per kilometre.',
						),
						array(
							'q' => 'Does the driver stay with me between stops?',
							'a' => 'Yes. The car and driver are yours for the whole block. He waits while you are inside and is at the door when you come out.',
						),
						array(
							'q' => 'How many hours should I book?',
							'a' => 'Four hours suits a morning of meetings or an evening out. Eight covers a working day. Twelve is the one to take when the day starts early and ends after dinner.',
						),
						array(
							'q' => 'What if the day runs longer than the block I booked?',
							'a' => 'Tell the driver or message us and we confirm the rate for the extra time before it starts. Nothing is added to your bill without you agreeing to it first.',
						),
						array(
							'q' => 'Can the chauffeur take me across to Saudi Arabia?',
							'a' => 'Not on an hourly block — that one stays inside Bahrain. A run over the King Fahd Causeway is priced per destination on our Bahrain to Saudi Arabia service, causeway fees included.',
						),
					),
				),
				'copy_ar' => array(
					'title' => 'استئجار ليموزين مع سائق بالساعة في البحرين',
					'intro' => 'خدمة الليموزين من ونان هي سيارة وسائق تحجزهما لمدة من الساعات، لا لرحلة واحدة. يأتيك إلى المكان الذي تحدده، ويبقى معك طوال المدة، وينقلك أينما ذهب يومك — اجتماعات، أو مجمّعات، أو عرس، أربع وقفات أو أربعين. سعر واحد متفق عليه قبل وصوله.',
					'sections' => array(
						array(
							'h' => 'كم تكلفة الليموزين بالساعة',
							'p' => array(
								'أربع ساعات بـ {p:h4:sedan} د.ب بسيارة سيدان، و{p:h4:suv} بدفع رباعي، و{p:h4:lsuv} بدفع رباعي فاخر، و{p:h4:luxury} بسيارة BMW الفئة السابعة.',
								'ثماني ساعات — يوم عمل كامل، وهي المدة الأكثر طلباً — بـ {p:h8:sedan} و{p:h8:suv} و{p:h8:lsuv} و{p:h8:luxury} د.ب. واثنتا عشرة ساعة بـ {p:h12:sedan} و{p:h12:suv} و{p:h12:lsuv} و{p:h12:luxury} د.ب. كل سعر يشمل السيارة والسائق والوقود.',
							),
						),
						array(
							'h' => 'السيارة تنتظر، والانتظار غير محسوب',
							'p' => array(
								'هذا هو الفرق بين الليموزين والتوصيلة. بين وقفة وأخرى لا تغادر السيارة. حقائبك تبقى في السيارة، والسائق عند الباب حين تخرج. ساعة تقضيها في اجتماع تكلف ما تكلفه ساعة على الطريق — كلها داخل المدة التي حجزتها.',
								'وإن كنت تحتاج رحلة واحدة من نقطة إلى نقطة فقط، فأسعار توصيل المطار والتنقل بين المدن أقل. الحجز بالساعة ليوم فيه أكثر من وجهة.',
							),
						),
						array(
							'h' => 'أي سيارة تختار',
							'p' => array(
								'فورد توروس تتسع لثلاثة ركاب وهي سيارة الأعمال اليومية. فورد إكسبديشن تتسع لستة، وهي خيار المجموعة التي تتنقل معاً طوال اليوم. مرسيدس فيتو تتسع لسبعة وهي ما تحجزه العائلة. أما BMW الفئة السابعة فتتسع لثلاثة، وهي سيارة العرس والوفود والضيف الذي يستحق وصولاً لائقاً.',
							),
						),
						array(
							'h' => 'كيف تحجز',
							'p' => array(
								'اختر المدة والسيارة في الأعلى، ثم حدد التاريخ ووقت البداية وعنوان الاستلام، وادفع عبر الموقع. الحجز الإلكتروني يحتاج {lead} ساعة مسبقاً؛ ولما هو أقرب راسلنا على واتساب ونخبرك بالمتاح.',
							),
						),
					),
					'faq_title' => 'أسئلة متكررة',
					'faq' => array(
						array(
							'q' => 'ماذا يشمل السعر بالساعة؟',
							'a' => 'السيارة والسائق والوقود. وقت الانتظار داخل المدة لا يكلف شيئاً إضافياً، ولا توجد رسوم على الكيلومترات.',
						),
						array(
							'q' => 'هل يبقى السائق معي بين الوقفات؟',
							'a' => 'نعم. السيارة والسائق لك طوال المدة. ينتظر ريثما تنهي ما عندك ويكون عند الباب حين تخرج.',
						),
						array(
							'q' => 'كم ساعة ينبغي أن أحجز؟',
							'a' => 'أربع ساعات تكفي صباح اجتماعات أو سهرة. وثماني ساعات تغطي يوم عمل كاملاً. واثنتا عشرة ساعة لليوم الذي يبدأ باكراً وينتهي بعد العشاء.',
						),
						array(
							'q' => 'وإن طال اليوم أكثر من المدة المحجوزة؟',
							'a' => 'أخبر السائق أو راسلنا ونؤكد لك سعر الوقت الإضافي قبل أن يبدأ. لا يُضاف شيء إلى فاتورتك دون موافقتك.',
						),
						array(
							'q' => 'هل يمكن للسائق أن يأخذني إلى السعودية؟',
							'a' => 'ليس ضمن الحجز بالساعة — فهو داخل البحرين فقط. الرحلة عبر جسر الملك فهد تُسعّر حسب الوجهة في خدمة البحرين إلى السعودية، ورسوم الجسر مشمولة.',
						),
					),
				),
			),

			/* ---------------------------------------------------------- */
			array(
				'id'      => 'ksa',
				'cars'    => array( 'sedan', 'suv', 'lsuv', 'luxury' ),
				'en'      => 'Bahrain ↔ Saudi Arabia',
				'ar'      => 'البحرين ↔ السعودية',
				'ask_en'  => 'Which destination?',
				'ask_ar'  => 'ما هي الوجهة؟',
				'note_en' => 'Per car, driver included. Causeway fees are included in the price — nothing to pay at the border.',
				'note_ar' => 'لكل سيارة شامل السائق. رسوم الجسر مشمولة في السعر — لا يوجد ما يُدفع على الحدود.',
				'fields'  => array( 'trip', 'direction', 'date', 'time', 'pickup' ),
				'directions' => array(
					array( 'v' => 'bh_ksa', 'en' => 'Bahrain to Saudi', 'ar' => 'من البحرين إلى السعودية' ),
					array( 'v' => 'ksa_bh', 'en' => 'Saudi to Bahrain', 'ar' => 'من السعودية إلى البحرين' ),
				),
				/*
				 * Return = one way x this. 2.0 would be two full one-ways;
				 * 1.8 is a 10% return discount. Confirmed by Qassim.
				 */
				'return_factor' => 1.8,
				'slugs'   => array(
					'sedan'  => 'sedan-car-bh-to-ksa-service',
					'suv'    => 'suv-car-bh-to-ksa-service',
					'lsuv'   => 'luxury-suv-car-bh-to-ksa-service',
					'luxury' => 'luxury-car-bh-to-ksa-service',
				),
				'options' => array(
					array(
						'code'   => 'khobar',
						'en'     => 'Khobar / Dhahran',
						'ar'     => 'الخبر / الظهران',
						'prices' => array(
							'sedan'  => 40,
							'suv'    => 45,
							'lsuv'   => 55,
							'luxury' => 130,
						),
					),
					array(
						'code'   => 'dammam',
						'en'     => 'Dammam',
						'ar'     => 'الدمام',
						'prices' => array(
							'sedan'  => 50,
							'suv'    => 60,
							'lsuv'   => 75,
							'luxury' => 150,
						),
					),
					array(
						'code'   => 'qatif',
						'en'     => 'Qatif',
						'ar'     => 'القطيف',
						'prices' => array(
							'sedan'  => 55,
							'suv'    => 65,
							'lsuv'   => 80,
							'luxury' => 160,
						),
					),
					array(
						'code'   => 'jubail',
						'en'     => 'Jubail / Al-Ahsa',
						'ar'     => 'الجبيل / الأحساء',
						'prices' => array(
							'sedan'  => 60,
							'suv'    => 70,
							'lsuv'   => 85,
							'luxury' => 190,
						),
					),
					array(
						'code'   => 'riyadh',
						'en'     => 'Riyadh / Qatar / Kuwait',
						'ar'     => 'الرياض / قطر / الكويت',
						'prices' => array(
							'sedan'  => 140,
							'suv'    => 160,
							'lsuv'   => 220,
							'luxury' => 450,
						),
					),
				),
				'offer'   => wnf_empty_offer(),
			),

			/* ---------------------------------------------------------- */
			array(
				'id'      => 'city',
				'cars'    => array( 'sedan', 'suv', 'lsuv', 'luxury' ),
				'en'      => 'City to City',
				'ar'      => 'من مدينة إلى مدينة',
				'ask_en'  => 'Where are you going?',
				'ask_ar'  => 'إلى أين أنت ذاهب؟',
				'note_en' => 'One way, fixed fare, driver included. Need the car to wait for you? That is our chauffeur service.',
				'note_ar' => 'اتجاه واحد بسعر ثابت شامل السائق. تحتاج السيارة أن تنتظرك؟ تلك هي خدمة الليموزين بالساعة.',
				// No drop-off box: the zone picked above already says where to.
				// 'cities' prints a line in its place instead.
				'fields'  => array( 'date', 'time', 'pickup', 'cities' ),
				'slugs'   => array(
					'sedan'  => 'sedan-car-city-to-city-service',
					'suv'    => 'suv-car-city-to-city-service',
					'lsuv'   => 'luxury-suv-car-city-to-city-service',
					'luxury' => 'luxury-car-city-to-city-service',
				),
				'options' => array(
					array(
						'code'     => 'zone_main',
						'short_en' => 'Manama, Seef, Juffair and most areas',
						'short_ar' => 'المنامة، السيف، الجفير ومعظم المناطق',
						'en'       => 'Manama · Muharraq · Juffair · Seef · Sitra · Isa Town · Budaiya · Hamad Town',
						'ar'       => 'المنامة · المحرق · الجفير · السيف · سترة · مدينة عيسى · البديع · مدينة حمد',
						'prices'   => array(
							'sedan'  => 15,
							'suv'    => 20,
							'lsuv'   => 30,
							'luxury' => 50,
						),
					),
					array(
						'code'   => 'zone_south',
						'en'     => 'Durrat, Askar, Jaww, Alzallaq',
						'ar'     => 'درة البحرين، عسكر، جو، الزلاق',
						// Matched to the airport far zone. Qassim confirmed the
						// main zone matches airport; he did not name a far-zone
						// figure, so this follows the same rule.
						'prices' => array(
							'sedan'  => 22,
							'suv'    => 33,
							'lsuv'   => 40,
							'luxury' => 80,
						),
					),
				),
				'offer'   => wnf_empty_offer(),
			),
			/* ---------------------------------------------------------- */
			array(
				'id'      => 'bus',
				'cars'    => array( 'hiace', 'coaster', 'coach', 'sprinter' ),
				'en'      => 'Luxury Bus with Driver',
				'ar'      => 'حافلة فاخرة مع سائق',
				'ask_en'  => 'How many hours?',
				'ask_ar'  => 'كم عدد الساعات؟',
				'car_label_en' => 'Choose your bus',
				'car_label_ar' => 'اختر الحافلة',
				'book_en' => 'Book this bus',
				'book_ar' => 'احجز هذه الحافلة',
				'note_en' => 'Driver and fuel included. The bus stays with your group for the whole block.',
				'note_ar' => 'شامل السائق والوقود. الحافلة تبقى مع مجموعتكم طوال المدة.',
				'fields'  => array( 'date', 'time', 'pickup' ),
				'option_style' => 'pills',
				'slugs'   => array(
					'hiace'    => 'hiace-2023-rental',
					'coaster'  => 'coaster-2023',
					'coach'    => 'mercedes-coach',
					'sprinter' => 'mercedes-sprinter-vip',
				),
				'options' => array(
					array(
						'code'   => 'h6',
						'hours'  => 6,
						'en'     => '6 hours',
						'ar'     => '٦ ساعات',
						'prices' => array(
							'hiace'    => 70,
							'coaster'  => 80,
							'coach'    => 160,
							'sprinter' => 180,
						),
					),
					array(
						'code'   => 'h8',
						'hours'  => 8,
						'en'     => '8 hours',
						'ar'     => '٨ ساعات',
						'prices' => array(
							'hiace'    => 90,
							'coaster'  => 100,
							'coach'    => 200,
							'sprinter' => 240,
						),
					),
					array(
						'code'   => 'h12',
						'hours'  => 12,
						'en'     => '12 hours',
						'ar'     => '١٢ ساعة',
						'prices' => array(
							'hiace'    => 120,
							'coaster'  => 140,
							'coach'    => 280,
							'sprinter' => 320,
						),
					),
				),
				'offer'   => wnf_empty_offer(),
			),
			/* ---------------------------------------------------------- */
			/*
			 * Bus to Saudi Arabia.
			 *
			 * ESTIMATED FARES — NOT QASSIM'S PRICES. No bus-to-Saudi figure exists
			 * anywhere on wanaan-bh.com. He asked for working numbers so the service
			 * is complete, to be corrected from the ERP.
			 *
			 * Derived from his own data rather than invented outright:
			 *   base   = each bus's own 12-hour rate (a Khobar round trip is about
			 *            a day's work for the vehicle and driver)
			 *   scale  = the destination multipliers implied by his CAR fares,
			 *            relative to Khobar — Dammam 1.25, Qatif 1.375, Jubail 1.5,
			 *            Riyadh 3.5 — then rounded to clean figures.
			 * So the shape follows how he already prices Saudi runs; only the level
			 * is assumed. Replace every one of these in the ERP before launch.
			 */
			array(
				'id'        => 'ksa_bus',
				'estimated' => true,
				'cars'    => array( 'hiace', 'coaster', 'coach', 'sprinter' ),
				'en'      => 'Bus to Saudi Arabia',
				'ar'      => 'حافلة إلى السعودية',
				'ask_en'  => 'Which destination?',
				'ask_ar'  => 'ما هي الوجهة؟',
				'car_label_en' => 'Choose your bus',
				'car_label_ar' => 'اختر الحافلة',
				'book_en' => 'Book this bus',
				'book_ar' => 'احجز هذه الحافلة',
				'note_en' => 'Per bus, driver included.',
				'note_ar' => 'لكل حافلة شامل السائق.',
				'fields'  => array( 'trip', 'direction', 'date', 'time', 'pickup' ),
				'directions' => array(
					array( 'v' => 'bh_ksa', 'en' => 'Bahrain to Saudi', 'ar' => 'من البحرين إلى السعودية' ),
					array( 'v' => 'ksa_bh', 'en' => 'Saudi to Bahrain', 'ar' => 'من السعودية إلى البحرين' ),
				),
				'return_factor' => 1.8,
				'slugs'   => array(
					'hiace'    => 'hiace-2023-rental',
					'coaster'  => 'coaster-2023',
					'coach'    => 'mercedes-coach',
					'sprinter' => 'mercedes-sprinter-vip',
				),
				'options' => array(
					array(
						'code'   => 'khobar',
						'en'     => 'Khobar / Dhahran',
						'ar'     => 'الخبر / الظهران',
						'prices' => array( 'hiace' => 120, 'coaster' => 140, 'coach' => 280, 'sprinter' => 320 ),
					),
					array(
						'code'   => 'dammam',
						'en'     => 'Dammam',
						'ar'     => 'الدمام',
						'prices' => array( 'hiace' => 150, 'coaster' => 175, 'coach' => 350, 'sprinter' => 400 ),
					),
					array(
						'code'   => 'qatif',
						'en'     => 'Qatif',
						'ar'     => 'القطيف',
						'prices' => array( 'hiace' => 165, 'coaster' => 190, 'coach' => 380, 'sprinter' => 440 ),
					),
					array(
						'code'   => 'jubail',
						'en'     => 'Jubail / Al-Ahsa',
						'ar'     => 'الجبيل / الأحساء',
						'prices' => array( 'hiace' => 180, 'coaster' => 210, 'coach' => 420, 'sprinter' => 480 ),
					),
					array(
						'code'   => 'riyadh',
						'en'     => 'Riyadh / Qatar / Kuwait',
						'ar'     => 'الرياض / قطر / الكويت',
						'prices' => array( 'hiace' => 420, 'coaster' => 490, 'coach' => 980, 'sprinter' => 1120 ),
					),
				),
				'offer'   => wnf_empty_offer(),
			),
		),
	);
}

/**
 * @return array
 */
function wnf_empty_offer() {
	return array(
		'active'   => false,
		'percent'  => 0,
		'label_en' => '',
		'label_ar' => '',
		/*
		 * The window is measured against the DATE OF THE TRIP, not against
		 * today. A National Day offer running 16-24 Sep discounts a trip taken
		 * on the 18th, whether it was booked in August or on the morning it
		 * happens; it does not discount a trip on the 30th just because it was
		 * booked while the offer was on. Both halves of that were wrong before.
		 */
		'starts'   => '',
		'ends'     => '',
		// Car ids the offer applies to. EMPTY MEANS ALL — an offer that names
		// no cars is a whole-service offer, not an offer on nothing.
		'cars'     => array(),
	);
}

/**
 * Plugin settings, with defaults filled in.
 *
 * @return array
 */
function wnf_settings() {
	$saved = get_option( WNF_OPT_SETTINGS, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	return array_merge(
		array(
			'source'    => 'builtin',      // 'builtin' | 'erp'
			// Workspace-scoped. Wanaan is workspace 7 in the ERP, and the id is
			// a PATH segment, not a header — /api/v1/pricing does not exist.
			// The signature covers the path, so this id is signed too.
			'erp_url'   => 'https://erp.wanaan-bh.com/api/v1/workspaces/7/pricing',
			'whatsapp'  => '97317474949',
			'lead_hours' => 12,            // minimum notice before a trip can be booked online
			'deposit_inside'  => 20,      // % paid online for a trip inside Bahrain, the rest in the car (0 = always pay in full)
			'deposit_outside' => 50,      // the same for a trip that leaves Bahrain (Saudi, Qatar, Kuwait)
			'ttl'       => 600,            // seconds a fetched copy counts as fresh
			'booking_product' => 0,        // the one hidden WooCommerce product every booking attaches to
			'overrides'   => array(),      // service => option => car => amount
			'offers'      => array(),      // service => offer array
			'extra_hours' => array(),      // service => car => rate per extra hour
			'return_factors' => array(),   // service => multiplier applied to a return trip
		),
		$saved
	);
}
