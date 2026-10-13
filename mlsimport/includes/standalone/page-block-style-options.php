<?php
/**
 * Page-block style tables: the style options of every page block.
 *
 * Each page block has a set of style options with plain names (price_color,
 * columns, button_background, ...). The AI tools (update-page-block) accept
 * these names, the block editor stores them in the block's `styles`
 * attribute and shows them in the "Look" sidebar panel, and Elementor maps
 * them to the widget's own Style tab controls. One row per option says what
 * it is called, what kind of value it takes, the CSS it writes on a block
 * editor page (selector inside the block + property with %s for the value)
 * and the Elementor Style tab control that does the same, when one exists.
 *
 * The tables are grouped by block family, because blocks that share markup
 * share options: the listing-card blocks (Property List, List by ID, Half
 * Map, Content Slider, Search Results, Saved Properties), the agents
 * directory, the featured property, the map, the two forms (search and
 * contact) and the two category blocks (slider and list). The selectors and
 * the Elementor control names come from the widget file
 * elementor/class-mlsimport-elementor-page-block-widget.php, so the two
 * builders stay in step. The engine that uses these tables is in
 * page-block-styles.php.
 *
 * Public interface:
 *   mlsimport_page_block_style_table( $slug )   the option rows of one block
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/standalone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option row.
 *
 * Kind: 'color' (a hex color), 'number' (whole pixels) or 'columns' (1 to 6).
 * Control: the shape of the Elementor control's value: 'color' (a string),
 * 'slider' ({ size }), 'dimensions' ({ top, right, bottom, left }, written
 * equal) or 'font' (a typography group; the control is the group name).
 * An empty Elementor control means the option has no native control; the
 * scoped CSS does it on Elementor too.
 *
 * @param string $label     Human label, as the sidebar panel shows it.
 * @param string $kind      'color', 'number' or 'columns'.
 * @param string $selector  CSS selector(s) inside the block, comma separated.
 * @param string $css       CSS declarations with %s for the value.
 * @param string $elementor Elementor control name, or '' when none.
 * @param string $control   Elementor control value shape, or ''.
 * @return array<string, string>
 */
function mlsimport_page_block_style_row( string $label, string $kind, string $selector, string $css, string $elementor = '', string $control = '' ): array {
	return array(
		'label'     => $label,
		'kind'      => $kind,
		'selector'  => $selector,
		'css'       => $css,
		'elementor' => $elementor,
		'control'   => $control,
	);
}

/**
 * The style options of one page block, by block family.
 *
 * @param string $slug Page block slug.
 * @return array<string, array<string, string>> Option name => row; [] for an unknown slug.
 */
function mlsimport_page_block_style_table( string $slug ): array {
	switch ( $slug ) {
		case 'item_list':
		case 'list_by_id':
		case 'half_map':
			return mlsimport_page_block_style_listing_cards( true );
		case 'content_slider':
			return mlsimport_page_block_style_listing_cards( false );
		case 'property_list_filters':
		case 'saved':
			// Same markup as the Property List, but the Elementor widget has no Style tab.
			return array_map(
				static function ( array $row ): array {
					$row['elementor'] = '';
					$row['control']   = '';
					return $row;
				},
				mlsimport_page_block_style_listing_cards( true )
			);
		case 'agents_directory':
			return mlsimport_page_block_style_agents();
		case 'featured_property':
			return mlsimport_page_block_style_featured();
		case 'map_listings':
			return mlsimport_page_block_style_map();
		case 'search_form':
		case 'contact_form':
			return mlsimport_page_block_style_form( $slug );
		case 'category_slider':
		case 'category_list':
			return mlsimport_page_block_style_categories( 'category_slider' === $slug );
		case 'blog_posts':
			// The rows live with the block (page-blocks-blog.php).
			return mlsimport_page_block_style_blog();
	}
	return array();
}

