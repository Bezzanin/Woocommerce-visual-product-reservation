<?php
add_action( 'wp_enqueue_scripts', 'my_enqueue_assets' );

function my_enqueue_assets() {
  wp_enqueue_style( 'parent-style', get_template_directory_uri().'/style.css' ); 
}

add_filter( 'gform_confirmation_anchor', function() {
    return 20;
} );

/**
* Better Pre-submission Confirmation
* http://gravitywiz.com/2012/08/04/better-pre-submission-confirmation/
*/
class GWPreviewConfirmation {

    private static $lead;

    public static function init() {
        add_filter( 'gform_pre_render', array( __class__, 'replace_merge_tags' ) );
    }

    public static function replace_merge_tags( $form ) {

        if( ! class_exists( 'GFFormDisplay' ) ) {
          return $form;
  	    }

        $current_page = isset(GFFormDisplay::$submission[$form['id']]) ? GFFormDisplay::$submission[$form['id']]['page_number'] : 1;
        $fields = array();

        // get all HTML fields on the current page
        foreach($form['fields'] as &$field) {

            // skip all fields on the first page
            if(rgar($field, 'pageNumber') <= 1)
                continue;

            $default_value = rgar($field, 'defaultValue');
            preg_match_all('/{.+}/', $default_value, $matches, PREG_SET_ORDER);
            if(!empty($matches)) {
                // if default value needs to be replaced but is not on current page, wait until on the current page to replace it
                if(rgar($field, 'pageNumber') != $current_page) {
                    $field['defaultValue'] = '';
                } else {
                    $field['defaultValue'] = self::preview_replace_variables($default_value, $form);
                }
            }

            // only run 'content' filter for fields on the current page
            if(rgar($field, 'pageNumber') != $current_page)
                continue;

            $html_content = rgar($field, 'content');
            preg_match_all('/{.+}/', $html_content, $matches, PREG_SET_ORDER);
            if(!empty($matches)) {
                $field['content'] = self::preview_replace_variables($html_content, $form);
            }

        }

        return $form;
    }

    /**
    * Adds special support for file upload, post image and multi input merge tags.
    */
    public static function preview_special_merge_tags($value, $input_id, $merge_tag, $field) {
        
        // added to prevent overriding :noadmin filter (and other filters that remove fields)
        if( ! $value )
            return $value;
        
        $input_type = RGFormsModel::get_input_type($field);
        
        $is_upload_field = in_array( $input_type, array('post_image', 'fileupload') );
        $is_multi_input = is_array( rgar($field, 'inputs') );
        $is_input = intval( $input_id ) != $input_id;
        
        if( !$is_upload_field && !$is_multi_input )
            return $value;

        // if is individual input of multi-input field, return just that input value
        if( $is_input )
            return $value;
            
        $form = RGFormsModel::get_form_meta($field['formId']);
        $lead = self::create_lead($form);
        $currency = GFCommon::get_currency();

        if(is_array(rgar($field, 'inputs'))) {
            $value = RGFormsModel::get_lead_field_value($lead, $field);
            return GFCommon::get_lead_field_display($field, $value, $currency);
        }

        switch($input_type) {
        case 'fileupload':
            $value = self::preview_image_value("input_{$field['id']}", $field, $form, $lead);
            $value = self::preview_image_display($field, $form, $value);
            break;
        default:
            $value = self::preview_image_value("input_{$field['id']}", $field, $form, $lead);
            $value = GFCommon::get_lead_field_display($field, $value, $currency);
            break;
        }

        return $value;
    }

    public static function preview_image_value($input_name, $field, $form, $lead) {

        $field_id = $field['id'];
        $file_info = RGFormsModel::get_temp_filename($form['id'], $input_name);
        $source = RGFormsModel::get_upload_url($form['id']) . "/tmp/" . $file_info["temp_filename"];

        if(!$file_info)
            return '';

        switch(RGFormsModel::get_input_type($field)){

            case "post_image":
                list(,$image_title, $image_caption, $image_description) = explode("|:|", $lead[$field['id']]);
                $value = !empty($source) ? $source . "|:|" . $image_title . "|:|" . $image_caption . "|:|" . $image_description : "";
                break;

            case "fileupload" :
                $value = $source;
                break;

        }

        return $value;
    }

