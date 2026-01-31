<?php
/**
 * Returns Policy Schema
 *
 * Adds MerchantReturnPolicy schema to Product pages and the returns page
 * to satisfy Google Merchant Center and Rich Results requirements.
 */

if (!defined('ABSPATH')) { exit; }

final class FN_Returns_Policy {
  public function __construct() {
    // Add return policy to Product schema (Rank Math)
    add_filter('rank_math/snippet/rich_snippet_product_entity', [$this, 'add_return_policy_to_product'], 10);

    // Add return policy schema to returns page
    add_action('wp_footer', [$this, 'output_return_policy_schema']);
  }

  /* ===== Add return policy to Product schema ===== */

  public function add_return_policy_to_product($entity) {
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

  /* ===== Output return policy schema on returns page ===== */

  public function output_return_policy_schema() {
    // Only run on the returns page (post ID 9)
    if (!is_page(9)) return;

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'MerchantReturnPolicy',
      'applicableCountry' => 'AU',
      'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
      'merchantReturnDays' => 30,
      'returnMethod' => 'https://schema.org/ReturnByMail',
      'returnFees' => 'https://schema.org/FreeReturn',
      'name' => 'Fairfield Nutrition Return Policy',
      'url' => 'https://shop.fairfieldnutrition.com.au/refund_returns/'
    ];

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
  }
}

new FN_Returns_Policy();