/**
 * The listing-card blocks: the grid (not for the carousel), the card, the
 * photo, the status badge, and the price / title / specs colors and sizes.
 *
 * @param bool $grid Whether the block lays its cards out in a grid.
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_listing_cards( bool $grid ): array {
	$r    = 'mlsimport_page_block_style_row';
	$g    = '.mlsimport-results__grid';
	$card = '.mlsimport-listing-card';
	$e    = 'mlsimport_item_list_';
	$rows = $grid ? array(
		'columns'    => $r( 'Cards per row (1 to 6)', 'columns', $g, 'grid-template-columns: repeat(%s, 1fr)', $e . 'columns', 'slider' ),
		'column_gap' => $r( 'Space between columns (px)', 'number', $g, 'column-gap: %spx', $e . 'column_gap', 'slider' ),
		'row_gap'    => $r( 'Space between rows (px)', 'number', $g, 'row-gap: %spx', $e . 'row_gap', 'slider' ),
	) : array();
	return $rows + array(
		'card_background'   => $r( 'Card background color', 'color', $card, 'background-color: %s', $e . 'card_background', 'color' ),
		'card_radius'       => $r( 'Card corner radius (px)', 'number', $card, 'border-radius: %spx', $e . 'card_radius', 'dimensions' ),
		'body_padding'      => $r( 'Card text padding (px)', 'number', $card . '__body', 'padding: %spx', $e . 'card_body_padding', 'dimensions' ),
		'photo_height'      => $r( 'Photo height (px)', 'number', $card . '__media', 'height: %spx', $e . 'media_height', 'slider' ),
		'badge_background'  => $r( 'Status badge background color', 'color', $card . '__badge', 'background-color: %s', $e . 'badge_background', 'color' ),
		'badge_color'       => $r( 'Status badge text color', 'color', $card . '__badge', 'color: %s', $e . 'badge_color', 'color' ),
		'badge_radius'      => $r( 'Status badge corner radius (px)', 'number', $card . '__badge', 'border-radius: %spx', $e . 'badge_radius', 'dimensions' ),
		'price_color'       => $r( 'Price color', 'color', $card . '__price', 'color: %s', $e . 'price_color', 'color' ),
		'title_color'       => $r( 'Title (address) color', 'color', $card . '__title', 'color: %s', $e . 'title_color', 'color' ),
		'title_hover_color' => $r( 'Title color on hover', 'color', $card . '__link:hover ' . $card . '__title', 'color: %s', $e . 'title_color_hover', 'color' ),
		'specs_color'       => $r( 'Specs (beds, baths, size) color', 'color', $card . '__specs', 'color: %s', $e . 'specs_color', 'color' ),
		'price_size'        => $r( 'Price font size (px)', 'number', $card . '__price', 'font-size: %spx', $e . 'price_typography', 'font' ),
		'title_size'        => $r( 'Title font size (px)', 'number', $card . '__title', 'font-size: %spx', $e . 'title_typography', 'font' ),
		'specs_size'        => $r( 'Specs font size (px)', 'number', $card . '__specs', 'font-size: %spx', $e . 'specs_typography', 'font' ),
	);
}

/**
 * The agents directory: the grid, the card, the photo, and the name / job
 * title / office colors and sizes.
 *
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_agents(): array {
	$r    = 'mlsimport_page_block_style_row';
	$grid = '.mlsimport-agents-directory';
	$card = '.mlsimport-agent-card';
	$e    = 'mlsimport_agents_';
	return array(
		'columns'         => $r( 'Agents per row (1 to 6)', 'columns', $grid, 'grid-template-columns: repeat(%s, minmax(0, 1fr))', $e . 'columns', 'slider' ),
		'gap'             => $r( 'Space between cards (px)', 'number', $grid, 'gap: %spx', $e . 'gap', 'slider' ),
		'card_background' => $r( 'Card background color', 'color', $card . '--dir', 'background-color: %s', $e . 'card_background', 'color' ),
		'card_radius'     => $r( 'Card corner radius (px)', 'number', $card . '--dir', 'border-radius: %spx; overflow: hidden', $e . 'card_radius', 'dimensions' ),
		'body_padding'    => $r( 'Card text padding (px)', 'number', $card . '__body', 'padding: %spx', $e . 'card_body_padding', 'dimensions' ),
		'photo_height'    => $r( 'Photo height (px)', 'number', $card . '__img', 'height: %spx', $e . 'photo_height', 'slider' ),
		'name_color'      => $r( 'Name color', 'color', $card . '--dir ' . $card . '__name', 'color: %s', $e . 'name_color', 'color' ),
		'title_color'     => $r( 'Job title color', 'color', $card . '__title', 'color: %s', $e . 'title_color', 'color' ),
		'office_color'    => $r( 'Office color', 'color', $card . '__office', 'color: %s', $e . 'office_color', 'color' ),
		'name_size'       => $r( 'Name font size (px)', 'number', $card . '--dir ' . $card . '__name', 'font-size: %spx', $e . 'name_typography', 'font' ),
		'title_size'      => $r( 'Job title font size (px)', 'number', $card . '__title', 'font-size: %spx', $e . 'title_typography', 'font' ),
		'office_size'     => $r( 'Office font size (px)', 'number', $card . '__office', 'font-size: %spx', $e . 'office_typography', 'font' ),
	);
}

/**
 * The featured property: the box, the photo / hero heights, and the colors
 * and sizes of its texts and badges. Some only show on certain designs, as
 * in Elementor; the CSS is harmless on the others.
 *
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_featured(): array {
	$r = 'mlsimport_page_block_style_row';
	$f = '.mlsimport-featured';
	$e = 'mlsimport_featured_';
	$t = $f . '__title, ' . $f . '__title a';
	$p = $f . '__price, ' . $f . '--design-5 ' . $f . '__label';
	$m = $f . '__specs, ' . $f . '__specrow, ' . $f . '__features';
	return array(
		'radius'           => $r( 'Corner radius (px)', 'number', $f . ', ' . $f . '__media, ' . $f . '__img, ' . $f . '__hero', 'border-radius: %spx; overflow: hidden', $e . 'radius', 'dimensions' ),
		'photo_height'     => $r( 'Photo height (px, designs 1 to 3)', 'number', $f . '__media', 'height: %spx; aspect-ratio: auto', $e . 'media_height', 'slider' ),
		'hero_height'      => $r( 'Hero height (px, designs 4 to 6)', 'number', $f . '__hero', 'min-height: %spx', $e . 'hero_height', 'slider' ),
		'title_color'      => $r( 'Title color', 'color', $t, 'color: %s', $e . 'title_color', 'color' ),
		'price_color'      => $r( 'Price color', 'color', $p, 'color: %s', $e . 'price_color', 'color' ),
		'price_background' => $r( 'Price background color', 'color', $f . '__price', 'background-color: %s', $e . 'price_bg', 'color' ),
		'meta_color'       => $r( 'Specs and features color', 'color', $m, 'color: %s', $e . 'meta_color', 'color' ),
		'excerpt_color'    => $r( 'Description color', 'color', $f . '__excerpt', 'color: %s', $e . 'excerpt_color', 'color' ),
		'more_color'       => $r( 'Read more link color', 'color', $f . '__more', 'color: %s', $e . 'more_color', 'color' ),
		'agent_color'      => $r( 'Agent name color', 'color', $f . '__agent-name', 'color: %s', $e . 'agent_color', 'color' ),
		'flag_background'  => $r( 'Featured flag background color', 'color', $f . '__flag', 'background-color: %s', $e . 'flag_bg', 'color' ),
		'flag_color'       => $r( 'Featured flag text color', 'color', $f . '__flag', 'color: %s', $e . 'flag_color', 'color' ),
		'badge_background' => $r( 'Status badge background color', 'color', $f . '__badge', 'background-color: %s', $e . 'badge_bg', 'color' ),
		'badge_color'      => $r( 'Status badge text color', 'color', $f . '__badge', 'color: %s', $e . 'badge_color', 'color' ),
		'title_size'       => $r( 'Title font size (px)', 'number', $t, 'font-size: %spx', $e . 'title_typography', 'font' ),
		'price_size'       => $r( 'Price font size (px)', 'number', $p, 'font-size: %spx', $e . 'price_typography', 'font' ),
		'meta_size'        => $r( 'Specs font size (px)', 'number', $m, 'font-size: %spx', $e . 'meta_typography', 'font' ),
	);
}

/**
 * The map: its height and corner radius.
 *
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_map(): array {
	$r   = 'mlsimport_page_block_style_row';
	$map = '.mlsimport-page-block--map .mlsimport-map';
	return array(
		'height' => $r( 'Map height (px)', 'number', $map, 'height: %spx', 'mlsimport_map_height', 'slider' ),
		'radius' => $r( 'Map corner radius (px)', 'number', $map, 'border-radius: %spx', 'mlsimport_map_radius', 'dimensions' ),
	);
}

/**
 * The search form and the contact form: the form box, its title, the
 * labels, the fields and the button. The contact form adds the consent text.
 *
 * @param string $slug 'search_form' or 'contact_form'.
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_form( string $slug ): array {
	$r      = 'mlsimport_page_block_style_row';
	$search = 'search_form' === $slug;
	$form   = $search ? '.mlsimport-search-form' : '.mlsimport-contact-form';
	$e      = $search ? 'mlsimport_search_form_' : 'mlsimport_contact_form_';
	$field  = $search
		? $form . '__field input, ' . $form . '__field select, ' . $form . '__field .mlsimport-ms__control, ' . $form . '__field .mlsimport-range__toggle, ' . $form . '__field .mlsimport-bedsbaths__toggle'
		: $form . '__field input:not([type="checkbox"]):not([type="radio"]), ' . $form . '__field select, ' . $form . '__field textarea';
	$label  = $search ? $form . '__field > span' : $form . '__field > span, ' . $form . '__field > legend';
	$button = $form . '__submit';
	$rows   = array(
		'column_gap'              => $r( 'Space between columns (px)', 'number', $form, 'column-gap: %spx', $e . 'column_gap', 'slider' ),
		'row_gap'                 => $r( 'Space between rows (px)', 'number', $form, 'row-gap: %spx', $e . 'row_gap', 'slider' ),
		'background'              => $r( 'Form background color', 'color', $form, 'background-color: %s', $e . 'background', 'color' ),
		'padding'                 => $r( 'Form padding (px)', 'number', $form, 'padding: %spx', $e . 'padding', 'dimensions' ),
		'radius'                  => $r( 'Form corner radius (px)', 'number', $form, 'border-radius: %spx', $e . 'radius', 'dimensions' ),
		'title_color'             => $r( 'Title color', 'color', $form . '__title', 'color: %s', $e . 'title_color', 'color' ),
		'title_size'              => $r( 'Title font size (px)', 'number', $form . '__title', 'font-size: %spx', $e . 'title_typography', 'font' ),
		'label_color'             => $r( 'Label color', 'color', $label, 'color: %s', $e . 'label_color', 'color' ),
		'label_size'              => $r( 'Label font size (px)', 'number', $label, 'font-size: %spx', $e . 'label_typography', 'font' ),
		'label_spacing'           => $r( 'Space between label and field (px)', 'number', $form . '__field', 'gap: %spx', $e . 'label_spacing', 'slider' ),
		'field_color'             => $r( 'Field text color', 'color', $field, 'color: %s', $e . 'field_color', 'color' ),
		'field_background'        => $r( 'Field background color', 'color', $field, 'background-color: %s', $e . 'field_background', 'color' ),
		'field_size'              => $r( 'Field font size (px)', 'number', $field, 'font-size: %spx', $e . 'field_typography', 'font' ),
		'field_padding'           => $r( 'Field padding (px)', 'number', $field, 'padding: %spx', $e . 'field_padding', 'dimensions' ),
		'field_border_color'      => $r( 'Field border color', 'color', $field, 'border-color: %s', $e . 'field_border_color', 'color' ),
		'field_radius'            => $r( 'Field corner radius (px)', 'number', $field, 'border-radius: %spx', $e . 'field_radius', 'dimensions' ),
		'button_background'       => $r( 'Button background color', 'color', $button, 'background-color: %s' . ( $search ? ' !important' : '' ), $e . 'button_background', 'color' ),
		'button_color'            => $r( 'Button text color', 'color', $button, 'color: %s', $e . 'button_color', 'color' ),
		'button_background_hover' => $r( 'Button background color on hover', 'color', $button . ':hover', 'background-color: %s' . ( $search ? ' !important' : '' ), $e . 'button_background_hover', 'color' ),
		'button_color_hover'      => $r( 'Button text color on hover', 'color', $button . ':hover', 'color: %s', $e . 'button_color_hover', 'color' ),
		'button_size'             => $r( 'Button font size (px)', 'number', $button, 'font-size: %spx', $e . 'button_typography', 'font' ),
		'button_radius'           => $r( 'Button corner radius (px)', 'number', $button, 'border-radius: %spx', $e . 'button_radius', 'dimensions' ),
		'button_padding'          => $r( 'Button padding (px)', 'number', $button, 'padding: %spx', $e . 'button_padding', 'dimensions' ),
	);
	if ( ! $search ) {
		$rows['consent_color'] = $r( 'Consent text color', 'color', $form . '__consent', 'color: %s', $e . 'consent_color', 'color' );
	}
	return $rows;
}

/**
 * The category slider and the category list: the tile, the grid gap (list
 * only), the title / tagline colors and sizes, and the arrows (slider only).
 *
 * @param bool $slider Whether the block is the slider.
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_categories( bool $slider ): array {
	$r     = 'mlsimport_page_block_style_row';
	$tile  = '.mlsimport-cat-tile';
	$e     = 'mlsimport_cat_';
	$arrow = '.mlsimport-category-slider .splide__arrow';
	$rows  = array(
		'tile_height'        => $r( 'Tile height (px)', 'number', $tile, 'height: %spx', $e . 'height', 'slider' ),
		'tile_background'    => $r( 'Tile background color', 'color', $tile, 'background-color: %s', $e . 'background', 'color' ),
		'tile_radius'        => $r( 'Tile corner radius (px)', 'number', $tile . ', ' . $tile . '__img', 'border-radius: %spx', $e . 'radius', 'dimensions' ),
		'tile_padding'       => $r( 'Tile text padding (px)', 'number', $tile . '__body', 'padding: %spx', $e . 'padding', 'dimensions' ),
		'title_color'        => $r( 'Title color', 'color', $tile . '__title', 'color: %s', $e . 'title_color', 'color' ),
		'title_hover_color'  => $r( 'Title color on hover', 'color', $tile . ':hover ' . $tile . '__title', 'color: %s', $e . 'title_hover_color', 'color' ),
		'tagline_color'      => $r( 'Tagline (count) color', 'color', $tile . '__tagline', 'color: %s', $e . 'count_color', 'color' ),
		'caption_background' => $r( 'Caption background color (design 3)', 'color', $tile . '__body', 'background: %s', $e . 'caption_bg', 'color' ),
		'title_size'         => $r( 'Title font size (px)', 'number', $tile . '__title', 'font-size: %spx', $e . 'title_typography', 'font' ),
		'tagline_size'       => $r( 'Tagline font size (px)', 'number', $tile . '__tagline', 'font-size: %spx', $e . 'count_typography', 'font' ),
	);
	if ( $slider ) {
		$rows['arrow_background'] = $r( 'Arrow background color', 'color', $arrow, 'background-color: %s', $e . 'arrow_bg', 'color' );
		$rows['arrow_icon_color'] = $r( 'Arrow icon color', 'color', $arrow . ' svg', 'fill: %s', $e . 'arrow_icon', 'color' );
	} else {
		$rows['gap'] = $r( 'Space between tiles (px)', 'number', '.mlsimport-cat-list', 'gap: %spx', $e . 'gap', 'slider' );
	}
	return $rows;
}
