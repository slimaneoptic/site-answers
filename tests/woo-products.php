<?php
/**
 * TEST ONLY: five fictional Atlas Home products (and one hidden one) for the WooCommerce index checks.
 */
require_once '/wordpress/wp-load.php';

function sa_woo_cat( $name ) {
	$term = term_exists( $name, 'product_cat' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'product_cat' );
	}
	return (int) $term['term_id'];
}

function sa_woo_attr( $name, $options, $variation = false ) {
	$a = new WC_Product_Attribute();
	$a->set_name( $name );
	$a->set_options( $options );
	$a->set_visible( true );
	$a->set_variation( $variation );
	return $a;
}

function sa_woo_simple( $d ) {
	$p = new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_status( 'publish' );
	$p->set_regular_price( $d['price'] );
	if ( isset( $d['sale'] ) ) {
		$p->set_sale_price( $d['sale'] );
	}
	$p->set_sku( $d['sku'] );
	$p->set_stock_status( isset( $d['stock'] ) ? $d['stock'] : 'instock' );
	$p->set_short_description( $d['short'] );
	$p->set_description( $d['long'] );
	$p->set_category_ids( array( sa_woo_cat( $d['cat'] ) ) );
	$p->set_attributes( $d['attrs'] );
	if ( isset( $d['dims'] ) ) {
		$p->set_length( $d['dims'][0] );
		$p->set_width( $d['dims'][1] );
		$p->set_height( $d['dims'][2] );
	}
	if ( isset( $d['weight'] ) ) {
		$p->set_weight( $d['weight'] );
	}
	if ( isset( $d['visibility'] ) ) {
		$p->set_catalog_visibility( $d['visibility'] );
	}
	return $p->save();
}

update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_dimension_unit', 'cm' );
update_option( 'woocommerce_weight_unit', 'kg' );

sa_woo_simple(
	array(
		'name'   => 'Hand-loomed wool rug',
		'price'  => '189',
		'sale'   => '159',
		'sku'    => 'AH-RUG-0810',
		'cat'    => 'Rugs',
		'short'  => '<p>A flat-woven rug in undyed wool, 8 x 10 ft.</p>',
		'long'   => '<p>Woven by hand on a floor loom. Vacuum with suction only.</p>',
		'attrs'  => array( sa_woo_attr( 'Material', array( 'Wool' ) ), sa_woo_attr( 'Size', array( '8 x 10 ft' ) ) ),
		'dims'   => array( '305', '244', '1' ),
		'weight' => '9',
	)
);
sa_woo_simple(
	array(
		'name'  => 'Stoneware dinner plate',
		'price' => '18',
		'sku'   => 'AH-STN-PL27',
		'cat'   => 'Kitchen and dining',
		'short' => '<p>A matte glazed stoneware plate, 27 cm across.</p>',
		'long'  => '<p>Dishwasher and microwave safe. Oven safe up to 220°C.</p>',
		'attrs' => array( sa_woo_attr( 'Colour', array( 'Sand', 'Charcoal' ) ) ),
	)
);
sa_woo_simple(
	array(
		'name'  => 'Cast iron skillet 26 cm',
		'price' => '64',
		'sku'   => 'AH-CI-26',
		'stock' => 'outofstock',
		'cat'   => 'Kitchen and dining',
		'short' => '<p>A pre-seasoned cast iron skillet that lasts a lifetime.</p>',
		'long'  => '<p>Wash by hand and dry on low heat.</p>',
		'attrs' => array( sa_woo_attr( 'Material', array( 'Cast iron' ) ) ),
	)
);
sa_woo_simple(
	array(
		'name'  => 'Brass table lamp',
		'price' => '129',
		'sku'   => 'AH-LMP-BR',
		'stock' => 'onbackorder',
		'cat'   => 'Lighting',
		'short' => '<p>A solid brass lamp with a linen shade.</p>',
		'long'  => '<p>Takes one E27 bulb (not included).</p>',
		'attrs' => array( sa_woo_attr( 'Finish', array( 'Raw brass' ) ) ),
	)
);
sa_woo_simple(
	array(
		'name'       => 'Staff sample (hidden)',
		'price'      => '1',
		'sku'        => 'AH-HIDDEN',
		'cat'        => 'Rugs',
		'short'      => '<p>Secret sample zanzibarite.</p>',
		'long'       => '<p>Not for sale.</p>',
		'attrs'      => array(),
		'visibility' => 'hidden',
	)
);

// A variable product: a price range and the sizes as a variation attribute.
$duvet = new WC_Product_Variable();
$duvet->set_name( 'Washed linen duvet cover' );
$duvet->set_status( 'publish' );
$duvet->set_sku( 'AH-LIN-DUV' );
$duvet->set_short_description( '<p>Stone-washed linen, soft from the first night.</p>' );
$duvet->set_description( '<p>Wash at 40°C. Gets softer with every wash.</p>' );
$duvet->set_category_ids( array( sa_woo_cat( 'Bedding' ) ) );
$duvet->set_attributes( array( sa_woo_attr( 'Size', array( 'Queen', 'King' ), true ) ) );
$duvet_id = $duvet->save();
foreach ( array( 'Queen' => '149', 'King' => '169' ) as $size => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $duvet_id );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( $price );
	$v->set_stock_status( 'instock' );
	$v->save();
}
WC_Product_Variable::sync( $duvet_id );

Site_Answers_Index::rebuild( 60.0 );
