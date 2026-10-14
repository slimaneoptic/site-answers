<?php
/**
 * Atlas Home demo: a fictional home-goods store for the Site Answers plugin.
 * Runs inside WordPress Playground (blueprint runPHP step) after the plugin is active.
 * All names, people, numbers and addresses below are invented.
 */
require_once '/wordpress/wp-load.php';

function sa_demo_p( $html ) {
	return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
}

function sa_demo_h( $text, $level = 2 ) {
	$attrs = 2 === $level ? '' : ' {"level":' . $level . '}';
	return "<!-- wp:heading$attrs -->\n<h$level class=\"wp-block-heading\">" . $text . "</h$level>\n<!-- /wp:heading -->\n\n";
}

function sa_demo_list( $items ) {
	$html = '';
	foreach ( $items as $item ) {
		$html .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->";
	}
	return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $html . "</ul>\n<!-- /wp:list -->\n\n";
}

function sa_demo_columns( $columns ) {
	$html = '';
	foreach ( $columns as $col ) {
		$html .= "<!-- wp:column -->\n<div class=\"wp-block-column\">" . sa_demo_h( $col[0], 3 ) . sa_demo_p( $col[1] ) . "</div>\n<!-- /wp:column -->\n\n";
	}
	return "<!-- wp:columns -->\n<div class=\"wp-block-columns\">" . $html . "</div>\n<!-- /wp:columns -->\n\n";
}

function sa_demo_link( $slug, $text ) {
	return '<a href="' . esc_url( home_url( '/' . $slug . '/' ) ) . '">' . $text . '</a>';
}

// ---------------------------------------------------------------- Site identity and clean slate.

update_option( 'blogname', 'Atlas Home' );
update_option( 'blogdescription', 'A fictional demo store for the Site Answers plugin' );
update_option( 'permalink_structure', '/%postname%/' );
foreach ( array( 'hello-world', 'sample-page' ) as $slug ) {
	$old = get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) );
	if ( $old ) {
		wp_delete_post( $old->ID, true );
	}
}

// ---------------------------------------------------------------- Pages.

$pages = array();

$pages['home'] = array(
	'Everyday home goods, made to last',
	sa_demo_p( '<strong>Atlas Home is a fictional demo store.</strong> It shows how the Site Answers plugin answers visitors from a site\'s own pages. Try the chat button in the corner: ask about shipping, returns, care or our products.' )
	. sa_demo_columns(
		array(
			array( 'Free shipping over $75', 'Standard shipping in the US is free on orders over $75. ' . sa_demo_link( 'shipping', 'Shipping details' ) . '.' ),
			array( '30-day returns', 'Changed your mind? Send unused items back within 30 days of delivery. ' . sa_demo_link( 'returns-and-refunds', 'How returns work' ) . '.' ),
			array( 'Built to last', 'Furniture and lighting come with a 2-year warranty, cast iron with a lifetime one. ' . sa_demo_link( 'warranty', 'Warranty' ) . '.' ),
		)
	)
	. sa_demo_h( 'What we sell' )
	. sa_demo_list(
		array(
			'<strong>Bedding and linen</strong>: washed linen sheets, duvet covers and pillowcases.',
			'<strong>Rugs and textiles</strong>: hand-loomed wool rugs, cotton throws and cushions.',
			'<strong>Kitchen and dining</strong>: stoneware plates and bowls, cast iron skillets.',
			'<strong>Lighting</strong>: brass table lamps and linen pendant shades.',
			'<strong>Furniture</strong>: solid oak side tables, stools and benches.',
		)
	)
	. sa_demo_p( 'New here? Read ' . sa_demo_link( 'how-to-choose-the-right-rug-size', 'how to choose the right rug size' ) . ' or ' . sa_demo_link( 'how-we-test-every-product', 'how we test every product' ) . '.' ),
	0,
);

$pages['about'] = array(
	'About us',
	sa_demo_p( 'Atlas Home started in 2019, when two friends furnished their first flat and got tired of things that broke within a year. They began selling a few pieces they trusted: a linen duvet cover, a wool rug and a cast iron skillet.' )
	. sa_demo_p( 'Today we are a team of 14 people and we work with 30 small workshops. We visit every workshop before we sell its products, and every product passes our own tests first.' )
	. sa_demo_h( 'What we care about' )
	. sa_demo_list(
		array(
			'Materials that age well: linen, wool, stoneware, cast iron, solid oak and brass.',
			'Fair work: we pay workshops within 15 days of delivery and agree prices a year ahead.',
			'Less waste: our parcels use paper tape and recycled cardboard, with no plastic fill.',
			'One percent of every order goes to a tree-planting fund.',
		)
	)
	. sa_demo_p( 'Atlas Home is fictional: it is a demo store, and so are its team and workshops.' ),
	1,
);