    public static function preview_image_display($field, $form, $value) {

        // need to get the tmp $file_info to retrieve real uploaded filename, otherwise will display ugly tmp name
        $input_name = "input_" . str_replace('.', '_', $field['id']);
        $file_info = RGFormsModel::get_temp_filename($form['id'], $input_name);

        $file_path = $value;
        if(!empty($file_path)){
            $file_path = esc_attr(str_replace(" ", "%20", $file_path));
            $value = "<a href='$file_path' target='_blank' title='" . __("Click to view", "gravityforms") . "'>" . $file_info['uploaded_filename'] . "</a>";
        }
        return $value;

    }

    /**
    * Retrieves $lead object from class if it has already been created; otherwise creates a new $lead object.
    */
    public static function create_lead( $form ) {
        
        if( empty( self::$lead ) ) {
            self::$lead = GFFormsModel::create_lead( $form );
            self::clear_field_value_cache( $form );
        }
        
        return self::$lead;
    }

    public static function preview_replace_variables( $content, $form ) {

        $lead = self::create_lead($form);

        // add filter that will handle getting temporary URLs for file uploads and post image fields (removed below)
        // beware, the RGFormsModel::create_lead() function also triggers the gform_merge_tag_filter at some point and will
        // result in an infinite loop if not called first above
        add_filter('gform_merge_tag_filter', array('GWPreviewConfirmation', 'preview_special_merge_tags'), 10, 4);

        $content = GFCommon::replace_variables($content, $form, $lead, false, false, false);

        // remove filter so this function is not applied after preview functionality is complete
        remove_filter('gform_merge_tag_filter', array('GWPreviewConfirmation', 'preview_special_merge_tags'));

        return $content;
    }
    
    public static function clear_field_value_cache( $form ) {
        
        if( ! class_exists( 'GFCache' ) )
            return;
            
        foreach( $form['fields'] as &$field ) {
            if( GFFormsModel::get_input_type( $field ) == 'total' )
                GFCache::delete( 'GFFormsModel::get_lead_field_value__' . $field['id'] );
        }
        
    }

}

GWPreviewConfirmation::init();

/* Return all products in database encoded into a javascript object. */
function load_product_js() {
    $full_product_list = array();
    $loop = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => -1 ) );

    while ( $loop->have_posts() ) : $loop->the_post();
    $thetitle = get_the_title();
	$theid = get_the_ID();
	$product = new WC_Product($theid);
    $full_product_list[$theid] = array(
	'nr' => $thetitle,
	'size' => $product->get_attribute( 'size' ),
	'price' => $product->get_price_html(),
	'id' => $theid,
    'available' => ($product->get_stock_status() == 'instock'),
    'stockAmount' => $product->get_stock_quantity()
    );
    endwhile; wp_reset_query();
    return json_encode($full_product_list);
}

add_shortcode('productjs', 'load_product_js');


/* Return reservation start and end dates by storage in a js object. */
function load_reserved_js() {
    $orders = wc_get_orders( array('numberposts' => -1) );

    $reservations = array();
    foreach($orders as $o) { 
        $order = wc_get_order($o->get_id());
        foreach( $order->get_items() as $item ) {
            if ($item['qty'] == 0) {
                continue;
            }
            $id = $item->get_product_id();
            $start = implode('.', array_reverse(explode('.', $item['startdate'])));
            $end = implode('.', array_reverse(explode('.', $item['enddate'])));
            if (!isset($reservations[$id])) {
                $reservations[$id] = array('start' => $start, 'end' => $end);
            } else {
                if ($start < $reservations[$id]['start']) {
                    $reservations[$id]['start'] = $start;
                }
                if ($end > $reservations[$id]['end']) {
                    $reservations[$id]['end'] = $end;
                }
            }
        }
    }
    wp_reset_query();
    return json_encode($reservations);
}

add_shortcode('reservedjs', 'load_reserved_js');


/* Load stylesheets and javascript for storage reservation */
function prepare_svgmap() {
    global $woocommerce;
    if ( get_permalink() == 'https://vuokra.fit-technology.fi/varastot/' ) {
        /* Empty cart first */
        $woocommerce->cart->empty_cart();
        wp_enqueue_style( 'jquery-ui-style', get_stylesheet_directory_uri().'/js/jquery-ui.min.css' ); 
        wp_enqueue_style( 'jquery-ui-theme', get_stylesheet_directory_uri().'/js/jquery-ui.theme.min.css' ); 
        wp_enqueue_script( 'jquery-ui', get_stylesheet_directory_uri().'/js/jquery-ui.min.js', array('jquery') );
        wp_enqueue_script( 'datepicker-i18n-fi', get_stylesheet_directory_uri().'/js/datepicker-fi.js', array('jquery-ui') );
        wp_enqueue_style( 'svgmap-style', get_stylesheet_directory_uri().'/svgmap.css' ); 
        wp_enqueue_script( 'svgmap-js', get_stylesheet_directory_uri().'/js/svgmap.js', array('jquery-ui') );
    } else {
        return false;
    }
}

