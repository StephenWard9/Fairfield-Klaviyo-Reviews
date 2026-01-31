# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin that integrates Klaviyo Reviews widgets into WooCommerce product pages and archives. The plugin is designed to work with Flatsome theme and provides star ratings and review displays.

**Plugin Details:**
- Name: Fairfield Klaviyo Reviews
- Main file: `fairfield-klaviyo-reviews.php`
- Version: 0.1.2

## Architecture

### Single-File Plugin Structure

The entire plugin is contained in `fairfield-klaviyo-reviews.php` with a single class `FN_Klaviyo_Reviews` that handles:

1. **Hook-based auto-placement** - Automatically injects Klaviyo widgets into WooCommerce pages
2. **Shortcode API** - Provides manual placement options
3. **Asset management** - Inline CSS and JavaScript for styling and functionality

### Key Components

**PDP (Product Detail Page) Placement:**
- `render_product_reviews_widget()` - Full reviews widget (hook: `woocommerce_after_single_product_summary` priority 15)
- `render_product_star_ratings_widget()` - Star ratings only (hook: `woocommerce_single_product_summary` priority 6)

**Archive/Grid Placement:**
- `render_archive_star_ratings_widget()` - Renders stars on shop/category pages
- Hooks into multiple Flatsome-specific positions: `woocommerce_after_shop_loop_item_title`, `woocommerce_before_shop_loop_item_title`, `woocommerce_after_shop_loop_item`
- Uses static deduplication to prevent duplicate widgets per product
- Respects `fn_klaviyo_archive_stars_enabled` option and filter
- Handles product variations by using parent product ID

**Shortcodes:**
- `[klaviyo-reviews-all]` - Product reviews widget
- `[klaviyo_star_rating]` - Star ratings (accepts `product_id` attribute)
- `[klaviyo-featured-reviews-carousel]` - Featured reviews carousel
- `[klaviyo-reviews-all-seo]` or `[fulfilled-reviews-all]` - All reviews with SEO markup

### Widget Rendering Pattern

All widgets follow this pattern:
```php
echo "<div class='widget-class' data-id='" . esc_attr($product_id) . "'></div>";
```

Klaviyo's external JavaScript finds these divs by class/id and populates them with the actual widget content.

### Asset Management

The plugin enqueues inline CSS and JS (not external files):

**CSS Features:**
- Maintains grid alignment with min-height placeholder
- Hides widgets with 0 ratings using `visibility: hidden` (preserves layout)
- Uses `.has-kl-stars` class to toggle placeholder visibility

**JavaScript:**
- Uses MutationObserver to detect when Klaviyo widgets are loaded
- Adds `.has-kl-stars` class to remove placeholder when widget appears
- Runs on DOMContentLoaded or immediately if DOM is ready

## Development Notes

### Product ID Handling
- For variations: uses parent product ID (`get_parent_id()`)
- For simple products: uses product ID directly
- Archive widgets use static array to prevent duplicate rendering per product

### Flatsome Theme Integration
The archive star placement hooks are specifically chosen to work with Flatsome's product grid structure. The multiple hook points ensure compatibility across different Flatsome configurations.

### Filter/Option System
The plugin supports disabling archive stars via:
- Option: `fn_klaviyo_archive_stars_enabled` (default: true)
- Filter: `fn_klaviyo_archive_stars_enabled` (allows programmatic control)
