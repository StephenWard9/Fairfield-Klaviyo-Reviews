<?php
/**
 * Plugin Name: Fairfield Klaviyo Reviews
 * Description: Registers Klaviyo Reviews widgets (hooks + shortcodes) for WooCommerce/Flatsome.
 * Author: Fairfield Nutrition
 * Version: 0.2.0
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

    // AJAX endpoint for saving captured ratings
    add_action('wp_ajax_fn_klaviyo_save_rating', [$this, 'ajax_save_rating']);
    add_action('wp_ajax_nopriv_fn_klaviyo_save_rating', [$this, 'ajax_save_rating']);

    // Schema injection - Use Rank Math's Product-specific filter
    add_filter('rank_math/snippet/rich_snippet_product_entity', [$this, 'inject_into_product_entity'], 10);

    // Daily cleanup of stale ratings
    add_action('fn_klaviyo_cleanup_stale_ratings', [$this, 'cleanup_stale_ratings']);
    if (!wp_next_scheduled('fn_klaviyo_cleanup_stale_ratings')) {
      wp_schedule_event(time(), 'daily', 'fn_klaviyo_cleanup_stale_ratings');
    }
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

    // JS: Capture and save ratings to product meta for Google schema
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('fn_klaviyo_rating');

    $capture_js = "(function(){
        var AJAX_URL = '" . esc_js($ajax_url) . "';
        var NONCE = '" . esc_js($nonce) . "';

        function parseStarsAndCount(widget){
          var btn = widget.querySelector('button.kl_reviews__star_rating_widget[aria-label]');
          if(!btn) return null;

          var aria = btn.getAttribute('aria-label') || '';
          // Match: '5 stars, 4 ratings' or '5 star, 1 review' etc.
          var m = aria.match(/([0-5](?:\\.[0-9])?)\\s*stars?\\s*,\\s*(\\d+)\\s*(?:rating|review)s?/i);
          if(!m) return null;

          var ratingValue = parseFloat(m[1]);
          var ratingCount = parseInt(m[2], 10);

          if(!(ratingValue > 0) || !(ratingCount > 0)) return null;
          return { ratingValue: ratingValue, ratingCount: ratingCount };
        }

        function saveIfFound(productId){
          var holder = document.querySelector('.klaviyo-star-rating-widget[data-id=\"' + productId + '\"]');
          if(!holder) return;

          var data = parseStarsAndCount(holder);
          if(!data) return;

          if(window.__fnKlaviyoSent) return;
          window.__fnKlaviyoSent = true;

          var body = new URLSearchParams();
          body.set('action', 'fn_klaviyo_save_rating');
          body.set('product_id', productId);
          body.set('rating_value', data.ratingValue);
          body.set('rating_count', data.ratingCount);
          body.set('nonce', NONCE);

          fetch(AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
          }).catch(function(err){
            if(window.console) console.debug('Klaviyo rating save failed:', err);
          });
        }

        function init(){
          var el = document.querySelector('.klaviyo-star-rating-widget[data-id]');
          if(!el) return;
          var pid = el.getAttribute('data-id');
          if(!pid) return;

          saveIfFound(pid);

          try{
            var mo = new MutationObserver(function(){ saveIfFound(pid); });
            mo.observe(el, {childList:true, subtree:true});
            setTimeout(function(){ try{mo.disconnect();}catch(e){} }, 30000);
          }catch(e){}
        }

        if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
      })();";

    wp_add_inline_script('fn-kl-reviews-inline', $capture_js);
  }

  /* ===== AJAX handler: Save captured ratings ===== */

  public function ajax_save_rating() {
    // Validate required params
    if (!isset($_POST['product_id'], $_POST['rating_value'], $_POST['rating_count'], $_POST['nonce'])) {
      wp_send_json_error(['message' => 'Missing params'], 400);
    }

    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'fn_klaviyo_rating')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $product_id   = absint($_POST['product_id']);
    $rating_value = (float) $_POST['rating_value'];
    $rating_count = (int) $_POST['rating_count'];

    // Validate values
    if ($product_id <= 0 || $rating_value <= 0 || $rating_value > 5 || $rating_count <= 0) {
      wp_send_json_error(['message' => 'Invalid values'], 400);
    }

    // Only allow saving on published products
    if (get_post_status($product_id) !== 'publish') {
      wp_send_json_error(['message' => 'Not published'], 400);
    }

    // Rate limiting: 1 update per product per IP per minute
    $rate_key = 'fn_klaviyo_rate_' . $product_id . '_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (get_transient($rate_key)) {
      wp_send_json_error(['message' => 'Rate limited'], 429);
    }
    set_transient($rate_key, 1, 60);

    // Save only if changed (avoid DB churn)
    $old_value = get_post_meta($product_id, '_fn_klaviyo_rating_value', true);
    $old_count = get_post_meta($product_id, '_fn_klaviyo_rating_count', true);

    if ($old_value != $rating_value) {
      update_post_meta($product_id, '_fn_klaviyo_rating_value', $rating_value);
    }
    if ($old_count != $rating_count) {
      update_post_meta($product_id, '_fn_klaviyo_rating_count', $rating_count);
    }

    // Always update timestamp when we get fresh data
    update_post_meta($product_id, '_fn_klaviyo_rating_timestamp', time());

    // Debug logging
    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log(sprintf(
        'Klaviyo rating saved: Product %d = %.1f stars, %d reviews',
        $product_id, $rating_value, $rating_count
      ));
    }

    wp_send_json_success(['saved' => true]);
  }

  /* ===== Inject into Rank Math Product entity ===== */

  public function inject_into_product_entity($entity) {
    global $post;
    if (!$post) return $entity;

    $product_id = $post->ID;

    // Check for manual override first
    $rating_value = get_post_meta($product_id, '_fn_klaviyo_manual_rating_value', true);
    $rating_count = get_post_meta($product_id, '_fn_klaviyo_manual_rating_count', true);

    if (!$rating_value || !$rating_count) {
      // Fall back to auto-captured values
      $rating_value = get_post_meta($product_id, '_fn_klaviyo_rating_value', true);
      $rating_count = get_post_meta($product_id, '_fn_klaviyo_rating_count', true);
      $timestamp = get_post_meta($product_id, '_fn_klaviyo_rating_timestamp', true);

      // Don't show stale data (older than 14 days)
      if ($timestamp && (time() - $timestamp) > 14 * DAY_IN_SECONDS) {
        return $entity;
      }
    }

    $rating_value = is_numeric($rating_value) ? (float) $rating_value : 0;
    $rating_count = is_numeric($rating_count) ? (int) $rating_count : 0;

    // If no valid rating data, return early
    if ($rating_value <= 0 || $rating_count <= 0) return $entity;

    // Inject aggregateRating into the Product entity
    $entity['aggregateRating'] = [
      '@type' => 'AggregateRating',
      'ratingValue' => sprintf('%.1f', $rating_value),
      'ratingCount' => (string) $rating_count,
      'reviewCount' => (string) $rating_count,
    ];

    // Add merchant return policy (adjust values to match your actual policy)
    $entity['hasMerchantReturnPolicy'] = [
      '@type' => 'MerchantReturnPolicy',
      'applicableCountry' => 'AU',
      'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
      'merchantReturnDays' => 30,
      'returnMethod' => 'https://schema.org/ReturnByMail',
      'returnFees' => 'https://schema.org/FreeReturn'
    ];

    return $entity;
  }

  /* ===== Cleanup stale ratings (daily cron) ===== */

  public function cleanup_stale_ratings() {
    global $wpdb;

    // Delete auto-captured ratings older than 30 days
    // (Manual ratings are never deleted)
    $cutoff = time() - (30 * DAY_IN_SECONDS);

    $old_timestamps = $wpdb->get_col($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->postmeta}
       WHERE meta_key = '_fn_klaviyo_rating_timestamp'
       AND meta_value < %d",
      $cutoff
    ));

    foreach ($old_timestamps as $post_id) {
      // Only delete if there's no manual override
      $has_manual = get_post_meta($post_id, '_fn_klaviyo_manual_rating_value', true);
      if (!$has_manual) {
        delete_post_meta($post_id, '_fn_klaviyo_rating_value');
        delete_post_meta($post_id, '_fn_klaviyo_rating_count');
        delete_post_meta($post_id, '_fn_klaviyo_rating_timestamp');
      }
    }
  }
}

