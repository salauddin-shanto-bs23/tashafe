<?php
/**
 * WooCommerce AJAX Category Tabs (Centered) with Uniform Product Cards
 */

/* SHORTCODE */
function tashafe_category_tabs_ajax() {

    $categories = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
    ) );

    // Tabs (outside wrapper for full width)
	$output  = '<div class="tashafe-category-tabs-outer">';
	$output .= '<div class="tashafe-category-tabs">';
	$output .= '<a href="#" class="tab active" data-cat-id="0">All</a>';

	foreach ( $categories as $cat ) {
		$output .= '<a href="#" class="tab" data-cat-id="' . esc_attr( $cat->term_id ) . '">' . esc_html( $cat->name ) . '</a>';
	}

	$output .= '</div>';
	$output .= '</div>';

	// Full-width horizontal line
	$output .= '<div class="tashafe-divider"></div>';

	// Wrapper for products
	$output .= '<div class="tashafe-catalogue-wrapper">';

	// Products
	$output .= '<div id="tashafe-category-products" class="tashafe-products">';
	$output .= tashafe_get_products_html(0); // Load all products initially
	$output .= '</div>';
	$output .= '</div>'; // close wrapper


    return $output;
}
add_shortcode( 'tashafe_category_tabs', 'tashafe_category_tabs_ajax' );

/* HELPER: Product HTML */
function tashafe_get_products_html($cat_id = 0) {

    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => 12,
        'post_status'    => 'publish',
    );

    if($cat_id > 0) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $cat_id,
            ),
        );
    }

    $products = new WP_Query($args);
    $html = '<ul class="products">';

    if($products->have_posts()) {
        while($products->have_posts()) {
            $products->the_post();
            global $product;
            $html .= '<li class="product">';
            $html .= '<div class="product-card">';
            $html .= '<a href="' . get_permalink() . '">';
            $html .= woocommerce_get_product_thumbnail();
            $html .= '<h2 class="woocommerce-loop-product__title">' . get_the_title() . '</h2>';
            $html .= '<span class="price">Coming Soon</span>';
            $html .= '</a>';
            $html .= '</div>';
            $html .= '</li>';
        }
    } else {
        $html .= '<li>No products found.</li>';
    }

    wp_reset_postdata();
    $html .= '</ul>';
	
	// Full-width horizontal line
	$html .= '<div class="tashafe-divider"></div>';

    return $html;
}

/* AJAX */
function tashafe_load_products_ajax() {
    $cat_id = intval($_POST['cat_id']);
    echo tashafe_get_products_html($cat_id);
    wp_die();
}
add_action('wp_ajax_tashafe_load_products', 'tashafe_load_products_ajax');
add_action('wp_ajax_nopriv_tashafe_load_products', 'tashafe_load_products_ajax');

/* ASSETS: JS + CSS */
function tashafe_category_tabs_assets() {

    wp_enqueue_script('jquery');

    wp_register_style('tashafe-category-tabs-style', false);
    wp_enqueue_style('tashafe-category-tabs-style');

    wp_add_inline_style('tashafe-category-tabs-style', "
        .tashafe-catalogue-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
        }

        /* Full-width outer container for category tabs */
        .tashafe-category-tabs-outer {
            width: 100vw;
            background: linear-gradient(to right, #6059A6, #DEDBED);
            margin: 0;
            padding: 0;
            position: relative;
            left: 50%;
            transform: translateX(-50%);
        }

        /* RTL support for gradient and positioning */
        [dir='rtl'] .tashafe-category-tabs-outer {
            background: linear-gradient(to left, #6059A6, #DEDBED);
            left: auto;
            right: 50%;
            transform: translateX(50%);
        }

        /* Inner tabs container - centered */
        .tashafe-category-tabs {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            padding: 0;
            margin: 0 auto;
            max-width: 1200px;
            overflow-x: auto;
            overflow-y: hidden;
        }

        .tashafe-category-tabs .tab {
            background: transparent;
            color: #fff;
            padding: 16px 24px;
            border-radius: 0;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tashafe-category-tabs .tab.active {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
        }

        .tashafe-category-tabs .tab:hover {
            opacity: 0.85;
        }

		/* Full-width horizontal divider */
		.tashafe-divider {
			width: 100vw;                /* full viewport width */
			height: 1px;                  /* thickness of line */
			background-color: #DEDBED;    /* updated color */
			margin: 0 0 60px 0;        /*bottom 40px */
			position: relative;
			left: 50%;
			transform: translateX(-50%);
		}

        .tashafe-products {
            width: 100%;
        }

        .tashafe-products ul.products {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            list-style: none;
            padding: 0;
            width: 100%;
        }

        .tashafe-products ul.products li.product {
            width: 100%;
        }

        /* Tablet: 2 products per row */
        @media (max-width: 768px) {
            .tashafe-products ul.products {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Mobile: 1 product per row */
        @media (max-width: 480px) {
            .tashafe-products ul.products {
                grid-template-columns: 1fr;
            }
        }

        .product-card {
            border: 1px solid #ddd;
            padding: 16px;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s ease;
            background-color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            min-height: 300px; /* ensures uniform base height */
        }

        .product-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .product-card img {
            max-width: 100%;
            height: auto;
            margin-bottom: 8px;
        }

        .product-card .woocommerce-loop-product__title {
            font-size: 13px;
            font-weight: 600;
            margin: 8px 0 4px;
            min-height: 36px; /* fixed space for 1-2 lines */
            overflow: hidden;
        }

        .product-card .price {
            color: #333;
            font-weight: 500;
            margin-top: auto; /* keeps price at bottom */
        }

        .tashafe-products .loading {
            text-align: center;
            padding: 40px 0;
            font-weight: 600;
        }

        /* Tablet: Keep tabs centered but allow scrolling if needed */
        @media (max-width: 768px) {
            .tashafe-category-tabs {
                -webkit-overflow-scrolling: touch;
                scroll-behavior: smooth;
            }
        }

        /* Mobile: Left-align tabs for better scrolling experience */
        @media (max-width: 480px) {
            .tashafe-category-tabs {
                justify-content: flex-start;
                padding: 0 16px;
            }
            
            .tashafe-category-tabs .tab {
                padding: 12px 16px;
                font-size: 14px;
            }
        }
    ");

    wp_add_inline_script('jquery', "
        jQuery(document).ready(function($){
            $('.tashafe-category-tabs .tab').on('click', function(e){
                e.preventDefault();
                var cat_id = $(this).data('cat-id');
                $('.tashafe-category-tabs .tab').removeClass('active');
                $(this).addClass('active');
                $('#tashafe-category-products').html('<p class=\"loading\">Loading...</p>');
                $.post('".admin_url('admin-ajax.php')."', {
                    action: 'tashafe_load_products',
                    cat_id: cat_id
                }, function(response){
                    $('#tashafe-category-products').html(response);
                });
            });
        });
    ");
}
add_action('wp_enqueue_scripts', 'tashafe_category_tabs_assets');