$pages['shipping'] = array(
	'Shipping',
	sa_demo_p( 'Orders placed before 1 pm Eastern Time on a business day ship the same day. Orders placed later ship the next business day. You get an email with a tracking link as soon as your parcel leaves our warehouse.' )
	. sa_demo_h( 'United States' )
	. sa_demo_list(
		array(
			'Standard shipping: 3 to 5 business days, $6.95 per order. Free on orders over $75.',
			'Express shipping: 1 to 2 business days, $14.95 per order.',
			'Large items (rugs of 8 x 10 ft and larger, furniture) travel by freight in 7 to 14 business days, for a flat $49, delivered to your door.',
		)
	)
	. sa_demo_h( 'International' )
	. sa_demo_list(
		array(
			'Canada: 6 to 10 business days, $19 per order.',
			'United Kingdom and European Union: 7 to 12 business days, $29 per order.',
			'Import duties and taxes are not included and are paid on delivery.',
			'Large freight items ship within the United States only.',
		)
	)
	. sa_demo_p( 'We cannot ship large items to P.O. boxes. If your parcel arrives damaged, email us within 7 days with photos and we will send a replacement.' ),
	2,
);

$pages['returns'] = array(
	'Returns and refunds',
	sa_demo_p( 'You can return unused items in their original packaging within 30 days of delivery for a full refund.' )
	. sa_demo_list(
		array(
			'Return shipping is free when an item is faulty or we sent the wrong one.',
			'For other returns, we email you a prepaid label and deduct $7.50 from your refund, or you can send the parcel back yourself.',
			'Large freight items can be picked up from your home for $49 (free if faulty).',
		)
	)
	. sa_demo_h( 'How to start a return' )
	. sa_demo_p( 'Email returns@atlas-home.example with your order number and the items you want to send back. We reply with a return label within one business day.' )
	. sa_demo_h( 'Refunds' )
	. sa_demo_p( 'Refunds go back to your original payment method within 5 business days after the item reaches us and passes a quick check.' )
	. sa_demo_h( 'What cannot be returned' )
	. sa_demo_list(
		array(
			'Personalised items, such as monogrammed linen.',
			'Candles that have been lit.',
			'Clearance items marked "Final sale".',
			'Gift cards.',
		)
	)
	. sa_demo_p( 'Want a different size or colour? The fastest way is to return the item and place a new order.' ),
	3,
);

$pages['warranty'] = array(
	'Warranty',
	sa_demo_p( 'Our products are built to last, and the warranty says so. It starts on the day of delivery.' )
	. sa_demo_list(
		array(
			'Furniture and lighting: 2 years against defects in materials and workmanship.',
			'Rugs, bedding and textiles: 1 year against manufacturing defects, such as split seams or weaving faults.',
			'Cast iron cookware: a lifetime warranty against cracking in normal use.',
		)
	)
	. sa_demo_h( 'What the warranty does not cover' )
	. sa_demo_list(
		array(
			'Normal wear, scratches and the natural patina of brass and oak.',
			'Damage from misuse, pets, sun fading or cleaning that does not follow our care guide.',
			'Light bulbs, which are consumables.',
			'Products bought from other sellers.',
		)
	)
	. sa_demo_h( 'How to make a claim' )
	. sa_demo_p( 'Email warranty@atlas-home.example with your order number, a few photos and a short description of the problem. Once we approve the claim, we repair, replace or refund the item within 10 business days.' ),
	4,
);

