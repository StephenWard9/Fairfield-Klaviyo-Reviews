# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin that integrates Klaviyo Reviews widgets into WooCommerce product pages and archives. The plugin is designed to work with Flatsome theme and provides star ratings and review displays.

**Plugin Details:**
- Name: Fairfield Klaviyo Reviews
- Main file: `fairfield-klaviyo-reviews.php`
- Version: 0.2.0

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

## SEO / Google Schema Integration

### Auto-Capture Rating Data for JSON-LD

The plugin automatically captures star ratings from Klaviyo widgets and injects them into Rank Math's Product schema as `aggregateRating`. This makes products eligible for Google star ratings in search results.

**How it works:**
1. Client-side JS parses the Klaviyo widget's `aria-label` (e.g., "5 stars, 4 ratings")
2. Sends data via AJAX to save as product meta (`_fn_klaviyo_rating_value`, `_fn_klaviyo_rating_count`, `_fn_klaviyo_rating_timestamp`)
3. Server-side filter (`rank_math/json_ld`) injects `aggregateRating` into Product schema

**Key features:**
- **Rate limiting**: 1 update per product per IP per minute (prevents spam)
- **Stale data detection**: Ratings older than 14 days are excluded from schema
- **Manual override**: Manual ratings (set via WP-CLI) always take precedence over auto-captured
- **Daily cleanup**: Cron job deletes auto-captured ratings older than 30 days (forces re-capture)

### Manual Rating Override

For hero products (Akkermansia, etc.), you can set ratings manually that never expire:

```bash
# Set manual rating (bypasses auto-capture)
wp klaviyo-rating set 13052 5.0 4

# View current rating
wp klaviyo-rating get 13052

# Clear manual rating (reverts to auto-capture)
wp klaviyo-rating clear 13052
```

**Manual vs Auto-captured:**
- Manual ratings (`_fn_klaviyo_manual_rating_value`) never expire or get cleaned up
- Auto-captured ratings expire after 14 days (schema) or 30 days (cleanup cron)
- Manual ratings always override auto-captured when both exist

### Product Meta Fields

- `_fn_klaviyo_rating_value` - Auto-captured star rating (0.0-5.0)
- `_fn_klaviyo_rating_count` - Auto-captured review count
- `_fn_klaviyo_rating_timestamp` - Unix timestamp of last auto-capture
- `_fn_klaviyo_manual_rating_value` - Manual override rating (takes precedence)
- `_fn_klaviyo_manual_rating_count` - Manual override count

### WP-CLI Commands

Available commands under `wp klaviyo-rating`:
- `set <product_id> <rating_value> <rating_count>` - Set manual rating
- `get <product_id>` - Show current rating (indicates if manual or auto)
- `clear <product_id>` - Remove manual override

### Testing Schema

After ratings are captured:
1. Check product meta in WP admin for the product ID
2. View source on PDP and search for `aggregateRating` in Rank Math JSON-LD
3. Test with [Google Rich Results Test](https://search.google.com/test/rich-results)