add_action('wp_head', 'prepare_svgmap');


/*
 * Support adding multiple items to cart at the same time.
 */ 
function woocommerce_maybe_add_multiple_products_to_cart( $url = false ) {
    // Make sure WC is installed, and add-to-cart qauery arg exists, and contains at least one comma.
    if ( ! class_exists( 'WC_Form_Handler' ) || empty( $_REQUEST['add-to-cart'] ) || false === strpos( $_REQUEST['add-to-cart'], ',' ) ) {
	return;
    }

    // Remove WooCommerce's hook, as it's useless (doesn't handle multiple products).
    remove_action( 'wp_loaded', array( 'WC_Form_Handler', 'add_to_cart_action' ), 20 );

    $product_ids = explode( ',', $_REQUEST['add-to-cart'] );
    $count       = count( $product_ids );
    $number      = 0;

    foreach ( $product_ids as $id_and_quantity ) {
	// Check for quantities defined in curie notation (<product_id>:<product_quantity>)
	// https://dsgnwrks.pro/snippets/woocommerce-allow-adding-multiple-products-to-the-cart-via-the-add-to-cart-query-string/#comment-12236
	$id_and_quantity = explode( ':', $id_and_quantity );
	$product_id = $id_and_quantity[0];

	$_REQUEST['quantity'] = ! empty( $id_and_quantity[1] ) ? absint( $id_and_quantity[1] ) : 1;

	if ( ++$number === $count ) {
	    // Ok, final item, let's send it back to woocommerce's add_to_cart_action method for handling.
	    $_REQUEST['add-to-cart'] = $product_id;

	    return WC_Form_Handler::add_to_cart_action( $url );
	}

	$product_id        = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $product_id ) );
	$was_added_to_cart = false;
	$adding_to_cart    = wc_get_product( $product_id );

	if ( ! $adding_to_cart ) {
	    continue;
	}

	$add_to_cart_handler = apply_filters( 'woocommerce_add_to_cart_handler', $adding_to_cart->get_type(), $adding_to_cart );

	// Variable product handling
	if ( 'variable' === $add_to_cart_handler ) {
	    woo_hack_invoke_private_method( 'WC_Form_Handler', 'add_to_cart_handler_variable', $product_id );

	// Grouped Products
	} elseif ( 'grouped' === $add_to_cart_handler ) {
	    woo_hack_invoke_private_method( 'WC_Form_Handler', 'add_to_cart_handler_grouped', $product_id );

	// Custom Handler
	} elseif ( has_action( 'woocommerce_add_to_cart_handler_' . $add_to_cart_handler ) ){
	    do_action( 'woocommerce_add_to_cart_handler_' . $add_to_cart_handler, $url );

	// Simple Products
	} else {
	    woo_hack_invoke_private_method( 'WC_Form_Handler', 'add_to_cart_handler_simple', $product_id );
	}
    }
}

// Fire before the WC_Form_Handler::add_to_cart_action callback.
add_action( 'wp_loaded', 'woocommerce_maybe_add_multiple_products_to_cart', 15 );


/**
 * Invoke class private method
 *
 * @since   0.1.0
 *
 * @param   string $class_name
 * @param   string $methodName
 *
 * @return  mixed
 */
function woo_hack_invoke_private_method( $class_name, $methodName ) {
    if ( version_compare( phpversion(), '5.3', '<' ) ) {
	throw new Exception( 'PHP Version Error', __LINE__ );
    }
    
    $args = func_get_args();
    unset( $args[0], $args[1] );
    $reflection = new ReflectionClass( $class_name );
    $method = $reflection->getMethod( $methodName );
    $method->setAccessible( true );

    $args = array_merge( array( $reflection ), $args );
    return call_user_func_array( array( $method, 'invoke' ), $args );
}

add_filter( 'woocommerce_order_item_name', 'remove_permalink_order_table', 10, 3 );