$pages['care'] = array(
	'Care guide',
	sa_demo_p( 'A little care keeps our products looking good for years. Here is how to look after each material.' )
	. sa_demo_h( 'Linen bedding' )
	. sa_demo_p( 'Wash at 40°C (104°F) on a gentle cycle with a mild detergent. Do not use bleach. Tumble dry on low heat or line dry. Linen gets softer with every wash; iron it while slightly damp if you like a crisp look.' )
	. sa_demo_h( 'Wool rugs' )
	. sa_demo_p( 'Vacuum once a week with suction only, without the rotating brush (beater bar). Some shedding is normal in the first three months. Turn the rug around every six months so it wears evenly. Blot spills at once with a clean white cloth and cold water; never rub. Have it professionally cleaned every one to two years.' )
	. sa_demo_h( 'Cotton throws and cushion covers' )
	. sa_demo_p( 'Machine wash cold, then reshape and dry flat.' )
	. sa_demo_h( 'Stoneware' )
	. sa_demo_p( 'Dishwasher and microwave safe, and oven safe up to 220°C (430°F). Do not use it on a stovetop or under a broiler, and avoid sudden changes of temperature.' )
	. sa_demo_h( 'Cast iron skillets' )
	. sa_demo_p( 'Wash by hand with hot water and a brush, dry it fully on low heat, then rub in a thin layer of cooking oil. Never put cast iron in the dishwasher.' )
	. sa_demo_h( 'Oak furniture' )
	. sa_demo_p( 'Dust with a dry cloth and wipe spills right away. Re-oil the wood twice a year with the hardwax oil included with your order. Keep it away from radiators and strong direct sun.' )
	. sa_demo_h( 'Brass lamps' )
	. sa_demo_p( 'Wipe with a soft dry cloth. Brass darkens slowly into a natural patina; use a brass cleaner if you prefer it shiny.' ),
	5,
);

$pages['faq'] = array(
	'FAQ',
	sa_demo_h( 'Do I need an account to order?' )
	. sa_demo_p( 'No. You can check out as a guest. An account only saves your addresses and order history.' )
	. sa_demo_h( 'Can I change or cancel my order?' )
	. sa_demo_p( 'Yes, within one hour of placing it: email hello@atlas-home.example with your order number. Once an order is packed we cannot change it, but you can return it.' )
	. sa_demo_h( 'Which payment methods do you accept?' )
	. sa_demo_p( 'Credit and debit cards and the main digital wallets. On orders over $100 you can also pay in 4 interest-free instalments.' )
	. sa_demo_h( 'Do you sell gift cards?' )
	. sa_demo_p( 'Yes. Digital gift cards from $25 to $500 are delivered by email and are valid for 3 years.' )
	. sa_demo_h( 'Can I get fabric samples?' )
	. sa_demo_p( 'Yes. We send up to 5 linen swatches for free, with free shipping in the US (3 to 5 business days).' )
	. sa_demo_h( 'Do you match prices?' )
	. sa_demo_p( 'We do not match prices, but we hold two seasonal sales each year, in January and July. Newsletter subscribers get 10% off their first order.' )
	. sa_demo_h( 'Can I send an order as a gift?' )
	. sa_demo_p( 'Yes. Add a gift note at checkout; the packing slip will not show any prices.' )
	. sa_demo_h( 'Do you have a showroom?' )
	. sa_demo_p( 'No, Atlas Home is online only. Our team answers by email and phone; see the ' . sa_demo_link( 'contact', 'contact page' ) . '.' ),
	6,
);

$pages['contact'] = array(
	'Contact',
	sa_demo_p( 'Our customer care team is here Monday to Friday from 9 am to 6 pm Eastern Time, and on Saturday from 10 am to 2 pm. We are closed on Sundays and public holidays.' )
	. sa_demo_list(
		array(
			'Email: hello@atlas-home.example (we reply within one business day)',
			'Phone: +1 (555) 014-2290',
			'Returns: returns@atlas-home.example',
			'Warranty claims: warranty@atlas-home.example',
		)
	)
	. sa_demo_p( 'Atlas Home is an online store, so we do not receive visitors. Before you write, you can ask the assistant below: it answers from this site\'s pages.' )
	. "<!-- wp:shortcode -->\n[site_answers]\n<!-- /wp:shortcode -->\n\n",
	7,
);

$pages['journal'] = array( 'Journal', '', 8 );

$ids = array();
foreach ( $pages as $key => $page ) {
	$ids[ $key ] = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $page[0],
			'post_name'    => 'returns' === $key ? 'returns-and-refunds' : ( 'care' === $key ? 'care-guide' : $key ),
			'post_content' => $page[1],
			'menu_order'   => $page[2],
		)
	);
}

// ---------------------------------------------------------------- Blog posts.

$cat = wp_insert_term( 'Guides', 'category' );
$cat = is_wp_error( $cat ) ? (int) get_cat_ID( 'Guides' ) : (int) $cat['term_id'];

