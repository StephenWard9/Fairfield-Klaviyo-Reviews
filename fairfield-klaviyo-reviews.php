<?php
/**
 * Plugin Name: Fairfield Klaviyo Reviews
 * Description: Registers Klaviyo Reviews widgets (hooks + shortcodes) for WooCommerce/Flatsome.
 * Author: Fairfield Nutrition
 * Version: 0.1.2
 */

if (!defined('ABSPATH')) { exit; }

final class FN_Klaviyo_Reviews {
  public function __construct() {
    // PDP auto-placement
    add_action('woocommerce_after_single_product_summary', [$this, 'render_product_reviews_widget'], 15);
    add_action('woocommerce_single_product_summary',      [$this, 'render_product_star_ratings_widget'], 6);

    // Archive / grid placements (Flatsome-friendly)
    add_action('woocommerce_after_shop_loop_item_title',  [$this, 'render_archive_star_ratings_widget'], 12);
    add_action('woocommerce_before_shop_loop_item_title', [$this, 'render_archive_star_ratings_widget'], 12);
    add_action('woocommerce_after_shop_loop_item',        [$this, 'render_archive_star_ratings_widget'], 5);

    // Shortcodes
    add_shortcode('klaviyo-reviews-all',               [$this, 'shortcode_product_reviews_widget']);
    add_shortcode('klaviyo_star_rating',               [$this, 'shortcode_product_star_ratings_widget']);
    add_shortcode('klaviyo-featured-reviews-carousel', [$this, 'shortcode_featured_reviews_widget']);
    add_shortcode('klaviyo-reviews-all-seo',           [$this, 'shortcode_all_reviews_widget']);
    add_shortcode('fulfilled-reviews-all',             [$this, 'shortcode_all_reviews_widget']);

    // CSS + JS
    add_action('wp_enqueue_scripts', [$this, 'enqueue_front_assets'], 20);
  }

  /* ===== PDP renderers ===== */

  public function render_product_reviews_widget() {
    if (!function_exists('is_product') || !is_product()) return;
    global $product; if (!$product) return;
    $product_id = $product->get_id();
    echo "<div id='klaviyo-reviews-all' data-id='" . esc_attr($product_id) . "'></div>";
  }

  public function render_product_star_ratings_widget() {
    if (!function_exists('is_product') || !is_product()) return;
    global $product; if (!$product) return;
    $product_id = $product->get_id();
    echo "<div class='klaviyo-star-rating-widget' data-id='" . esc_attr($product_id) . "'></div>";
  }

  /* ===== Shortcodes ===== */

  public function shortcode_product_reviews_widget($atts = []) {
    global $product; if (!$product) return '';
    $product_id = $product->get_id();
    return "<div id='klaviyo-reviews-all' data-id='" . esc_attr($product_id) . "'></div>";
  }

  public function shortcode_product_star_ratings_widget($atts = []) {
    $atts = shortcode_atts(['product_id' => null], $atts, 'klaviyo_star_rating');
    $product_id = $atts['product_id'];
    if (!$product_id) { global $product; if (!$product) return ''; $product_id = $product->get_id(); }
    return "<div class='klaviyo-star-rating-widget' data-id='" . esc_attr($product_id) . "'></div>";
  }

  public function shortcode_featured_reviews_widget($atts = []) {
    return "<div id='klaviyo-featured-reviews-carousel'></div>";
  }

  public function shortcode_all_reviews_widget($atts = []) {
    return "<div id='klaviyo-reviews-all' data-id='all'></div>";
  }

  /* ===== Archive/grid renderer ===== */

  public function render_archive_star_ratings_widget() {
    $enabled = apply_filters(
      'fn_klaviyo_archive_stars_enabled',
      (bool) get_option('fn_klaviyo_archive_stars_enabled', true)
    );
    if (!$enabled) return;

    if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) return;

    $loop_post_id = get_the_ID();
    if (!$loop_post_id || !function_exists('wc_get_product')) return;
    $wc_product = wc_get_product($loop_post_id);
    if (!$wc_product) return;

    $pid = $wc_product->is_type('variation') ? ($wc_product->get_parent_id() ?: $wc_product->get_id()) : $wc_product->get_id();

    static $printed = [];
    if (isset($printed[$loop_post_id])) return;
    $printed[$loop_post_id] = true;

    echo '<div class="fn-kl-stars">
            <div class="klaviyo-star-rating-widget" data-id="' . esc_attr($pid) . '"></div>
            <span class="fn-kl-stars--placeholder" aria-hidden="true"></span>
          </div>';
  }

  /* ===== Assets ===== */

  public function enqueue_front_assets() {
    // CSS
    $css = "
	  /* Maintain grid alignment */
	  .fn-kl-stars{display:block;min-height:24px;line-height:1;}
	  .fn-kl-stars--placeholder{display:inline-block;visibility:hidden;height:20px;}
	  .fn-kl-stars.has-kl-stars .fn-kl-stars--placeholder{display:none;}
	  .klaviyo-star-rating-widget,.fn-kl-stars--placeholder{margin:.25rem 0 .35rem;}

	  /* Hide Klaviyo's widget when there are zero ratings */
	  .kl_reviews__star_rating_widget[aria-label*='0 ratings'],
	  .kl_reviews__star_rating_widget[aria-label*='0 reviews'],
	  .kl_reviews__star_rating_widget[aria-label^='null stars']{
		visibility: hidden; /* keeps space so cards line up */
	  }
	";

    wp_register_style('fn-kl-reviews-inline', false);
    wp_enqueue_style('fn-kl-reviews-inline');
    wp_add_inline_style('fn-kl-reviews-inline', $css);

    // JS: remove placeholder when Klaviyo stars load
    $js = "(function(){
      function updateBox(box){
        if (box.querySelector('.kl_reviews__star_rating_widget')) {
          box.classList.add('has-kl-stars');
        }
      }
      function init(){
        document.querySelectorAll('.fn-kl-stars').forEach(function(box){
          updateBox(box);
          try {
            var mo=new MutationObserver(function(){updateBox(box);});
            mo.observe(box,{childList:true,subtree:true});
          } catch(e){}
        });
      }
      if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
    })();";
    wp_register_script('fn-kl-reviews-inline', '', [], null, true);
    wp_enqueue_script('fn-kl-reviews-inline');
    wp_add_inline_script('fn-kl-reviews-inline', $js);
  }
}

new FN_Klaviyo_Reviews();
