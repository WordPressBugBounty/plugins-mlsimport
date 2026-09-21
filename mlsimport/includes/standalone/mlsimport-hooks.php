<?php
/**
 * Standalone (theme_id 990) hook reference — the single place to discover the
 * extension contract, mirroring WooCommerce's wc-template-hooks.php.
 *
 * This file is documentation-only: it declares no callbacks. The hooks below
 * are fired/applied at the architecture's single chokepoints (the section
 * dispatcher, the template loader, the query executor, the render orchestrator,
 * the write adapter, the lead endpoint, CPT registration), so one add_action()/
 * add_filter() reaches every front-end surface. Convention is locked in
 * docs/adr/0006-standalone-hook-conventions.md; the full human reference with
 * worked examples lives in docs/standalone-hooks.md.
 *
 * Conventions (see ADR-0006):
 *   - Every hook is prefixed `mlsimport_`.
 *   - Filters: the subject is arg 0 and MUST be returned (possibly modified);
 *     context args ($slug, $id, $args, $params, $property) follow.
 *   - Actions: most-relevant subject first; never expected to return.
 *   - Dynamic hooks use the sanitized slug, e.g. `mlsimport_section_{$slug}_html`.
 *   - Hooks are a public API: renames require a *_deprecated shim.
 *   - SQL-affecting filters expose structured params/results as the primary path;
 *     `mlsimport_listings_query_where` and `mlsimport_listings_order` are advanced
 *     hooks, re-validated for placeholder safety after the filter runs.
 *   - Editorial protection: no hook may set/clear the mlsimport_label taxonomy.
 *
 * ─── RENDER — section dispatcher (property-section-registry.php) ──────────────
 *   filter mlsimport_property_sections      ( array  $sections )
 *   filter mlsimport_section_args           ( array  $args, string $slug, int $id )
 *   action mlsimport_before_section         ( string $slug, int $id, array $args )            buffered
 *   action mlsimport_before_section_{$slug} ( int    $id, array $args )                        buffered
 *   filter mlsimport_section_{$slug}_html   ( string $html, int $id, array $args )
 *   filter mlsimport_section_html           ( string $html, string $slug, int $id, array $args )
 *   action mlsimport_after_section_{$slug}  ( int    $id, array $args )                        buffered
 *   action mlsimport_after_section          ( string $slug, int $id, array $args )             buffered
 *
 * ─── RENDER — view model + facts (property-sections.php) ──────────────────────
 *   filter mlsimport_property_data          ( array  $vm, int $id )
 *   filter mlsimport_property_field_section ( string $section, string $field )  move a RESO field between sections
 *   filter mlsimport_format_price           ( string $string, mixed $value )
 *
 * ─── RENDER — orchestrator (class-mlsimport-standalone-render.php) ────────────
 *   filter mlsimport_render_args            ( array  $args )
 *   filter mlsimport_prepared_listings      ( array  $payload, array $args )
 *   action mlsimport_before_listings        ( array  $args, int $total )
 *   action mlsimport_before_search_form     ( array  $args )
 *   action mlsimport_after_search_form      ( array  $args )
 *   filter mlsimport_listings_visible_fields ( array|null $keys, array $args )   null = show every search field
 *   action mlsimport_before_results         ( array  $args, int $total )
 *   action mlsimport_after_results          ( array  $args, int $total )
 *   filter mlsimport_no_results_message     ( string $html, array $args )
 *   action mlsimport_before_listing_card    ( WP_Post $post, object|null $row )                buffered
 *   action mlsimport_after_listing_card     ( WP_Post $post, object|null $row )                buffered
 *   filter mlsimport_listing_card_html      ( string $html, WP_Post $post, object|null $row )
 *   filter mlsimport_card_template          ( string $name, string $style )                    v1|v2|v3 → template file
 *   filter mlsimport_card_view              ( array  $view, WP_Post $post, object|null $row )
 *   action mlsimport_card_before            ( WP_Post $post, object|null $row )                inside the card
 *   action mlsimport_card_badges            ( WP_Post $post, object|null $row )                badge row over the photo, after status
 *   filter mlsimport_card_featured_flag     ( string $html, WP_Post $post, object|null $row )  the card's "Featured" flag
 *   filter mlsimport_featured_first         ( bool $on )                                       featured listings lead unsorted lists
 *   action mlsimport_card_after_media       ( WP_Post $post, object|null $row )                inside the card
 *   action mlsimport_card_body_start        ( WP_Post $post, object|null $row )                inside the card
 *   action mlsimport_card_body_end          ( WP_Post $post, object|null $row )                inside the card
 *   action mlsimport_card_after             ( WP_Post $post, object|null $row )                inside the card
 *   filter mlsimport_map_markers            ( array  $markers, array $args )
 *
 * ─── RENDER — page-block dispatcher (page-block-registry.php) ─────────────────
 *   filter mlsimport_page_blocks              ( array  $blocks )
 *   filter mlsimport_page_block_args          ( array  $args, string $slug )
 *   action mlsimport_before_page_block        ( string $slug, array $args )                     buffered
 *   action mlsimport_before_page_block_{$slug} ( array $args )                                   buffered
 *   filter mlsimport_page_block_{$slug}_html  ( string $html, array $args )
 *   filter mlsimport_page_block_html          ( string $html, string $slug, array $args )
 *   action mlsimport_after_page_block_{$slug} ( array  $args )                                   buffered
 *   action mlsimport_after_page_block         ( string $slug, array $args )                      buffered
 *   filter mlsimport_search_form_fields       ( array  $rows, array $args )   each row: { field, label }
 *   filter mlsimport_contact_form_fields      ( array  $rows, array $args )   each row: { name, type, label, placeholder, required }
 *
 * ─── QUERY — executor + builder (class-mlsimport-standalone-listings-query / -query) ──
 *   filter mlsimport_listings_query_params  ( array  $params )
 *   filter mlsimport_listings_query_where   ( array  $where{where,args}, array $params )       advanced
 *   filter mlsimport_listings_feature_ttids ( int[]  $ttids, array $params )
 *   filter mlsimport_listings_results       ( array  $result{rows,total}, array $params )
 *   filter mlsimport_listings_order         ( string $order, array $params )                   advanced, re-validated
 *   filter mlsimport_listings_per_page      ( int    $limit, array $params )
 *
 * ─── TEMPLATE — loader + routing + slots (template loader / single / templates) ──
 *   filter mlsimport_template_name          ( string $name )
 *   filter mlsimport_template_subdir        ( string $subdir )
 *   filter mlsimport_locate_template        ( string $path, string $name, string $subdir )
 *   filter mlsimport_template_include       ( string $located, string $template )
 *   filter mlsimport_single_property_sections ( string[] $slugs, int $id )                   single-page section order
 *   action mlsimport_before_single_property ( int    $id )
 *   action mlsimport_after_single_property  ( int    $id )
 *   action mlsimport_before_archive         ( array  $args )
 *   action mlsimport_after_archive          ( array  $args )
 *
 * ─── ASSETS / MAP (class-mlsimport-standalone-assets / -settings / property-sections) ──
 *   filter mlsimport_standalone_styles      ( bool   $enqueue )                                stylesheet toggle
 *   filter mlsimport_map_tile_url           ( string $url )
 *
 * ─── LIFECYCLE — import write (StandaloneClass.php, row, reso-map, reindex) ────
 *   action mlsimport_before_save_property   ( int    $property_id, array $property )
 *   filter mlsimport_property_skip_write    ( bool   $skip, int $property_id, string $incoming_mod, array $property )
 *   filter mlsimport_property_meta_value    ( mixed  $value, string $meta_key, int $property_id, array $property )
 *   filter mlsimport_property_feature_label ( string $label, string $reso_field, mixed $value )
 *   filter mlsimport_property_post_content  ( string $content, array $property )
 *   action mlsimport_after_save_property    ( int    $property_id, array $property )
 *   filter mlsimport_reso_targets_for       ( array  $targets, string $field )
 *   filter mlsimport_listings_row           ( array  $row, int $post_id, string $listing_key )
 *   action mlsimport_after_listings_row_upsert ( int $post_id, string $listing_key, array $row )
 *   action mlsimport_before_delete_listing_row ( int $post_id )
 *   action mlsimport_after_delete_listing_row  ( int $post_id )
 *   action mlsimport_reindex_property       ( int    $post_id )
 *   action mlsimport_after_reindex          ( int    $count )
 *
 * ─── LEAD (class-mlsimport-property-lead.php, property-sections.php) ──────────
 *   filter mlsimport_property_lead_fields     ( string $fields, string $variant, int $id )
 *   filter mlsimport_property_lead_validation ( array  $errors, array $input, int $property_id )
 *   filter mlsimport_property_lead_subject    ( string $subject, int $property_id )
 *   filter mlsimport_property_lead_email_body ( string $body, array $data, int $property_id )
 *   action mlsimport_property_lead_submitted  ( array  $data, int $property_id )
 *   action mlsimport_property_lead_mail_sent  ( bool   $sent, array $data, int $property_id )
 *   filter mlsimport_property_lead_recipient  ( string $to, int $property_id )
 *
 * ─── LIVE MODE — store-nothing passthrough seams (includes/live/) ─────────────
 *   filter mlsimport_live_mode_active        ( bool   $active )                               the one gate
 *   filter mlsimport_live_cache_ttl          ( int    $ttl )                                  seconds
 *   filter mlsimport_live_url_base           ( string $base )                                 virtual single URL base ('listing')
 *   filter mlsimport_property_data_pre       ( array|null $pre, int $id )                     short-circuit the single VM
 *   filter mlsimport_map_payload_pre         ( array|null $pre, array $args, int $zoom )      short-circuit the viewport payload
 *   filter mlsimport_map_bounds_pre          ( array|null $pre, array $params )               short-circuit the overall map bounds
 *   filter mlsimport_search_field_options    ( array  $pairs, array $def )                    value => label option list
 *   filter mlsimport_search_field_options_pre ( array|null $pre, array $def )                 options before terms query (live enums)
 *   filter mlsimport_page_block_list_by_id_pre ( string|null $pre, array $args )              List-by-ID as ListingKeys
 *   filter mlsimport_page_block_ids_cards_pre ( string|null $pre, array $args, string $slide_class )  list/slider ids as ListingKeys
 *   filter mlsimport_page_block_featured_card_pre ( string|null $pre, array $args )           Featured card by ListingKey
 *   filter mlsimport_page_block_map_markers_pre ( array|null $pre, array $args )              Map ids as ListingKey markers
 *   filter mlsimport_half_map_draw_tools      ( bool   $enabled )                             draw-area tools gate (live hides)
 *
 * ─── REGISTRATION (class-mlsimport-standalone-cpt.php) ────────────────────────
 *   filter mlsimport_property_post_type_args ( array  $args )
 *   filter mlsimport_agent_post_type_args    ( array  $args )
 *   filter mlsimport_taxonomies              ( array  $list )
 *   filter mlsimport_taxonomy_args           ( array  $args, string $taxonomy )
 *   action mlsimport_registered_cpts         ( )
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