wp_insert_post(
	array(
		'post_type'     => 'post',
		'post_status'   => 'publish',
		'post_title'    => 'How to choose the right rug size',
		'post_name'     => 'how-to-choose-the-right-rug-size',
		'post_date'     => '2026-09-08 09:00:00',
		'post_category' => array( $cat ),
		'post_content'  =>
			sa_demo_p( 'A rug that is too small makes a room look smaller. These simple rules work in most homes.' )
			. sa_demo_h( 'Living room' )
			. sa_demo_p( 'Put at least the front legs of the sofa and chairs on the rug. A 5 x 8 ft rug suits a small room, 8 x 10 ft fits most living rooms and 9 x 12 ft suits a large one. Leave 30 to 45 cm (12 to 18 in) of bare floor between the rug and the walls.' )
			. sa_demo_h( 'Dining room' )
			. sa_demo_p( 'The rug should reach at least 60 cm (24 in) beyond the table on every side, so the chairs stay on it when you pull them out. For a six-seat table, an 8 x 10 ft rug usually works.' )
			. sa_demo_h( 'Bedroom' )
			. sa_demo_p( 'Under a queen bed, choose an 8 x 10 ft rug and place it under the lower two-thirds of the bed. Under a king bed, choose 9 x 12 ft. Two runners of 2.5 x 8 ft, one on each side, also work well.' )
			. sa_demo_p( 'Tip: mark the outline of the rug on the floor with painter\'s tape before you order.' ),
	)
);

wp_insert_post(
	array(
		'post_type'     => 'post',
		'post_status'   => 'publish',
		'post_title'    => 'How we test every product before it goes on sale',
		'post_name'     => 'how-we-test-every-product',
		'post_date'     => '2026-09-22 09:00:00',
		'post_category' => array( $cat ),
		'post_content'  =>
			sa_demo_p( 'Before a product goes on sale, samples from the workshop go through our own tests. About one sample in four fails and goes back for changes.' )
			. sa_demo_list(
				array(
					'Chairs, stools and benches carry a 150 kg (330 lb) load for 24 hours, then go through 50,000 sit cycles.',
					'Rugs go through a 20,000-step wear test on a rolling drum, and a colour test with water and rubbing.',
					'Linen is washed 50 times at 40°C. It must shrink less than 3% and must not pill.',
					'Stoneware goes through 500 dishwasher cycles and a hot-to-cold temperature test.',
					'Lamp wiring is checked by an independent lab, and switches are clicked 10,000 times.',
				)
			)
			. sa_demo_p( 'A product that fails a test does not go on sale until the workshop fixes it and it passes again.' ),
	)
);

// ---------------------------------------------------------------- Reading settings and navigation.

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $ids['home'] );
update_option( 'page_for_posts', $ids['journal'] );

$links = '';
foreach ( array( 'shipping', 'returns', 'care', 'faq', 'about', 'journal', 'contact' ) as $key ) {
	$links .= '<!-- wp:navigation-link ' . wp_json_encode(
		array(
			'label' => get_the_title( $ids[ $key ] ),
			'type'  => 'page',
			'id'    => $ids[ $key ],
			'url'   => get_permalink( $ids[ $key ] ),
			'kind'  => 'post-type',
		)
	) . ' /-->';
}
wp_insert_post(
	array(
		'post_type'    => 'wp_navigation',
		'post_status'  => 'publish',
		'post_title'   => 'Main menu',
		'post_content' => $links,
	)
);

// ---------------------------------------------------------------- Site Answers settings and index.

update_option(
	'site_answers_settings',
	array(
		'enabled'      => 1,
		'base_url'     => 'https://openrouter.ai/api/v1',
		'model'        => 'google/gemini-3.8-flash',
		'color'        => '#9a4a2f',
		'greeting'     => 'Hi! I am the Atlas Home assistant. Ask me about shipping, returns, care or our products. I answer from this site\'s pages and show my sources.',
		'starters'     => array( 'How much is shipping?', 'What is your return policy?', 'How do I clean a wool rug?' ),
		'contact_page' => $ids['contact'],
		'ip_per_hour'  => 30,
		'daily_cap'    => 300,
		'post_types'   => array( 'post', 'page', 'product' ),
		'demo_mode'    => 1,
	)
);
flush_rewrite_rules();
Site_Answers_Index::rebuild( 60.0 );