function remove_permalink_order_table( $name, $item, $order ) {
  $name = $item['name'];
  return $name;
}


add_filter('woocommerce_add_cart_item_data','vv_add_item_data',10,2);

function vv_add_item_data($cart_item_data, $product_id) {
    $engraving_text = filter_input( INPUT_POST, 'iconic-engraving' );

    if(isset($_REQUEST['startdate'])) {
        $cart_item_data['startdate'] = sanitize_text_field($_REQUEST['startdate']);
    }

    if(isset($_REQUEST['enddate'])) {
        $cart_item_data['enddate'] = sanitize_text_field($_REQUEST['enddate']);
    }

    return $cart_item_data;
}


add_filter('woocommerce_get_item_data','vv_add_item_meta',10,2);

function vv_add_item_meta($item_data, $cart_item) {
    if(array_key_exists('startdate', $cart_item)) {
        $start_date = $cart_item['startdate'];
        $end_date = $cart_item['enddate'];

        $item_data[] = array(
            'key'   => 'Alkaa',
            'value' => $start_date
        );
        $item_data[] = array(
            'key'   => 'Päättyy',
            'value' => $end_date,
        );
    }
    return $item_data;
}


add_action( 'woocommerce_checkout_create_order_line_item', 'vv_add_dates_to_item_meta', 10, 4 );

function vv_add_dates_to_item_meta($item, $cart_item_key, $values, $order) {
    if(array_key_exists('startdate', $values)) {
        $item->add_meta_data( 'startdate', $values['startdate'] );
        $item->add_meta_data( 'enddate', $values['enddate'] );
    }
}

add_action('woocommerce_cart_calculate_fees' , 'vv_add_custom_fees');

/*
 * Use negative fee for first month discount
 */


function add_drafts_admin_menu_item() {
    // $page_title, $menu_title, $capability, $menu_slug, $callback_function
    add_menu_page(__('Alennus'), __('Alennus'), 'read', 'post.php?post=1918&action=edit');
  }
  add_action('admin_menu', 'add_drafts_admin_menu_item');



//   function vv_add_custom_fees( WC_Cart $cart ){
//     $discount_fee = 0;
//     // Calculate the amount to reduce
//     foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
//         // No discount for locks
//         if ($cart_item['product_id'] == 1700) {
//             continue;
//         }

//         $product = $cart_item['data'];
//         if ( has_term( 'Floor2', 'product_cat', $product->id ) ) {
//             // No discount for single month rentals
//             if((int) $cart_item['quantity'] == 1) {
//                 return;
//             }
//             if((int) $cart_item['quantity'] >=2 & $cart_item['quantity'] <4) {
//                 $discount = ($cart_item['data']->get_price() * 50) / 100;
//                 $discount_fee += $cart_item['quantity'] * $discount;
//             }
//             else {
//             $discount = ($cart_item['data']->get_price() * 50) / 100;
//             $discount_fee += 3 * $discount;
//             }
//         } else { return; }



//     }

//     $discount_text = get_field('discount_text', 1918);
//     $cart->add_fee( $discount_text, -$discount_fee);
// }

add_shortcode('test-content', 'u23_players_shortcode');
function u23_players_shortcode() {
    
    echo '<p>Testing here...</p>';
    $fieldGroup = get_field('kerros_2_options', 1918);
    print_r($fieldGroup['k2_prosentti']);
    
    $floor = get_field('kerros', 1918);
    $fieldGroup1 = get_field('kerros_1_options', 1918);
}