new FN_Klaviyo_Reviews();

/* ===== WP-CLI Commands ===== */

if (defined('WP_CLI') && WP_CLI) {
  class FN_Klaviyo_CLI {
    /**
     * Set manual rating for a product (bypasses auto-capture).
     *
     * ## OPTIONS
     *
     * <product_id>
     * : The product ID
     *
     * <rating_value>
     * : Rating value (0.0 to 5.0)
     *
     * <rating_count>
     * : Number of ratings/reviews
     *
     * ## EXAMPLES
     *
     *     wp klaviyo-rating set 13052 5.0 4
     *
     * @when after_wp_load
     */
    public function set($args, $assoc_args) {
      list($product_id, $rating_value, $rating_count) = $args;

      $product_id = absint($product_id);
      $rating_value = (float) $rating_value;
      $rating_count = (int) $rating_count;

      if ($product_id <= 0 || $rating_value <= 0 || $rating_value > 5 || $rating_count <= 0) {
        WP_CLI::error('Invalid values. Rating must be 0-5, count must be positive.');
      }

      if (get_post_status($product_id) !== 'publish') {
        WP_CLI::error("Product $product_id not found or not published.");
      }

      update_post_meta($product_id, '_fn_klaviyo_manual_rating_value', $rating_value);
      update_post_meta($product_id, '_fn_klaviyo_manual_rating_count', $rating_count);

      WP_CLI::success(sprintf(
        'Set manual rating for product %d: %.1f stars, %d reviews',
        $product_id, $rating_value, $rating_count
      ));
    }

    /**
     * Clear manual rating for a product (reverts to auto-capture).
     *
     * ## OPTIONS
     *
     * <product_id>
     * : The product ID
     *
     * ## EXAMPLES
     *
     *     wp klaviyo-rating clear 13052
     *
     * @when after_wp_load
     */
    public function clear($args, $assoc_args) {
      $product_id = absint($args[0]);

      if ($product_id <= 0) {
        WP_CLI::error('Invalid product ID.');
      }

      delete_post_meta($product_id, '_fn_klaviyo_manual_rating_value');
      delete_post_meta($product_id, '_fn_klaviyo_manual_rating_count');

      WP_CLI::success("Cleared manual rating for product $product_id. Will use auto-capture.");
    }

    /**
     * Show current rating for a product.
     *
     * ## OPTIONS
     *
     * <product_id>
     * : The product ID
     *
     * ## EXAMPLES
     *
     *     wp klaviyo-rating get 13052
     *
     * @when after_wp_load
     */
    public function get($args, $assoc_args) {
      $product_id = absint($args[0]);

      if ($product_id <= 0) {
        WP_CLI::error('Invalid product ID.');
      }

      $manual_value = get_post_meta($product_id, '_fn_klaviyo_manual_rating_value', true);
      $manual_count = get_post_meta($product_id, '_fn_klaviyo_manual_rating_count', true);
      $auto_value = get_post_meta($product_id, '_fn_klaviyo_rating_value', true);
      $auto_count = get_post_meta($product_id, '_fn_klaviyo_rating_count', true);
      $timestamp = get_post_meta($product_id, '_fn_klaviyo_rating_timestamp', true);

      if ($manual_value && $manual_count) {
        WP_CLI::line(sprintf(
          'Product %d: %.1f stars, %d reviews (MANUAL)',
          $product_id, (float) $manual_value, (int) $manual_count
        ));
      } elseif ($auto_value && $auto_count) {
        $age = $timestamp ? human_time_diff($timestamp) . ' ago' : 'unknown';
        WP_CLI::line(sprintf(
          'Product %d: %.1f stars, %d reviews (auto-captured %s)',
          $product_id, (float) $auto_value, (int) $auto_count, $age
        ));
      } else {
        WP_CLI::line("Product $product_id: No rating data found.");
      }
    }
  }

  WP_CLI::add_command('klaviyo-rating', 'FN_Klaviyo_CLI');
}