function vv_add_custom_fees( WC_Cart $cart ){
    $discount_fee = 0;
    $discount_text = get_field('discount_text', 1918);
    $floor = get_field('kerros', 1918);
    $min_period = get_field('min_period', 1918);
    $max_period = get_field('max_period', 1918);
    $prosentti = get_field('prosentti', 1918);

    // Calculate the amount to reduce
    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        // No discount for locks
        if ($cart_item['product_id'] == 1700) {
            continue;
        }

        $product = $cart_item['data'];
        
        if ( $floor == 'all') {
            // No discount for single month rentals
            if((int) $cart_item['quantity'] < $min_period) {
                return;
            }
            if((int) $cart_item['quantity'] >= $min_period & $cart_item['quantity'] <= $max_period) {
                $discount = ($cart_item['data']->get_price() * $prosentti) / 100;
                $discount_fee += $cart_item['quantity'] * $discount;
            }
            else {
            $discount = ($cart_item['data']->get_price() * $prosentti) / 100;
            $discount_fee += $max_period * $discount;
            }
        }
        else {
        if ( has_term( 'Floor1', 'product_cat', $product->id ) ) {
            $floor1options = get_field('kerros_1_options', 1918);
            // No discount for single month rentals
            if((int) $cart_item['quantity'] < $floor1options['k1_min_period']) {
                return;
            }
            if((int) $cart_item['quantity'] >= $floor1options['k1_min_period'] & $cart_item['quantity'] <= $floor1options['k1_max_period']) {
                $discount = ($cart_item['data']->get_price() * $floor1options['k1_prosentti']) / 100;
                $discount_fee += $cart_item['quantity'] * $discount;
            }
            else {
            $discount = ($cart_item['data']->get_price() * $floor1options['k1_prosentti']) / 100;
            $discount_fee += $floor1options['k1_max_period'] * $discount;
            }
        } 
        elseif ( has_term( 'Floor2', 'product_cat', $product->id ) ) {
            $floor2options = get_field('kerros_2_options', 1918);
            
            // No discount for single month rentals
            if((int) $cart_item['quantity'] < $floor2options['k2_min_period']) {
                return;
            }
            if((int) $cart_item['quantity'] >= $floor2options['k2_min_period'] & $cart_item['quantity'] <= $floor2options['k2_max_period']) {
                $discount = ($cart_item['data']->get_price() * $floor2options['k2_prosentti']) / 100;
                $discount_fee += $cart_item['quantity'] * $discount;
            }
            else {
            $discount = ($cart_item['data']->get_price() * $floor2options['k2_prosentti']) / 100;
            $discount_fee += $floor2options['k2_max_period'] * $discount;
            }
        }
        elseif ( has_term( 'Merikontti', 'product_cat', $product->id ) ) {
            $merikonttioptions = get_field('merikontti_options', 1918);
            // No discount for single month rentals
            if((int) $cart_item['quantity'] < $merikonttioptions['mk_min_period']) {
                return;
            }
            if((int) $cart_item['quantity'] >= $merikonttioptions['mk_min_period'] & $cart_item['quantity'] <= $merikonttioptions['mk_max_period']) {
                $discount = ($cart_item['data']->get_price() * $merikonttioptions['mk_prosentti']) / 100;
                $discount_fee += $cart_item['quantity'] * $discount;
            }
            else {
            $discount = ($cart_item['data']->get_price() * $merikonttioptions['mk_prosentti']) / 100;
            $discount_fee += $merikonttioptions['mk_max_period'] * $discount;
            }
        }
        else { return; }
    }



    }

    $cart->add_fee( $discount_text, -$discount_fee);
}

add_action('woocommerce_after_order_notes' , 'vv_add_company_vat_checkout_field');

function vv_add_company_vat_checkout_field($checkout) {
    woocommerce_form_field('shipping_method_add_vat', array(
        'type'      => 'checkbox',
        'class'     => array(),
        'label'     => 'Vuokraan yritykselle, lisää ALV 24%',
        'required'  => false,
    ), $checkout->get_value('shipping_method_add_vat'));
}

add_action('woocommerce_checkout_update_order_review' , 'vv_add_company_vat_to_items');

function vv_add_company_vat_to_items($post_data) {
    parse_str( $post_data, $post_data );
 
    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        // Skip lock product
        if ($cart_item['product_id'] == 1700) {
            continue;
        }
        if ( $post_data['shipping_method_add_vat'] == '1' ) {
            $cart_item['data']->set_tax_class('24%');
        } else {
            $cart_item['data']->set_tax_class('');
        }
    }
}


/**
 * @snippet       Add Content to the Customer Processing Order Email - WooCommerce
 * @how-to        Watch tutorial @ https://businessbloomer.com/?p=19055
 * @sourcecode    https://businessbloomer.com/?p=385
 * @author        Rodolfo Melogli
 * @compatible    Woo 3.2.6
 */
 
add_action( 'woocommerce_email_before_order_table', 'bbloomer_add_content_', 20, 4 );
 
function add_content( $order, $sent_to_admin, $plain_text, $email ) {
    if ( $email->id == 'customer_processing_order' ) {
        echo '<h2 class="email-upsell-title">Kiitos</h2>';
    }
}
