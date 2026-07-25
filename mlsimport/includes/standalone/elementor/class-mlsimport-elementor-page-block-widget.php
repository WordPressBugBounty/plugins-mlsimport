<?php
/**
 * Elementor widgets: MLSImport Page Blocks (one per manifest entry).
 *
 * Loaded only when Elementor is active. An abstract base reads its slug from the
 * overridden get_block_slug() method (constructor-safe, the ADR-0005 trap),
 * builds its controls from the block's arg schema, and delegates render to the
 * dispatcher via Mlsimport_Page_Block_Elementor::render_settings(). Each concrete
 * widget is a one-line subclass carrying only its slug.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Abstract base for every page-block widget.
 */
abstract class Mlsimport_Elementor_Page_Block_Widget extends \Elementor\Widget_Base {

	/**
	 * The page-block slug this widget renders. Overridden per subclass — a method,
	 * not a constructor arg, so it survives Elementor's re-instantiation.
	 *
	 * @return string
	 */
	abstract protected function get_block_slug(): string;

	/**
	 * Unique Elementor machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'mlsimport-' . str_replace( '_', '-', $this->get_block_slug() );
	}

	/**
	 * Widget title (from the manifest label).
	 *
	 * @return string
	 */
	public function get_title() {
		return Mlsimport_Page_Block_Elementor::label( $this->get_block_slug() );
	}

	/**
	 * Per-block Elementor icon. Stock eicon-* glyphs (no icon font of our own to
	 * ship), each paired with the .mlsimport-note marker class that the editor
	 * stylesheet turns into an "MLSImport" badge on the tile.
	 */
	const ICONS = array(
		'item_list'             => 'eicon-posts-grid',
		'property_list_filters' => 'eicon-archive-posts',
		'list_by_id'            => 'eicon-bullet-list',
		'content_slider'        => 'eicon-post-slider',
		'agents_directory'      => 'eicon-person',
		'featured_property'     => 'eicon-featured-image',
		'map_listings'          => 'eicon-google-maps',
		'search_form'           => 'eicon-site-search',
		'contact_form'          => 'eicon-form-horizontal',
		'half_map'              => 'eicon-map-pin',
		'category_slider'       => 'eicon-product-categories',
		'category_list'         => 'eicon-gallery-grid',
		'saved'                 => 'eicon-heart-o',
	);

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$slug = $this->get_block_slug();
		$icon = isset( self::ICONS[ $slug ] ) ? self::ICONS[ $slug ] : 'eicon-posts-grid';
		return 'mlsimport-note ' . $icon;
	}

	/**
	 * Editor panel categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( Mlsimport_Page_Block_Elementor::CATEGORY );
	}

	/**
	 * Behavioral controls built from the block's arg schema.
	 *
	 * @return void
	 */
	protected function register_controls() {
		// Open the single controls section that holds every arg-schema field.
		$this->start_controls_section(
			'mlsimport_page_block',
			array( 'label' => $this->get_title() )
		);

		// Knobs this widget re-exposes as native Style-tab controls; showing them here
		// too would give one CSS property two competing controls.
		$styled = $this->styled_schema_keys();

		// Walk the block's arg schema, mapping each field to an Elementor control.
		foreach ( Mlsimport_Page_Block_Elementor::schema( $this->get_block_slug() ) as $key => $field ) {
			if ( in_array( $key, $styled, true ) ) {
				continue;
			}
			// Resolve the field type, defaulting to a plain text control.
			$type = isset( $field['type'] ) ? $field['type'] : 'text';
			// Repeater fields need Elementor's Repeater control (built separately).
			if ( 'repeater' === $type ) {
				$this->add_repeater_control( $key, $field );
				continue;
			}
			if ( 'multiselect' === $type ) {
				// A taxonomy with no terms yet gets no picker (in the editor). Still
				// registered on the front end so a saved pick isn't dropped.
				$cargs = $this->control_args( $field );
				// In the editor, skip an empty-option picker; keep it on the front end.
				if ( is_admin() && empty( $cargs['options'] ) ) {
					continue;
				}
				$this->add_control( $key, $cargs );
				continue;
			}
			// Every other type maps through control_args() to a single control.
			$this->add_control( $key, $this->control_args( $field ) );
		}

		// Close the controls section.
		$this->end_controls_section();

		// Style-tab sections, for the blocks whose markup has a stable contract.
		$this->register_style_controls();
	}

	/**
	 * Style-tab controls. No-op by default: the arg schema describes a block's
	 * behavior, not its looks, so appearance controls have to be declared against
	 * the block's own markup. A widget with a stable class contract overrides this.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {}

	/**
	 * Arg-schema keys this widget replaces with a native Style-tab control, and so
	 * hides from the Content tab. Empty by default. Only for keys whose whole effect
	 * is CSS the Style control can reproduce — a key the render function reads for
	 * anything else (markup, a data- attribute, Splide config) must stay.
	 *
	 * @return string[]
	 */
	protected function styled_schema_keys(): array {
		return array();
	}

	/**
	 * Add a repeater control built from a field's row sub-schema.
	 *
	 * @param string $key   Control key.
	 * @param array  $field Repeater schema ( label, default rows, fields sub-schema ).
	 * @return void
	 */
	private function add_repeater_control( string $key, array $field ): void {
		// Build a fresh Elementor Repeater and register one control per row field.
		$repeater = new \Elementor\Repeater();
		foreach ( (array) $field['fields'] as $sub_key => $sub ) {
			$repeater->add_control( $sub_key, $this->control_args( $sub ) );
		}

		// Register the repeater itself, wiring in the row sub-controls and defaults.
		$this->add_control(
			$key,
			array(
				'label'       => isset( $field['label'] ) ? $field['label'] : '',
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => isset( $field['default'] ) ? $field['default'] : array(),
				'title_field' => '{{{ label }}}',
			)
		);
	}

	/**
	 * Map one arg-schema field to Elementor control args, carrying across the
	 * schema's optional 'condition' — the only presentation hint the registry
	 * declares, so a control that applies to just some rows can hide itself.
	 * Elementor's condition format is already { other_key: value|values }, so the
	 * schema states it verbatim and this passes it straight through.
	 *
	 * @param array $field Schema field ( type, label, default, options, condition ).
	 * @return array
	 */
	private function control_args( array $field ): array {
		$args = $this->control_base( $field );
		if ( ! empty( $field['condition'] ) ) {
			$args['condition'] = (array) $field['condition'];
		}
		return $args;
	}

	/**
	 * Map one arg-schema field to its Elementor control type, label and default.
	 *
	 * @param array $field Schema field ( type, label, default, options ).
	 * @return array
	 */
	private function control_base( array $field ): array {
		// Pull the schema's type/label/default (each with a safe fallback).
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$label   = isset( $field['label'] ) ? $field['label'] : '';
		$default = isset( $field['default'] ) ? $field['default'] : '';

		// Multi-line field → TEXTAREA control, shown full width so a long comma
		// list (e.g. many post IDs) is visible while editing.
		if ( 'textarea' === $type ) {
			return array( 'label' => $label, 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => $default, 'rows' => 5, 'label_block' => true );
		}
		// Numeric field → Elementor NUMBER control.
		if ( 'number' === $type ) {
			return array( 'label' => $label, 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => $default );
		}
		// Boolean field → SWITCHER; Elementor's "on" value is the string 'yes'.
		if ( 'toggle' === $type ) {
			return array( 'label' => $label, 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => $default ? 'yes' : '' );
		}
		// Colour field → Elementor's colour picker (returns a CSS colour string).
		if ( 'color' === $type ) {
			return array( 'label' => $label, 'type' => \Elementor\Controls_Manager::COLOR, 'default' => $default );
		}
		// Image field → the media library picker. Elementor hands back
		// { url, id }; render_settings() flattens it to the URL string the
		// Gutenberg side already stores, so the render fn sees one shape.
		if ( 'media' === $type ) {
			return array( 'label' => $label, 'type' => \Elementor\Controls_Manager::MEDIA, 'default' => array() );
		}
		// Single-choice field with options → SELECT dropdown.
		if ( 'select' === $type && ! empty( $field['options'] ) ) {
			return array( 'label' => $label, 'type' => \Elementor\Controls_Manager::SELECT, 'options' => $field['options'], 'default' => $default );
		}
		if ( 'multiselect' === $type ) {
			// SELECT2 in multiple mode = a searchable multi-select. Options resolve only
			// in the admin editor (the front end reads the saved value and never needs
			// the list): a taxonomy's terms, or — for the Team Directory agent picker —
			// a post type's posts as a { post_id => title } map.
			$options = array();
			if ( is_admin() ) {
				if ( isset( $field['taxonomy'] ) ) {
					$options = mlsimport_category_term_options( (string) $field['taxonomy'] );
				} elseif ( isset( $field['post_type'] ) ) {
					$options = mlsimport_post_type_options( (string) $field['post_type'] );
				}
			}
			// SELECT2 in multiple mode = a searchable, multi-value picker.
			return array(
				'label'       => $label,
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => $options,
				'default'     => array(),
				'label_block' => true,
			);
		}
		// Fallback: any unrecognized type becomes a plain TEXT control.
		return array( 'label' => $label, 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $default );
	}

	/**
	 * Render via the shared dispatcher.
	 *
	 * @return void
	 */
	protected function render() {
		echo Mlsimport_Page_Block_Elementor::render_settings( $this->get_block_slug(), $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render fns escape at source.
	}
}

/*
 * One real, named subclass per page block. Real classes (not anonymous) are
 * required because Elementor rebuilds a widget from get_class( $prototype ) and an
 * anonymous class name is not re-instantiable. Each carries only its slug; all
 * behavior lives in the base.
 */

/**
 * Style-tab controls for any widget whose block renders the shared listings grid
 * (.mlsimport-results__grid) of .mlsimport-listing-card boxes — the Property List
 * and List-by-ID blocks both do, via Mlsimport_Standalone_Render::render_cards().
 * Every selector targets that one BEM contract, so a single control set styles all
 * three card designs (v1/v2/v3) identically on both widgets.
 *
 * The base class calls register_style_controls() at the end of register_controls(),
 * so a widget using this trait exposes the whole Style tab with no other wiring;
 * the trait method also overrides the base's no-op (trait beats inherited).
 */
trait Mlsimport_Listing_Card_Style_Controls {

	/**
	 * The shared listing-card selectors, defined once.
	 *
	 * grid   — the CSS grid that lays the cards out (columns and gaps hang here).
	 * card   — the card box every design (v1/v2/v3) shares; overflow:hidden already
	 *          set, so a radius here clips the media's top corners.
	 * media  — the card photo (a CSS background-image div, never an <img>).
	 * badge  — the status chip on the photo (Active/Sold/…), only when set.
	 * price  — the price line (v1/v2 lead with it; v3 overlays it).
	 * title  — the address heading (an <h3>, inside the card's own link).
	 * specs  — the beds · baths · ft² spec line.
	 *
	 * @return array<string,string>
	 */
	private function card_style_selectors(): array {
		return array(
			'grid'  => '{{WRAPPER}} .mlsimport-results__grid',
			'card'  => '{{WRAPPER}} .mlsimport-listing-card',
			'media' => '{{WRAPPER}} .mlsimport-listing-card__media',
			'badge' => '{{WRAPPER}} .mlsimport-listing-card__badge',
			'price' => '{{WRAPPER}} .mlsimport-listing-card__price',
			'title' => '{{WRAPPER}} .mlsimport-listing-card__title',
			'specs' => '{{WRAPPER}} .mlsimport-listing-card__specs',
		);
	}

	/**
	 * Style tab — the grid layout plus the card frame, photo, status badge and text.
	 * Modelled on the Featured Property widget's Style tab.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_layout_controls();
		$this->register_card_style_controls();
	}

	/**
	 * The card-frame Style sections — everything except the grid Layout, so a widget
	 * whose block lays the cards out some other way (the slider's Splide carousel)
	 * can pull in the same card styling without the columns/gaps control that would
	 * target a .mlsimport-results__grid it never renders.
	 *
	 * @return void
	 */
	protected function register_card_style_controls(): void {
		$this->register_card_controls();
		$this->register_media_controls();
		$this->register_badge_controls();
		$this->register_card_typography_controls();
		$this->register_card_color_controls();
	}

	/**
	 * Layout — how many cards across and the gutters between them. Columns are a
	 * responsive control seeded with the stylesheet's own 3 / 2 / 1 breakpoints, so
	 * the default look is unchanged until an author overrides it. The two gaps set
	 * column-gap / row-gap, which outrank the stylesheet's `gap` shorthand.
	 *
	 * @return void
	 */
	private function register_layout_controls(): void {
		$grid = $this->card_style_selectors()['grid'];
		$this->start_controls_section(
			'mlsimport_item_list_layout',
			array(
				'label' => esc_html__( 'Layout', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'mlsimport_item_list_columns',
			array(
				'label'          => esc_html__( 'Columns', 'mlsimport' ),
				'type'           => \Elementor\Controls_Manager::SLIDER,
				'range'          => array( 'px' => array( 'min' => 1, 'max' => 6, 'step' => 1 ) ),
				'default'        => array( 'size' => 3 ),
				'tablet_default' => array( 'size' => 2 ),
				'mobile_default' => array( 'size' => 1 ),
				'selectors'      => array( $grid => 'grid-template-columns: repeat({{SIZE}}, 1fr);' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_item_list_column_gap',
			array(
				'label'      => esc_html__( 'Column Gap', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( $grid => 'column-gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_item_list_row_gap',
			array(
				'label'      => esc_html__( 'Row Gap', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( $grid => 'row-gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Card — the box itself: background, border, corner radius, the resting and hover
	 * shadows, and the body padding. Radius rides the card's existing overflow:hidden,
	 * so the photo's top corners round with it. The hover shadow is a second Box Shadow
	 * group on :hover, matching the card's built-in lift.
	 *
	 * @return void
	 */
	private function register_card_controls(): void {
		$card = $this->card_style_selectors()['card'];
		$this->start_controls_section(
			'mlsimport_item_list_card',
			array(
				'label' => esc_html__( 'Card', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_item_list_card_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $card => 'background-color: {{VALUE}}' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'mlsimport_item_list_card_border',
				'selector' => $card,
			)
		);

		$this->add_responsive_control(
			'mlsimport_item_list_card_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					$card => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_item_list_card_shadow',
				'label'    => esc_html__( 'Box Shadow', 'mlsimport' ),
				'selector' => $card,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_item_list_card_shadow_hover',
				'label'    => esc_html__( 'Box Shadow (Hover)', 'mlsimport' ),
				'selector' => $card . ':hover',
			)
		);

		$this->add_responsive_control(
			'mlsimport_item_list_card_body_padding',
			array(
				'label'      => esc_html__( 'Body Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-listing-card__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
				'separator'  => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Media — the photo height. v1 fixes it at 180px and v3 gives it a min-height;
	 * v2's photo is a side-by-side split whose height follows the body, so this knob
	 * reads most naturally on the v1/v3 designs.
	 *
	 * @return void
	 */
	private function register_media_controls(): void {
		$media = $this->card_style_selectors()['media'];
		$this->start_controls_section(
			'mlsimport_item_list_media',
			array(
				'label' => esc_html__( 'Photo', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'mlsimport_item_list_media_height',
			array(
				'label'      => esc_html__( 'Height', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 80, 'max' => 500 ) ),
				'selectors'  => array( $media => 'height: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Status badge — the chip on the photo (Active / Sold / …). Its own fill, text
	 * colour and corner radius, independent of the price/title text.
	 *
	 * @return void
	 */
	private function register_badge_controls(): void {
		$badge = $this->card_style_selectors()['badge'];
		$this->start_controls_section(
			'mlsimport_item_list_badge',
			array(
				'label' => esc_html__( 'Status Badge', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_item_list_badge_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $badge => 'background-color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_item_list_badge_color',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $badge => 'color: {{VALUE}}' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_item_list_badge_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					$badge => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Typography — the three text elements every card design shares: price, title
	 * (address) and the spec line. One typography group each.
	 *
	 * @return void
	 */
	private function register_card_typography_controls(): void {
		$selectors = $this->card_style_selectors();
		$this->start_controls_section(
			'mlsimport_item_list_typography',
			array(
				'label' => esc_html__( 'Typography', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_item_list_price_typography',
				'label'    => esc_html__( 'Price Typography', 'mlsimport' ),
				'selector' => $selectors['price'],
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_ACCENT ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_item_list_title_typography',
				'label'    => esc_html__( 'Title Typography', 'mlsimport' ),
				'selector' => $selectors['title'],
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_item_list_specs_typography',
				'label'    => esc_html__( 'Specs Typography (Beds, Baths, Size)', 'mlsimport' ),
				'selector' => $selectors['specs'],
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Colors — price, title (resting + hover) and the spec line. Title hover targets
	 * the heading inside the card's own <a>, so it lights up on card hover.
	 *
	 * @return void
	 */
	private function register_card_color_controls(): void {
		$selectors = $this->card_style_selectors();
		$this->start_controls_section(
			'mlsimport_item_list_colors',
			array(
				'label' => esc_html__( 'Colors', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_item_list_price_color',
			array(
				'label'     => esc_html__( 'Price Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $selectors['price'] => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_item_list_title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $selectors['title'] => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_item_list_title_color_hover',
			array(
				'label'     => esc_html__( 'Title Color (Hover)', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .mlsimport-listing-card__link:hover .mlsimport-listing-card__title' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'mlsimport_item_list_specs_color',
			array(
				'label'     => esc_html__( 'Specs Color (Beds, Baths, Size)', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $selectors['specs'] => 'color: {{VALUE}}' ),
			)
		);

		$this->end_controls_section();
	}
}

/**
 * The "Initial filters" Content section shared by the listing-set widgets that
 * seed a fixed query (Property List, Property Slider): one SELECT2 term picker per
 * taxonomy, the full-width Price min/max pair, min beds/baths and an agent — the
 * exact controls, in the exact order, of the Gutenberg initialFilterPanel(). Every
 * key here is a listings filter both blocks' schemas carry, so the host widget's
 * render path forwards them unchanged.
 *
 * The one control that differs is the sort dropdown: Property List forwards an
 * orderby/order pair, the slider a single sort token, because their block schemas
 * expose different sort keys. Each host supplies its own via add_sort_control(),
 * called where the sort control belongs (last, before the section closes).
 */
trait Mlsimport_Listing_Selection_Controls {

	/**
	 * Register the host widget's Order control — the one initial-filter control that
	 * differs between hosts (see the trait docblock).
	 *
	 * @return void
	 */
	abstract protected function add_sort_control(): void;

	/**
	 * Initial filters — the presets that seed the set on first load. Same controls,
	 * same order as the Gutenberg initialFilterPanel().
	 *
	 * @return void
	 */
	protected function register_initial_filters_section(): void {
		$this->start_controls_section( 'mlsimport_initial_filters', array( 'label' => __( 'Initial filters', 'mlsimport' ) ) );

		// One SELECT2 term picker per taxonomy, from the list the Gutenberg
		// FormTokenFields read — option values already follow each field's own
		// convention (term name or slug), so a pick drops straight into the filter.
		foreach ( Mlsimport_Standalone_Block::taxonomy_options() as $tax ) {
			$options = array();
			foreach ( $tax['options'] as $option ) {
				$options[ $option['value'] ] = $option['label'];
			}
			$this->add_control(
				$tax['key'],
				array(
					'label'       => $tax['label'],
					'type'        => \Elementor\Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $options,
					'default'     => array(),
					'label_block' => true,
				)
			);
		}

		// Price min/max, each full width (stacked). Elementor's NUMBER input is narrow
		// by default (label_block only moves the label, not the box), so a full price
		// like 1,500,000 was clipped. mlsimport-control-wide (editor CSS) stretches the
		// input to 100% so the whole value is visible.
		$this->add_control(
			'price_min',
			array(
				'label'       => __( 'Price min', 'mlsimport' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'label_block' => true,
				'classes'     => 'mlsimport-control-wide',
			)
		);
		$this->add_control(
			'price_max',
			array(
				'label'       => __( 'Price max', 'mlsimport' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'label_block' => true,
				'classes'     => 'mlsimport-control-wide',
			)
		);

		$this->add_control(
			'beds',
			array(
				'label' => __( 'Min beds', 'mlsimport' ),
				'type'  => \Elementor\Controls_Manager::NUMBER,
				'min'   => 0,
			)
		);
		// Half-baths are real in every MLS feed and the query filters them as one,
		// so this steps by 0.5 rather than whole baths.
		$this->add_control(
			'baths',
			array(
				'label' => __( 'Min baths', 'mlsimport' ),
				'type'  => \Elementor\Controls_Manager::NUMBER,
				'min'   => 0,
				'step'  => 0.5,
			)
		);
		$this->add_control(
			'agent',
			array(
				'label' => __( 'Agent (post ID)', 'mlsimport' ),
				'type'  => \Elementor\Controls_Manager::NUMBER,
				'min'   => 0,
			)
		);

		$this->add_sort_control();

		$this->end_controls_section();
	}
}

/**
 * The "friendly search bar" Content controls shared by the two widgets that pair a
 * live search/refine bar with an initial-filter set: Property List and Half Map.
 * Both mirror the Gutenberg inspector, which offers an Order preset (a single
 * dropdown standing in for the orderby/order pair) and per-field search-bar toggles
 * rather than raw text inputs, so the translation of both back into the schema keys
 * the dispatcher reads lives here once. The host widget still declares its own
 * Settings section (the two differ — Property List paginates, Half Map caps) and,
 * for Half Map, the map Layout section.
 *
 * Provides the concrete add_sort_control() the Selection trait leaves abstract; a
 * concrete trait method satisfies another trait's abstract requirement with no
 * collision. Constants would live here but for PHP 7.4 (traits gained constants in
 * 8.2), so the preset table and field prefix are static methods instead.
 */
trait Mlsimport_Listing_Search_Bar_Controls {

	/**
	 * Prefix for the per-search-field switchers. Not schema keys: they serialize into
	 * the single `search_fields` comma list the render path reads.
	 *
	 * @return string
	 */
	private static function field_prefix(): string {
		return 'mlsimport_field_';
	}

	/**
	 * Sort presets → the orderby/order pair the render path filters by. The same list,
	 * in the same order, as the Gutenberg sortControl() (SORT_OPTIONS), so both
	 * builders offer identical ordering.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function sort_presets(): array {
		return array(
			''               => array( 'label' => 'Default', 'orderby' => '', 'order' => '' ),
			'list_date|desc' => array( 'label' => 'Newest', 'orderby' => 'list_date', 'order' => 'desc' ),
			'list_date|asc'  => array( 'label' => 'Oldest', 'orderby' => 'list_date', 'order' => 'asc' ),
			'price|asc'      => array( 'label' => 'Price (low to high)', 'orderby' => 'price', 'order' => 'asc' ),
			'price|desc'     => array( 'label' => 'Price (high to low)', 'orderby' => 'price', 'order' => 'desc' ),
			'bedrooms|desc'  => array( 'label' => 'Bedrooms (most first)', 'orderby' => 'bedrooms', 'order' => 'desc' ),
		);
	}

	/**
	 * The host's Order control — the one initial-filter control each Selection host
	 * supplies itself (see the Selection trait). A friendly dropdown standing in for
	 * the orderby/order pair, which render() splits back apart; its values are the
	 * sort_presets() keys.
	 *
	 * @return void
	 */
	protected function add_sort_control(): void {
		$sort_options = array();
		foreach ( self::sort_presets() as $value => $preset ) {
			$sort_options[ $value ] = $preset['label'];
		}
		$this->add_control(
			'mlsimport_sort',
			array(
				'label'   => __( 'Order', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $sort_options,
				'default' => '',
			)
		);
	}

	/**
	 * Search fields — one switcher per catalog field, all on by default (the default
	 * empty search_fields means "show every field").
	 *
	 * @return void
	 */
	protected function register_search_fields_section(): void {
		$this->start_controls_section( 'mlsimport_search_fields', array( 'label' => __( 'Search fields', 'mlsimport' ) ) );
		foreach ( Mlsimport_Page_Block_Search_Fields::labels() as $key => $label ) {
			$this->add_control(
				self::field_prefix() . $key,
				array(
					'label'   => $label,
					'type'    => \Elementor\Controls_Manager::SWITCHER,
					'default' => 'yes',
				)
			);
		}
		$this->end_controls_section();
	}

	/**
	 * Serialize the per-field switchers, preserving catalog order. Every field on →
	 * '' (the default: show all); every field off → the 'none' marker (an explicit
	 * empty form, distinct from the default); otherwise the comma list. The same
	 * three-way encoding the Gutenberg toggleField() writes.
	 *
	 * @param array $settings Control values.
	 * @return string
	 */
	private function search_fields_value( array $settings ): string {
		$all = array_keys( Mlsimport_Page_Block_Search_Fields::labels() );
		$on  = array();
		foreach ( $all as $key ) {
			$control = self::field_prefix() . $key;
			if ( ! isset( $settings[ $control ] ) || 'yes' === $settings[ $control ] ) {
				$on[] = $key;
			}
		}
		if ( count( $on ) === count( $all ) ) {
			return '';
		}
		return empty( $on ) ? 'none' : implode( ',', $on );
	}

	/**
	 * Translate this widget's two composite controls (the Order preset and the
	 * per-field switchers) into the schema keys the dispatcher reads, then render
	 * through the shared path.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Order preset → the orderby/order pair.
		$presets = self::sort_presets();
		$sort    = isset( $settings['mlsimport_sort'] ) ? (string) $settings['mlsimport_sort'] : '';
		$sort    = isset( $presets[ $sort ] ) ? $presets[ $sort ] : $presets[''];
		$settings['orderby'] = $sort['orderby'];
		$settings['order']   = $sort['order'];

		// Per-field switchers → the single search_fields comma list.
		$settings['search_fields'] = $this->search_fields_value( $settings );

		echo Mlsimport_Page_Block_Elementor::render_settings( $this->get_block_slug(), $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render fns escape at source.
	}
}

/**
 * Property List.
 *
 * The one widget that does NOT build its panel by walking the arg schema. The
 * Gutenberg inspector for this block is hand-arranged (Settings / Initial filters /
 * Search fields, with taxonomy term pickers, a paired Price row, and a friendly
 * Order preset) and the two builders must offer the same thing, so this widget
 * mirrors that arrangement instead of emitting the schema's raw text inputs. The
 * filter keys Gutenberg leaves out (sqft/lot/year/hoa/dom/…) stay out here too;
 * they remain reachable from the shortcode, which is the low-level surface.
 */
class Mlsimport_Elementor_Item_List_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	use Mlsimport_Listing_Card_Style_Controls;
	use Mlsimport_Listing_Selection_Controls;
	use Mlsimport_Listing_Search_Bar_Controls;

	protected function get_block_slug(): string {
		return 'item_list';
	}

	/**
	 * Three Content sections in the Gutenberg inspector's order, then the Style tab.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_settings_section();
		$this->register_initial_filters_section();
		$this->register_search_fields_section();
		$this->register_style_controls();
	}

	/**
	 * Settings — per-page, the filter-bar toggle and the bar's column count.
	 *
	 * @return void
	 */
	private function register_settings_section(): void {
		$this->start_controls_section( 'mlsimport_settings', array( 'label' => __( 'Settings', 'mlsimport' ) ) );
		$this->add_control(
			'count',
			array(
				'label'   => __( 'Properties per page', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'default' => 12,
			)
		);
		$this->add_control(
			'show_filter_bar',
			array(
				'label'   => __( 'Show filter bar', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);
		$this->add_control(
			'fields_per_row',
			array(
				'label'   => __( 'Search fields per row', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array( '3' => '3', '4' => '4', '5' => '5', '6' => '6' ),
				'default' => '4',
			)
		);
		$this->end_controls_section();
	}
}

/** Search Results. */
class Mlsimport_Elementor_Property_List_Filters_Widget extends Mlsimport_Elementor_Page_Block_Widget {
	protected function get_block_slug(): string {
		return 'property_list_filters';
	}
}

/**
 * List Items by ID.
 *
 * Builds its Content panel from the arg schema (the IDs list + per-page count), and
 * pulls in the full listings-card Style tab — its block renders the same
 * .mlsimport-results__grid of .mlsimport-listing-card boxes the Property List does,
 * so one shared control set styles both.
 */
class Mlsimport_Elementor_List_By_Id_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	use Mlsimport_Listing_Card_Style_Controls;

	protected function get_block_slug(): string {
		return 'list_by_id';
	}
}

/**
 * Property Slider.
 *
 * Hand-arranged like Property List — a Settings section (just "How many properties")
 * and the shared Initial filters section — instead of walking the schema's raw text
 * inputs. It renders the same .mlsimport-listing-card cards (in a Splide carousel,
 * not a CSS grid), so it pulls in the card Style sections but NOT the grid Layout.
 *
 * Sort differs from Property List: the content_slider schema exposes a single `sort`
 * token (not the orderby/order pair), which the render path maps back — so the Order
 * control here writes that token directly and render() needs no sort translation.
 */
class Mlsimport_Elementor_Content_Slider_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	use Mlsimport_Listing_Card_Style_Controls;
	use Mlsimport_Listing_Selection_Controls;

	protected function get_block_slug(): string {
		return 'content_slider';
	}

	/**
	 * Settings, then the shared Initial filters, then the card Style sections.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_settings_section();
		$this->register_initial_filters_section();
		$this->register_style_controls();
	}

	/**
	 * Settings — just how many properties feed the carousel. (The slider caps and
	 * has no pager, so there is no per-page/filter-bar config like Property List.)
	 *
	 * @return void
	 */
	private function register_settings_section(): void {
		$this->start_controls_section( 'mlsimport_settings', array( 'label' => __( 'Settings', 'mlsimport' ) ) );
		$this->add_control(
			'count',
			array(
				'label'   => __( 'How many properties', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'default' => 6,
			)
		);
		$this->end_controls_section();
	}

	/**
	 * The slider's Order control. Its values are the `sort` tokens the content_slider
	 * schema (and Mlsimport_Standalone_Query::SORT_TOKENS) understand, so the value is
	 * forwarded as-is — no render() translation, unlike Property List's orderby/order.
	 * Bedrooms sort is omitted: there is no bedrooms sort token, only orderby/order,
	 * which this block's schema does not carry.
	 *
	 * @return void
	 */
	protected function add_sort_control(): void {
		$this->add_control(
			'sort',
			array(
				'label'   => __( 'Order', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					''           => __( 'Default', 'mlsimport' ),
					'newest'     => __( 'Newest', 'mlsimport' ),
					'oldest'     => __( 'Oldest', 'mlsimport' ),
					'price_low'  => __( 'Price (low to high)', 'mlsimport' ),
					'price_high' => __( 'Price (high to low)', 'mlsimport' ),
				),
				'default' => 'newest',
			)
		);
	}

	/**
	 * Card styling only — the slider lays cards out with Splide, not a CSS grid, so
	 * the grid Layout section (columns/gaps) would target a grid it never renders.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_card_style_controls();
	}
}

/**
 * Team Directory (agents).
 *
 * Content panel is schema-driven (agents picker, count, verified-only, order, the
 * show toggles). The three visual knobs (per_row/gap/border_radius) are hidden from
 * Content and re-exposed as native Style-tab controls, joined by the card frame,
 * photo, typography and colour controls a builder most often reaches for. Every
 * selector targets the agent-directory markup (.mlsimport-agents-directory grid of
 * .mlsimport-agent-card--dir tiles). Elementor's rules outrank the block's inline
 * CSS-var defaults by specificity, so an untouched control leaves the default look.
 */
class Mlsimport_Elementor_Agents_Directory_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	/** The directory grid — columns and the gutter between tiles hang here. */
	const GRID_SELECTOR = '{{WRAPPER}} .mlsimport-agents-directory';

	/** The tile: the whole card is an <a> to the agent's page. */
	const CARD_SELECTOR = '{{WRAPPER}} .mlsimport-agent-card--dir';

	/** The tile photo (an <img>, or a tinted placeholder span sharing the class). */
	const PHOTO_SELECTOR = '{{WRAPPER}} .mlsimport-agent-card__img';

	/** The text block under the photo. */
	const BODY_SELECTOR = '{{WRAPPER}} .mlsimport-agent-card__body';

	/** Agent name. */
	const NAME_SELECTOR = '{{WRAPPER}} .mlsimport-agent-card--dir .mlsimport-agent-card__name';

	/** Job title line. */
	const TITLE_SELECTOR = '{{WRAPPER}} .mlsimport-agent-card__title';

	/** Office line. */
	const OFFICE_SELECTOR = '{{WRAPPER}} .mlsimport-agent-card__office';

	protected function get_block_slug(): string {
		return 'agents_directory';
	}

	/**
	 * The visual knobs the block schema still carries (for the Gutenberg inspector and
	 * the render's CSS-var defaults) but which this widget replaces with Style controls,
	 * so they don't also appear as raw Content inputs.
	 *
	 * @return string[]
	 */
	protected function styled_schema_keys(): array {
		return array( 'per_row', 'gap', 'border_radius' );
	}

	/**
	 * Style tab: grid layout, the tile frame, its photo, and the three text lines.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_layout_controls();
		$this->register_card_controls();
		$this->register_photo_controls();
		$this->register_typography_controls();
		$this->register_color_controls();
	}

	/**
	 * Layout — columns across and the gutter between tiles.
	 *
	 * @return void
	 */
	private function register_layout_controls(): void {
		$this->start_controls_section(
			'mlsimport_agents_layout',
			array(
				'label' => esc_html__( 'Layout', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'mlsimport_agents_columns',
			array(
				'label'          => esc_html__( 'Columns', 'mlsimport' ),
				'type'           => \Elementor\Controls_Manager::SLIDER,
				'range'          => array( 'px' => array( 'min' => 1, 'max' => 6, 'step' => 1 ) ),
				'default'        => array( 'size' => 4 ),
				'tablet_default' => array( 'size' => 3 ),
				'mobile_default' => array( 'size' => 2 ),
				'selectors'      => array( self::GRID_SELECTOR => 'grid-template-columns: repeat({{SIZE}}, minmax(0, 1fr));' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_agents_gap',
			array(
				'label'      => esc_html__( 'Gap', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( self::GRID_SELECTOR => 'gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Card — background, border, corner radius, the hover shadow and the body padding.
	 *
	 * @return void
	 */
	private function register_card_controls(): void {
		$this->start_controls_section(
			'mlsimport_agents_card',
			array(
				'label' => esc_html__( 'Card', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_agents_card_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::CARD_SELECTOR => 'background-color: {{VALUE}}' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'mlsimport_agents_card_border',
				'selector' => self::CARD_SELECTOR,
			)
		);

		$this->add_responsive_control(
			'mlsimport_agents_card_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array( 'unit' => 'px', 'top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8, 'isLinked' => true ),
				'selectors'  => array(
					self::CARD_SELECTOR => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_agents_card_shadow_hover',
				'label'    => esc_html__( 'Box Shadow (Hover)', 'mlsimport' ),
				'selector' => self::CARD_SELECTOR . ':hover',
			)
		);

		$this->add_responsive_control(
			'mlsimport_agents_card_body_padding',
			array(
				'label'      => esc_html__( 'Body Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					self::BODY_SELECTOR => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
				'separator'  => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Photo — the portrait height (object-fit stays cover, so it never distorts).
	 *
	 * @return void
	 */
	private function register_photo_controls(): void {
		$this->start_controls_section(
			'mlsimport_agents_photo',
			array(
				'label' => esc_html__( 'Photo', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'mlsimport_agents_photo_height',
			array(
				'label'      => esc_html__( 'Height', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 120, 'max' => 480 ) ),
				'selectors'  => array( self::PHOTO_SELECTOR => 'height: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Typography — the name, job title and office lines, one group each.
	 *
	 * @return void
	 */
	private function register_typography_controls(): void {
		$this->start_controls_section(
			'mlsimport_agents_typography',
			array(
				'label' => esc_html__( 'Typography', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_agents_name_typography',
				'label'    => esc_html__( 'Name', 'mlsimport' ),
				'selector' => self::NAME_SELECTOR,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_agents_title_typography',
				'label'    => esc_html__( 'Job Title', 'mlsimport' ),
				'selector' => self::TITLE_SELECTOR,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_agents_office_typography',
				'label'    => esc_html__( 'Office', 'mlsimport' ),
				'selector' => self::OFFICE_SELECTOR,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Colors — the name, job title and office lines.
	 *
	 * @return void
	 */
	private function register_color_controls(): void {
		$this->start_controls_section(
			'mlsimport_agents_colors',
			array(
				'label' => esc_html__( 'Colors', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_agents_name_color',
			array(
				'label'     => esc_html__( 'Name', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::NAME_SELECTOR => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_agents_title_color',
			array(
				'label'     => esc_html__( 'Job Title', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::TITLE_SELECTOR => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_agents_office_color',
			array(
				'label'     => esc_html__( 'Office', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::OFFICE_SELECTOR => 'color: {{VALUE}}' ),
			)
		);

		$this->end_controls_section();
	}
}

/**
 * Featured Property.
 *
 * Carries Style-tab controls modelled on WpResidence's Featured Property widget
 * (box shadow, border radius, typography, colors). WpResidence conditions its
 * typography/color controls on design 6 because each of its six templates uses
 * different classes; our six designs share one BEM contract
 * (.mlsimport-featured__title / __price / __specs), so one set of controls
 * styles all six and no conditions are needed.
 */
class Mlsimport_Elementor_Featured_Property_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	/**
	 * The elements the meta/spec controls target — the three designs name their
	 * spec row differently (bar, panel/hero, design 6's feature strip).
	 */
	const META_SELECTOR = '{{WRAPPER}} .mlsimport-featured__specs,
		{{WRAPPER}} .mlsimport-featured__specrow,
		{{WRAPPER}} .mlsimport-featured__features';

	/**
	 * The price line. Designs 1–4 and 6 use __price, but design 5 renders the price
	 * as a __label chip inside its floating box — so the price controls target both,
	 * otherwise design 5's price would silently ignore every price knob. (Design 4's
	 * __label is a "Featured Property" eyebrow, not a price, so it is scoped out by
	 * the design-5 modifier.)
	 */
	const PRICE_SELECTOR = '{{WRAPPER}} .mlsimport-featured__price,
		{{WRAPPER}} .mlsimport-featured--design-5 .mlsimport-featured__label';

	/** The "Featured" flag chip (image-based designs 1–3). */
	const FLAG_SELECTOR = '{{WRAPPER}} .mlsimport-featured__flag';

	/** The status badge — Active/Sold/… (designs 1–3 and 6). */
	const BADGE_SELECTOR = '{{WRAPPER}} .mlsimport-featured__badge';

	/** The description/excerpt paragraph (designs 3 and 5). */
	const EXCERPT_SELECTOR = '{{WRAPPER}} .mlsimport-featured__excerpt';

	/** The "Discover more" call-to-action link (designs 4 and 5). */
	const MORE_SELECTOR = '{{WRAPPER}} .mlsimport-featured__more';

	/** The photo box of the image-card designs (1–3). */
	const MEDIA_SELECTOR = '{{WRAPPER}} .mlsimport-featured__media';

	/** The full-bleed background-image hero of the hero designs (4–6). */
	const HERO_SELECTOR = '{{WRAPPER}} .mlsimport-featured__hero';

	/** The agent's name beside the face chip (designs 1 and 4 render it). */
	const AGENT_NAME_SELECTOR = '{{WRAPPER}} .mlsimport-featured__agent-name';

	protected function get_block_slug(): string {
		return 'featured_property';
	}

	/**
	 * An Elementor `condition` that shows a control only for the given design numbers.
	 * The block's Design select stores '1'..'6', so a control's visibility can track
	 * which of the six templates actually renders the element it styles.
	 *
	 * @param string[] $designs Design values the control applies to.
	 * @return array{condition:array<string,string[]>}
	 */
	private function for_designs( array $designs ): array {
		return array( 'condition' => array( 'design' => $designs ) );
	}

	/**
	 * Box shadow, corner radius, typography and colors for the card.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_box_controls();
		$this->register_typography_controls();
		$this->register_color_controls();
	}

	/**
	 * Card frame: drop shadow and corner radius.
	 *
	 * @return void
	 */
	private function register_box_controls(): void {
		$this->start_controls_section(
			'mlsimport_featured_box',
			array(
				'label' => esc_html__( 'Box', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_featured_shadow',
				'label'    => esc_html__( 'Box Shadow', 'mlsimport' ),
				'selector' => '{{WRAPPER}} .mlsimport-featured',
			)
		);

		// Rounded on the card, its photo and the full-bleed hero alike, so every
		// design corners the same way (the image would otherwise square them off).
		$this->add_responsive_control(
			'mlsimport_featured_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-featured,
					 {{WRAPPER}} .mlsimport-featured__media,
					 {{WRAPPER}} .mlsimport-featured__img,
					 {{WRAPPER}} .mlsimport-featured__hero' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
				),
			)
		);

		// Photo height for the image-card designs (1–3): overrides the design's own
		// aspect-ratio / min-height. The <img> fills the box (object-fit: cover) so it
		// never distorts.
		$this->add_responsive_control(
			'mlsimport_featured_media_height',
			array_merge(
				array(
					'label'      => esc_html__( 'Image Height', 'mlsimport' ),
					'type'       => \Elementor\Controls_Manager::SLIDER,
					'size_units' => array( 'px', 'vh' ),
					'range'      => array( 'px' => array( 'min' => 120, 'max' => 700 ) ),
					'selectors'  => array( self::MEDIA_SELECTOR => 'height: {{SIZE}}{{UNIT}}; aspect-ratio: auto;' ),
				),
				$this->for_designs( array( '1', '2', '3' ) )
			)
		);

		// Hero height for the full-bleed designs (4–6): their min-height.
		$this->add_responsive_control(
			'mlsimport_featured_hero_height',
			array_merge(
				array(
					'label'      => esc_html__( 'Hero Height', 'mlsimport' ),
					'type'       => \Elementor\Controls_Manager::SLIDER,
					'size_units' => array( 'px', 'vh' ),
					'range'      => array(
						'px' => array( 'min' => 200, 'max' => 900 ),
						'vh' => array( 'min' => 20, 'max' => 100 ),
					),
					'selectors'  => array( self::HERO_SELECTOR => 'min-height: {{SIZE}}{{UNIT}}' ),
				),
				$this->for_designs( array( '4', '5', '6' ) )
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Typography for the title, price and meta row.
	 *
	 * @return void
	 */
	private function register_typography_controls(): void {
		$this->start_controls_section(
			'mlsimport_featured_typography',
			array(
				'label' => esc_html__( 'Typography', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_featured_title_typography',
				'label'    => esc_html__( 'Title Typography', 'mlsimport' ),
				'selector' => '{{WRAPPER}} .mlsimport-featured__title, {{WRAPPER}} .mlsimport-featured__title a',
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_ACCENT ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array_merge(
				array(
					'name'     => 'mlsimport_featured_price_typography',
					'label'    => esc_html__( 'Price Typography', 'mlsimport' ),
					'selector' => self::PRICE_SELECTOR,
					'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
				),
				$this->for_designs( array( '1', '2', '3', '5', '6' ) )
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array_merge(
				array(
					'name'     => 'mlsimport_featured_meta_typography',
					'label'    => esc_html__( 'Meta Typography (Beds, Baths, Size)', 'mlsimport' ),
					'selector' => self::META_SELECTOR,
					'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
				),
				$this->for_designs( array( '1', '3', '5', '6' ) )
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array_merge(
				array(
					'name'     => 'mlsimport_featured_excerpt_typography',
					'label'    => esc_html__( 'Description Typography', 'mlsimport' ),
					'selector' => self::EXCERPT_SELECTOR,
					'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
				),
				$this->for_designs( array( '3', '5' ) )
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array_merge(
				array(
					'name'     => 'mlsimport_featured_more_typography',
					'label'    => esc_html__( '"Discover More" Typography', 'mlsimport' ),
					'selector' => self::MORE_SELECTOR,
					'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_ACCENT ),
				),
				$this->for_designs( array( '4', '5' ) )
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array_merge(
				array(
					'name'     => 'mlsimport_featured_agent_typography',
					'label'    => esc_html__( 'Agent Name Typography', 'mlsimport' ),
					'selector' => self::AGENT_NAME_SELECTOR,
					'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
				),
				$this->for_designs( array( '1', '4' ) )
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Colors for the title, price, meta row and the Featured flag.
	 *
	 * @return void
	 */
	private function register_color_controls(): void {
		$this->start_controls_section(
			'mlsimport_featured_colors',
			array(
				'label' => esc_html__( 'Colors', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_featured_title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .mlsimport-featured__title, {{WRAPPER}} .mlsimport-featured__title a' => 'color: {{VALUE}}',
				),
			)
		);

		// Price is on every design except 4 (design 5's is the __label, per PRICE_SELECTOR).
		$this->add_control(
			'mlsimport_featured_price_color',
			array_merge(
				array(
					'label'     => esc_html__( 'Price Color', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::PRICE_SELECTOR => 'color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '2', '3', '5', '6' ) )
			)
		);

		// Price chip fill — only the designs whose price is the __price element (1–3, 6);
		// design 5's price is the __label box, styled by its own box, not a chip.
		$this->add_control(
			'mlsimport_featured_price_bg',
			array_merge(
				array(
					'label'     => esc_html__( 'Price Background', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .mlsimport-featured__price' => 'background-color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '2', '3', '6' ) )
			)
		);

		// Specs row is on designs 1, 3, 5, 6 (design 2 shows only title + price).
		$this->add_control(
			'mlsimport_featured_meta_color',
			array_merge(
				array(
					'label'     => esc_html__( 'Meta Color (Beds, Baths, Size)', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::META_SELECTOR => 'color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '3', '5', '6' ) )
			)
		);

		// Description paragraph (designs 3 and 5).
		$this->add_control(
			'mlsimport_featured_excerpt_color',
			array_merge(
				array(
					'label'     => esc_html__( 'Description Color', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::EXCERPT_SELECTOR => 'color: {{VALUE}}' ),
				),
				$this->for_designs( array( '3', '5' ) )
			)
		);

		// "Discover more" call-to-action link (designs 4 and 5).
		$this->add_control(
			'mlsimport_featured_more_color',
			array_merge(
				array(
					'label'     => esc_html__( '"Discover More" Link Color', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::MORE_SELECTOR => 'color: {{VALUE}}' ),
				),
				$this->for_designs( array( '4', '5' ) )
			)
		);

		// Agent name beside the face chip (designs 1 and 4 render the name).
		$this->add_control(
			'mlsimport_featured_agent_color',
			array_merge(
				array(
					'label'     => esc_html__( 'Agent Name Color', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::AGENT_NAME_SELECTOR => 'color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '4' ) )
			)
		);

		// The "Featured" flag (image designs 1–3) — background was always here; its
		// text sits on that fill, so pair it with a text-colour knob.
		$this->add_control(
			'mlsimport_featured_flag_bg',
			array_merge(
				array(
					'label'     => esc_html__( '"Featured" Flag Background', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::FLAG_SELECTOR => 'background-color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '2', '3' ) )
			)
		);

		$this->add_control(
			'mlsimport_featured_flag_color',
			array_merge(
				array(
					'label'     => esc_html__( '"Featured" Flag Text', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::FLAG_SELECTOR => 'color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '2', '3' ) )
			)
		);

		// The status badge (Active/Sold/…) — designs 1–3 and 6. Its own fill and text,
		// independent of the "Featured" flag they sit beside.
		$this->add_control(
			'mlsimport_featured_badge_bg',
			array_merge(
				array(
					'label'     => esc_html__( 'Status Badge Background', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::BADGE_SELECTOR => 'background-color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '2', '3', '6' ) )
			)
		);

		$this->add_control(
			'mlsimport_featured_badge_color',
			array_merge(
				array(
					'label'     => esc_html__( 'Status Badge Text', 'mlsimport' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( self::BADGE_SELECTOR => 'color: {{VALUE}}' ),
				),
				$this->for_designs( array( '1', '2', '3', '6' ) )
			)
		);

		$this->end_controls_section();
	}
}

/**
 * Map with Listings.
 *
 * A map plots EVERY matching listing at its coordinates, so the only thing a builder
 * configures is which listings match — the shared Initial filters (SELECT2 term-picker
 * autocompletes for Cities / Property types / Status / …, plus price, beds, baths and
 * agent). It deliberately exposes nothing else: a map has no page size ("How many"
 * never caps it — map_payload() strips limit/page), no order (pins have no sequence),
 * and no card grid or search bar to style. So this widget is just that one section.
 */
class Mlsimport_Elementor_Map_Listings_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	use Mlsimport_Listing_Selection_Controls;

	protected function get_block_slug(): string {
		return 'map_listings';
	}

	/** The map viewport node — the one box every map style control targets. */
	const MAP_SELECTOR = '{{WRAPPER}} .mlsimport-page-block--map .mlsimport-map';

	/**
	 * The shared Initial filters (which listings become pins), then the map's own
	 * Style tab. No Settings section — a map has no page size or ID list.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_initial_filters_section();
		$this->register_style_controls();
	}

	/**
	 * The Initial filters trait ends every host's section with a sort control, but a
	 * map has no meaningful ordering (pins sit at their coordinates), so the map adds
	 * none — this satisfies the trait's contract with an intentional no-op.
	 *
	 * @return void
	 */
	protected function add_sort_control(): void {}

	/**
	 * Map box: height, corner radius, border and shadow — the only appearance a map
	 * has. Height is the one that matters: the stylesheet locks the map at 420px, so
	 * without this a builder cannot make it taller, shorter, or full-height. Every
	 * control targets the single .mlsimport-map viewport node; the full descendant
	 * selector outranks the block's own `.mlsimport-page-block--map .mlsimport-map`
	 * rule so the value actually wins.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->start_controls_section(
			'mlsimport_map_style',
			array(
				'label' => esc_html__( 'Map', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'mlsimport_map_height',
			array(
				'label'      => esc_html__( 'Height', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh' ),
				'range'      => array(
					'px' => array( 'min' => 200, 'max' => 900 ),
					'vh' => array( 'min' => 20, 'max' => 100 ),
				),
				'selectors'  => array( self::MAP_SELECTOR => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_map_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					self::MAP_SELECTOR => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'mlsimport_map_border',
				'selector' => self::MAP_SELECTOR,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_map_shadow',
				'label'    => esc_html__( 'Box Shadow', 'mlsimport' ),
				'selector' => self::MAP_SELECTOR,
			)
		);

		$this->end_controls_section();
	}
}

/** Search Form. */
class Mlsimport_Elementor_Search_Form_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	/**
	 * Every surface a search field can present as, so one set of field controls
	 * styles them all: the plain input/select, the multiselect's control, and the
	 * popup toggle the range and Beds & Baths fields close to. Without all four a
	 * colour set in the panel would land on some fields and skip others.
	 */
	const FIELD_SELECTOR = '{{WRAPPER}} .mlsimport-search-form__field input,
		{{WRAPPER}} .mlsimport-search-form__field select,
		{{WRAPPER}} .mlsimport-search-form__field .mlsimport-ms__control,
		{{WRAPPER}} .mlsimport-search-form__field .mlsimport-range__toggle,
		{{WRAPPER}} .mlsimport-search-form__field .mlsimport-bedsbaths__toggle';

	/** The field's visible label — a field's only direct <span> child. */
	const LABEL_SELECTOR = '{{WRAPPER}} .mlsimport-search-form__field > span';

	protected function get_block_slug(): string {
		return 'search_form';
	}

	/**
	 * Form frame, labels, field controls and the submit button.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_form_controls();
		$this->register_label_controls();
		$this->register_field_controls();
		$this->register_button_controls();
	}

	/**
	 * The form box itself: the grid gaps, its background, padding, corners, shadow,
	 * and the optional heading above the fields.
	 *
	 * @return void
	 */
	private function register_form_controls(): void {
		$this->start_controls_section(
			'mlsimport_search_form_box',
			array(
				'label' => esc_html__( 'Form', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		// The form is a 12-column grid, so the two gaps are set independently rather
		// than through the CSS `gap` shorthand the stylesheet declares.
		$this->add_responsive_control(
			'mlsimport_search_form_column_gap',
			array(
				'label'      => esc_html__( 'Column Gap', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-search-form' => 'column-gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_row_gap',
			array(
				'label'      => esc_html__( 'Row Gap', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-search-form' => 'row-gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->add_control(
			'mlsimport_search_form_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-search-form' => 'background-color: {{VALUE}}' ),
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_padding',
			array(
				'label'      => esc_html__( 'Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-search-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-search-form' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_search_form_shadow',
				'label'    => esc_html__( 'Box Shadow', 'mlsimport' ),
				'selector' => '{{WRAPPER}} .mlsimport-search-form',
			)
		);

		// The heading only exists when the Title arg is filled in, so its controls
		// hide with it rather than sitting dead in the panel.
		$this->add_control(
			'mlsimport_search_form_title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-search-form__title' => 'color: {{VALUE}}' ),
				'separator' => 'before',
				'condition' => array( 'title!' => '' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'mlsimport_search_form_title_typography',
				'label'     => esc_html__( 'Title Typography', 'mlsimport' ),
				'selector'  => '{{WRAPPER}} .mlsimport-search-form__title',
				'global'    => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_ACCENT ),
				'condition' => array( 'title!' => '' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The field labels. Hidden labels leave nothing to style, so these follow the
	 * "Hide labels" arg.
	 *
	 * @return void
	 */
	private function register_label_controls(): void {
		$this->start_controls_section(
			'mlsimport_search_form_labels',
			array(
				'label'     => esc_html__( 'Labels', 'mlsimport' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'hide_labels' => '' ),
			)
		);

		$this->add_control(
			'mlsimport_search_form_label_color',
			array(
				'label'     => esc_html__( 'Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::LABEL_SELECTOR => 'color: {{VALUE}}' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_search_form_label_typography',
				'label'    => esc_html__( 'Typography', 'mlsimport' ),
				'selector' => self::LABEL_SELECTOR,
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
			)
		);

		// The field wrapper is a flex column, so the label's distance from its control
		// is the wrapper's gap rather than a margin on the label.
		$this->add_responsive_control(
			'mlsimport_search_form_label_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-search-form__field' => 'gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The field controls themselves — inputs, selects, multiselects and popup
	 * toggles, all styled together through FIELD_SELECTOR.
	 *
	 * @return void
	 */
	private function register_field_controls(): void {
		$this->start_controls_section(
			'mlsimport_search_form_fields',
			array(
				'label' => esc_html__( 'Fields', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_search_form_field_color',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				// The swatch colours real text (inputs, toggles) AND every field's empty
				// "placeholder" text, so the colour lands on all fields, not just the ones
				// showing a typed value. Placeholder text lives in a different place per
				// field type: an input's ::placeholder, the multiselect's own placeholder
				// span, and each popup toggle's muted .is-empty label.
				'selectors' => array(
					self::FIELD_SELECTOR => 'color: {{VALUE}}',
					'{{WRAPPER}} .mlsimport-search-form__field input::placeholder,
					{{WRAPPER}} .mlsimport-search-form__field .mlsimport-ms__placeholder,
					{{WRAPPER}} .mlsimport-search-form__field .mlsimport-range__toggle.is-empty,
					{{WRAPPER}} .mlsimport-search-form__field .mlsimport-bedsbaths__toggle.is-empty' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'mlsimport_search_form_field_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::FIELD_SELECTOR => 'background-color: {{VALUE}}' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_search_form_field_typography',
				'label'    => esc_html__( 'Typography', 'mlsimport' ),
				'selector' => self::FIELD_SELECTOR,
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_field_padding',
			array(
				'label'      => esc_html__( 'Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					self::FIELD_SELECTOR => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
				'separator'  => 'before',
			)
		);

		$this->add_control(
			'mlsimport_search_form_field_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::FIELD_SELECTOR => 'border-color: {{VALUE}}' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_field_border_width',
			array(
				'label'      => esc_html__( 'Border Width', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					self::FIELD_SELECTOR => 'border-style: solid; border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_field_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					self::FIELD_SELECTOR => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The submit button, in normal and hover states.
	 *
	 * @return void
	 */
	private function register_button_controls(): void {
		$this->start_controls_section(
			'mlsimport_search_form_button',
			array(
				'label' => esc_html__( 'Button', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'mlsimport_search_form_button_states' );

		$this->start_controls_tab(
			'mlsimport_search_form_button_normal',
			array( 'label' => esc_html__( 'Normal', 'mlsimport' ) )
		);

		// The Button Color arg prints an inline background on the element, which would
		// outrank a plain selector rule — so this one is forced. It stays as the
		// Elementor-side override of that arg rather than a competing setting.
		$this->add_control(
			'mlsimport_search_form_button_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-search-form__submit' => 'background-color: {{VALUE}} !important' ),
			)
		);

		$this->add_control(
			'mlsimport_search_form_button_color',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-search-form__submit' => 'color: {{VALUE}}' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'mlsimport_search_form_button_hover',
			array( 'label' => esc_html__( 'Hover', 'mlsimport' ) )
		);

		$this->add_control(
			'mlsimport_search_form_button_background_hover',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-search-form__submit:hover' => 'background-color: {{VALUE}} !important' ),
			)
		);

		$this->add_control(
			'mlsimport_search_form_button_color_hover',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-search-form__submit:hover' => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_search_form_button_border_color_hover',
			array(
				'label'     => esc_html__( 'Border Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-search-form__submit:hover' => 'border-color: {{VALUE}}' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		// State-independent button styling, below the Normal/Hover tabs.
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'mlsimport_search_form_button_typography',
				'label'     => esc_html__( 'Typography', 'mlsimport' ),
				'selector'  => '{{WRAPPER}} .mlsimport-search-form__submit',
				'global'    => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_ACCENT ),
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'mlsimport_search_form_button_border',
				'selector' => '{{WRAPPER}} .mlsimport-search-form__submit',
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_button_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-search-form__submit' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		// Overrides the padding the Button Size arg sets, for a size between the three.
		$this->add_responsive_control(
			'mlsimport_search_form_button_padding',
			array(
				'label'      => esc_html__( 'Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-search-form__submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		// The icon is sized in em against the button's font-size, so this overrides it
		// only when the builder wants the glyph off that scale.
		$this->add_responsive_control(
			'mlsimport_search_form_button_icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 6, 'max' => 60 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-search-form__submit-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
				),
				'condition'  => array( 'button_icon[url]!' => '' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_search_form_button_icon_gap',
			array(
				'label'      => esc_html__( 'Icon Spacing', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-search-form__submit' => 'gap: {{SIZE}}{{UNIT}}' ),
				'condition'  => array( 'button_icon[url]!' => '' ),
			)
		);

		$this->end_controls_section();
	}
}

/**
 * Contact Form.
 *
 * Style tab modelled on WpResidence's Contact Form Builder widget (Form, Field
 * Style, GDPR and Button sections). WpResidence styles Elementor's own
 * .elementor-field-group / .elementor-field markup, which only exists inside
 * Elementor; ours targets the block's BEM contract, so the same knobs apply to the
 * form however it was placed. The button's Background is forced past the inline
 * tint the Button Color arg prints, the way the Search Form's is.
 */
class Mlsimport_Elementor_Contact_Form_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	/**
	 * Every control a contact row can render as, so one set of field controls styles
	 * them all — the text/email/tel inputs, the message textarea and the dropdown.
	 * Checkboxes and radios are excluded: sizing a tick box like a text input would
	 * blow it up.
	 */
	const FIELD_SELECTOR = '{{WRAPPER}} .mlsimport-contact-form__field input:not([type="checkbox"]):not([type="radio"]),
		{{WRAPPER}} .mlsimport-contact-form__field select,
		{{WRAPPER}} .mlsimport-contact-form__field textarea';

	/** A field's visible caption — its direct <span>, or a radio group's legend. */
	const LABEL_SELECTOR = '{{WRAPPER}} .mlsimport-contact-form__field > span,
		{{WRAPPER}} .mlsimport-contact-form__field > legend';

	/** The submit button. */
	const BUTTON_SELECTOR = '{{WRAPPER}} .mlsimport-contact-form__submit';

	protected function get_block_slug(): string {
		return 'contact_form';
	}

	/**
	 * Button Color is dropped from the Content tab: the Style tab's Background control
	 * sets the same property and having both would be two controls for one colour.
	 * Field/button SIZE stay on the Content tab — they are presets the stylesheet
	 * enumerates (padding + font-size together), not a single CSS property.
	 *
	 * @return string[]
	 */
	protected function styled_schema_keys(): array {
		return array( 'button_color' );
	}

	/**
	 * Form frame, labels, fields, consent row and the submit button.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_form_controls();
		$this->register_label_controls();
		$this->register_field_controls();
		$this->register_consent_controls();
		$this->register_button_controls();
	}

	/**
	 * The form box: the grid gaps, background, padding, corners, shadow, and the
	 * optional heading above the fields.
	 *
	 * @return void
	 */
	private function register_form_controls(): void {
		$this->start_controls_section(
			'mlsimport_contact_form_box',
			array(
				'label' => esc_html__( 'Form', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		// The form is a 12-column grid, so the two gaps are set independently rather
		// than through the CSS `gap` shorthand the stylesheet declares.
		$this->add_responsive_control(
			'mlsimport_contact_form_column_gap',
			array(
				'label'      => esc_html__( 'Column Gap', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-contact-form' => 'column-gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_contact_form_row_gap',
			array(
				'label'      => esc_html__( 'Row Gap', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-contact-form' => 'row-gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-contact-form' => 'background-color: {{VALUE}}' ),
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'mlsimport_contact_form_padding',
			array(
				'label'      => esc_html__( 'Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-contact-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'mlsimport_contact_form_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-contact-form' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_contact_form_shadow',
				'label'    => esc_html__( 'Box Shadow', 'mlsimport' ),
				'selector' => '{{WRAPPER}} .mlsimport-contact-form',
			)
		);

		// The heading only exists when the Title arg is filled in, so its controls hide
		// with it rather than sitting dead in the panel.
		$this->add_control(
			'mlsimport_contact_form_title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-contact-form__title' => 'color: {{VALUE}}' ),
				'separator' => 'before',
				'condition' => array( 'title!' => '' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'mlsimport_contact_form_title_typography',
				'label'     => esc_html__( 'Title Typography', 'mlsimport' ),
				'selector'  => '{{WRAPPER}} .mlsimport-contact-form__title',
				'global'    => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_ACCENT ),
				'condition' => array( 'title!' => '' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The field labels. Hidden labels leave nothing to style, so these follow the
	 * "Hide labels" arg.
	 *
	 * @return void
	 */
	private function register_label_controls(): void {
		$this->start_controls_section(
			'mlsimport_contact_form_labels',
			array(
				'label'     => esc_html__( 'Labels', 'mlsimport' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'hide_labels' => '' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_label_color',
			array(
				'label'     => esc_html__( 'Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::LABEL_SELECTOR => 'color: {{VALUE}}' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_contact_form_label_typography',
				'label'    => esc_html__( 'Typography', 'mlsimport' ),
				'selector' => self::LABEL_SELECTOR,
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
			)
		);

		// The field wrapper is a flex column, so the label's distance from its control
		// is the wrapper's gap rather than a margin on the label.
		$this->add_responsive_control(
			'mlsimport_contact_form_label_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-contact-form__field' => 'gap: {{SIZE}}{{UNIT}}' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The inputs, textarea and dropdowns, styled together through FIELD_SELECTOR.
	 *
	 * @return void
	 */
	private function register_field_controls(): void {
		$this->start_controls_section(
			'mlsimport_contact_form_fields',
			array(
				'label' => esc_html__( 'Fields', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_contact_form_field_color',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::FIELD_SELECTOR => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_field_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::FIELD_SELECTOR => 'background-color: {{VALUE}}' ),
			)
		);

		// The placeholder is the only text the field shows before it is filled in, and
		// with labels hidden it carries the field's name — so it gets its own colour.
		$this->add_control(
			'mlsimport_contact_form_placeholder_color',
			array(
				'label'     => esc_html__( 'Placeholder Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::FIELD_SELECTOR . '::placeholder' => 'color: {{VALUE}}' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_contact_form_field_typography',
				'label'    => esc_html__( 'Typography', 'mlsimport' ),
				'selector' => self::FIELD_SELECTOR,
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_contact_form_field_padding',
			array(
				'label'      => esc_html__( 'Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					self::FIELD_SELECTOR => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
				'separator'  => 'before',
			)
		);

		$this->add_control(
			'mlsimport_contact_form_field_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::FIELD_SELECTOR => 'border-color: {{VALUE}}' ),
			)
		);

		$this->add_responsive_control(
			'mlsimport_contact_form_field_border_width',
			array(
				'label'      => esc_html__( 'Border Width', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					self::FIELD_SELECTOR => 'border-style: solid; border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->add_responsive_control(
			'mlsimport_contact_form_field_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					self::FIELD_SELECTOR => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The consent row. Mirrors WpResidence's GDPR section — text colour, the link's
	 * own colour, and typography — and hides unless the checkbox is switched on.
	 *
	 * @return void
	 */
	private function register_consent_controls(): void {
		$this->start_controls_section(
			'mlsimport_contact_form_consent',
			array(
				'label'     => esc_html__( 'Consent', 'mlsimport' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_consent!' => '' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_consent_color',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-contact-form__consent' => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_consent_link_color',
			array(
				'label'     => esc_html__( 'Link Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-contact-form__consent a' => 'color: {{VALUE}}' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'           => 'mlsimport_contact_form_consent_typography',
				'label'          => esc_html__( 'Typography', 'mlsimport' ),
				'selector'       => '{{WRAPPER}} .mlsimport-contact-form__consent',
				'global'         => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
				'fields_options' => array( 'font_weight' => array( 'default' => '300' ) ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The submit button, in normal and hover states.
	 *
	 * @return void
	 */
	private function register_button_controls(): void {
		$this->start_controls_section(
			'mlsimport_contact_form_button',
			array(
				'label' => esc_html__( 'Button', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'mlsimport_contact_form_button_states' );

		$this->start_controls_tab(
			'mlsimport_contact_form_button_normal',
			array( 'label' => esc_html__( 'Normal', 'mlsimport' ) )
		);

		// No !important needed, unlike the Search Form's: Button Color is hidden from
		// this widget's Content tab (styled_schema_keys), so it never reaches the render
		// and there is no inline background to outrank.
		$this->add_control(
			'mlsimport_contact_form_button_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::BUTTON_SELECTOR => 'background-color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_button_color',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::BUTTON_SELECTOR => 'color: {{VALUE}}' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'mlsimport_contact_form_button_hover',
			array( 'label' => esc_html__( 'Hover', 'mlsimport' ) )
		);

		$this->add_control(
			'mlsimport_contact_form_button_background_hover',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::BUTTON_SELECTOR . ':hover' => 'background-color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_button_color_hover',
			array(
				'label'     => esc_html__( 'Text Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::BUTTON_SELECTOR . ':hover' => 'color: {{VALUE}}' ),
			)
		);

		$this->add_control(
			'mlsimport_contact_form_button_border_color_hover',
			array(
				'label'     => esc_html__( 'Border Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::BUTTON_SELECTOR . ':hover' => 'border-color: {{VALUE}}' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		// State-independent button styling, below the Normal/Hover tabs.
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'mlsimport_contact_form_button_typography',
				'label'     => esc_html__( 'Typography', 'mlsimport' ),
				'selector'  => self::BUTTON_SELECTOR,
				'global'    => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_ACCENT ),
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'mlsimport_contact_form_button_border',
				'selector' => self::BUTTON_SELECTOR,
			)
		);

		$this->add_responsive_control(
			'mlsimport_contact_form_button_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					self::BUTTON_SELECTOR => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		// Overrides the padding the Button Size arg sets, for a size between the three.
		$this->add_responsive_control(
			'mlsimport_contact_form_button_padding',
			array(
				'label'      => esc_html__( 'Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					self::BUTTON_SELECTOR => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}',
				),
			)
		);

		$this->end_controls_section();
	}
}

/**
 * Half Map (search bar + AJAX results grid beside a live map).
 *
 * Like Property List, this widget does NOT walk the arg schema — the schema is the
 * full listings filter set, so a raw walk emitted ~38 plain text boxes (city, price,
 * beds … but also machinery no builder should set by hand: orderby, order, limit,
 * lat/lng bounds, polygon), which made the panel unusable. Instead it mirrors the
 * Gutenberg Half Map inspector exactly: a Settings section (how many + fields-per-row),
 * the shared Initial filters (rich taxonomy pickers + price/beds/baths/agent + a
 * friendly Order preset), the per-field search-bar toggles, and a map Layout section
 * (side + height). Unregistered schema keys (the geo bounds, polygon, page) are simply
 * never forwarded — render_settings() skips any key absent from the widget's settings —
 * so they stay reachable from the shortcode without cluttering the builder.
 */
class Mlsimport_Elementor_Half_Map_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	use Mlsimport_Listing_Card_Style_Controls;
	use Mlsimport_Listing_Selection_Controls;
	use Mlsimport_Listing_Search_Bar_Controls;

	protected function get_block_slug(): string {
		return 'half_map';
	}

	/**
	 * Settings, the shared Initial filters, the per-field search toggles, the map
	 * Layout section — the same Content panels, in the same order, as the Gutenberg
	 * block — then the shared listing-card Style tab. The list pane renders the same
	 * .mlsimport-results__grid of .mlsimport-listing-card boxes as the Property List,
	 * so the Card Style trait's Columns / gaps / card-frame controls style it exactly
	 * the same way (the Columns control is the "cards per row" the pane never exposed).
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_settings_section();
		$this->register_initial_filters_section();
		$this->register_search_fields_section();
		$this->register_layout_section();
		$this->register_style_controls();
	}

	/**
	 * Settings — how many listings the results pane loads (the `limit` the render's
	 * grid pane pages by) and how many search fields the bar lays out per row. Mirrors
	 * the Gutenberg listingsPanel; fields-per-row defaults to 3, as it does there.
	 *
	 * @return void
	 */
	private function register_settings_section(): void {
		$this->start_controls_section( 'mlsimport_settings', array( 'label' => __( 'Settings', 'mlsimport' ) ) );
		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Properties to display', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
			)
		);
		$this->add_control(
			'fields_per_row',
			array(
				'label'   => __( 'Search fields per row', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array( '3' => '3', '4' => '4', '5' => '5', '6' => '6' ),
				'default' => '3',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Layout — which side the map sits on and the block's height (any CSS length, e.g.
	 * 100vh). The same two controls as the Gutenberg layoutPanel.
	 *
	 * @return void
	 */
	private function register_layout_section(): void {
		$this->start_controls_section( 'mlsimport_layout', array( 'label' => __( 'Map layout', 'mlsimport' ) ) );
		$this->add_control(
			'map_side',
			array(
				'label'   => __( 'Map side', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array( 'right' => __( 'Right', 'mlsimport' ), 'left' => __( 'Left', 'mlsimport' ) ),
				'default' => 'right',
			)
		);
		$this->add_control(
			'height',
			array(
				'label'       => __( 'Height (CSS, e.g. 100vh)', 'mlsimport' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '100vh',
				'label_block' => true,
			)
		);
		$this->end_controls_section();
	}
}

/**
 * Shared Style tab for the two Category widgets.
 *
 * Modelled on WpResidence's Category Slider (places-slider) and Display Categories
 * widgets: an Item Settings section (height, radius, interior padding), Typography,
 * Colors and Box Shadow. WpResidence needs a per-design-type selector list and
 * `condition` clauses because each of its four "places" templates uses different
 * classes; our tile is one BEM contract (.mlsimport-cat-tile__img / __overlay /
 * __body / __title / __tagline) shared by every design, so one selector set styles
 * them all.
 *
 * The one naming difference: WpResidence splits "tagline" from "listings number".
 * Our tile has a single sub-line — the listing count — so those two collapse into
 * one Listing Count control group.
 */
abstract class Mlsimport_Elementor_Category_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	/** The tile's photo layer, and the tile itself — both corner together. */
	const RADIUS_SELECTOR = '{{WRAPPER}} .mlsimport-cat-tile,
		{{WRAPPER}} .mlsimport-cat-tile__img';

	/**
	 * A one-line "where do the tile images come from" note ahead of the schema-built
	 * Content controls, then the base panel. Each tile's photo is the taxonomy TERM's
	 * featured image — set on the term, not in the widget — which is not obvious from
	 * the widget alone, so this points the builder straight at it.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'mlsimport_cat_help',
			array( 'label' => esc_html__( 'Category images', 'mlsimport' ) )
		);
		$this->add_control(
			'mlsimport_cat_help_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Each tile shows the category term\'s featured image. Add one on the term itself — e.g. Properties → Cities (or Property Types) → edit a term → Image. Terms without an image show a plain placeholder tile.', 'mlsimport' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		$this->end_controls_section();

		parent::register_controls();
	}

	/**
	 * Item Settings → Typography → Colors → Box Shadow, then whatever the concrete
	 * widget adds (slider arrows / the design-3 frame).
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_item_controls();
		$this->register_typography_controls();
		$this->register_color_controls();
		$this->register_shadow_controls();
	}

	/**
	 * Tile box: height, background, border, corner radius, interior text padding —
	 * the card unit's frame. Background sits under the photo (visible on an image-less
	 * term and while the photo loads); the Border group and the radius corner the tile
	 * and its photo together (RADIUS_SELECTOR) so the frame reads as one card.
	 *
	 * @return void
	 */
	protected function register_item_controls(): void {
		$this->start_controls_section(
			'mlsimport_cat_item',
			array(
				'label' => esc_html__( 'Item Settings', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'mlsimport_cat_height',
			array(
				'label'      => esc_html__( 'Item Height', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh' ),
				'range'      => array( 'px' => array( 'min' => 150, 'max' => 500 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 240 ),
				'selectors'  => array( '{{WRAPPER}} .mlsimport-cat-tile' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'mlsimport_cat_background',
			array(
				'label'     => esc_html__( 'Background', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-tile' => 'background-color: {{VALUE}};' ),
			)
		);

		// One frame border for the card unit, applied to every design (the tile has
		// overflow:hidden, so the border frames the photo cleanly on designs 1 and 2 as
		// well as design 3's caption layout). Shared by BOTH Category widgets.
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'mlsimport_cat_border',
				'label'    => esc_html__( 'Item Border', 'mlsimport' ),
				'selector' => '{{WRAPPER}} .mlsimport-cat-tile',
			)
		);

		$this->add_responsive_control(
			'mlsimport_cat_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					self::RADIUS_SELECTOR => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'mlsimport_cat_padding',
			array(
				'label'      => esc_html__( 'Text Padding', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .mlsimport-cat-tile__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Title and listing-count typography, plus their bottom margins.
	 *
	 * @return void
	 */
	protected function register_typography_controls(): void {
		$this->start_controls_section(
			'mlsimport_cat_typography',
			array(
				'label' => esc_html__( 'Typography', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'           => 'mlsimport_cat_title_typography',
				'label'          => esc_html__( 'Title Typography', 'mlsimport' ),
				'selector'       => '{{WRAPPER}} .mlsimport-cat-tile__title',
				'global'         => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_PRIMARY ),
				'fields_options' => array(
					'font_weight' => array( 'default' => '600' ),
					'font_size'   => array( 'default' => array( 'unit' => 'px', 'size' => 18 ) ),
				),
			)
		);

		$this->add_responsive_control(
			'mlsimport_cat_title_margin',
			array(
				'label'     => esc_html__( 'Title Margin Bottom', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 0, 'max' => 100 ) ),
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-tile__title' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'           => 'mlsimport_cat_count_typography',
				'label'          => esc_html__( 'Listing Count Typography', 'mlsimport' ),
				'selector'       => '{{WRAPPER}} .mlsimport-cat-tile__tagline',
				'global'         => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
				'fields_options' => array(
					'font_weight' => array( 'default' => '300' ),
					'font_size'   => array( 'default' => array( 'unit' => 'px', 'size' => 13 ) ),
				),
			)
		);

		$this->add_responsive_control(
			'mlsimport_cat_count_margin',
			array(
				'label'     => esc_html__( 'Listing Count Margin Bottom', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 0, 'max' => 100 ) ),
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-tile__tagline' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Title, listing count and the image overlay — resting and hover.
	 *
	 * @return void
	 */
	protected function register_color_controls(): void {
		$this->start_controls_section(
			'mlsimport_cat_colors',
			array(
				'label' => esc_html__( 'Colors', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_cat_title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-tile__title' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'mlsimport_cat_title_hover_color',
			array(
				'label'     => esc_html__( 'Title Hover Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-tile:hover .mlsimport-cat-tile__title' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'mlsimport_cat_count_color',
			array(
				'label'     => esc_html__( 'Listing Count Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-tile__tagline' => 'color: {{VALUE}};' ),
			)
		);

		// Design 3's solid caption bar — the dark strip that holds BOTH the title and
		// the listing count (.mlsimport-cat-tile__body). Designs 1 and 2 have no bar (the
		// text sits over the photo scrim/dim), so this only appears when Design 3 is
		// selected — the `design` content control both Category widgets carry drives it.
		$this->add_control(
			'mlsimport_cat_caption_bg',
			array(
				'label'     => esc_html__( 'Caption Background (Design 3)', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-tile__body' => 'background: {{VALUE}};' ),
				'condition' => array( 'design' => '3' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Tile drop shadow.
	 *
	 * @return void
	 */
	protected function register_shadow_controls(): void {
		$this->start_controls_section(
			'mlsimport_cat_shadow',
			array(
				'label' => esc_html__( 'Box Shadow', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_cat_box_shadow',
				'label'    => esc_html__( 'Box Shadow', 'mlsimport' ),
				'selector' => '{{WRAPPER}} .mlsimport-cat-tile',
			)
		);

		$this->end_controls_section();
	}
}

/**
 * Category Slider.
 *
 * Adds the arrow customization section WpResidence's places-slider carries. `gap`
 * stays on the Content tab: it is not plain CSS here — the render function emits it
 * as data-gap for Splide, which writes the spacing inline after it mounts.
 */
class Mlsimport_Elementor_Category_Slider_Widget extends Mlsimport_Elementor_Category_Widget {

	/** The two Splide arrows. */
	const ARROW_SELECTOR = '{{WRAPPER}} .mlsimport-category-slider .splide__arrow';

	protected function get_block_slug(): string {
		return 'category_slider';
	}

	protected function styled_schema_keys(): array {
		return array( 'item_height', 'border_radius', 'text_padding', 'title_margin', 'tagline_margin', 'title_size', 'title_color' );
	}

	protected function register_style_controls(): void {
		parent::register_style_controls();
		$this->register_arrow_controls();
	}

	/**
	 * Splide arrow colours (resting and hover) and shadow. Splide draws its arrow as
	 * an inline SVG, so the icon colour is a fill, not a colour.
	 *
	 * @return void
	 */
	private function register_arrow_controls(): void {
		$this->start_controls_section(
			'mlsimport_cat_arrows',
			array(
				'label' => esc_html__( 'Slider Arrows', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'mlsimport_cat_arrow_bg',
			array(
				'label'     => esc_html__( 'Arrow Background Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::ARROW_SELECTOR => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'mlsimport_cat_arrow_icon',
			array(
				'label'     => esc_html__( 'Arrow Icon Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::ARROW_SELECTOR . ' svg' => 'fill: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'mlsimport_cat_arrow_bg_hover',
			array(
				'label'     => esc_html__( 'Arrow Hover Background Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::ARROW_SELECTOR . ':hover' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'mlsimport_cat_arrow_icon_hover',
			array(
				'label'     => esc_html__( 'Arrow Hover Icon Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::ARROW_SELECTOR . ':hover svg' => 'fill: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'mlsimport_cat_arrow_shadow',
				'label'    => esc_html__( 'Arrow Box Shadow', 'mlsimport' ),
				'selector' => self::ARROW_SELECTOR,
			)
		);

		$this->end_controls_section();
	}
}

/**
 * Display Categories.
 *
 * Adds the grid gap and the design-3 frame border to the shared Style tab. The
 * column count, the auto-grid toggle and its minimum unit width stay on the Content
 * tab: they decide the grid's structure, and the minimum width is only meaningful
 * next to the toggle that switches it on.
 */
class Mlsimport_Elementor_Category_List_Widget extends Mlsimport_Elementor_Category_Widget {

	protected function get_block_slug(): string {
		return 'category_list';
	}

	protected function styled_schema_keys(): array {
		return array( 'item_height', 'gap', 'border_radius', 'text_padding', 'title_size', 'title_color', 'border_width', 'border_color' );
	}

	protected function register_item_controls(): void {
		parent::register_item_controls();
		$this->register_grid_controls();
	}

	/**
	 * Gap between tiles. (The Design-3 frame border is the shared Item Border control
	 * from the abstract base, so it is not repeated here.)
	 *
	 * @return void
	 */
	private function register_grid_controls(): void {
		$this->start_controls_section(
			'mlsimport_cat_grid',
			array(
				'label' => esc_html__( 'Grid', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'mlsimport_cat_gap',
			array(
				'label'     => esc_html__( 'Gap Between Items', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array( 'px' => array( 'min' => 0, 'max' => 100 ) ),
				'default'   => array( 'unit' => 'px', 'size' => 16 ),
				'selectors' => array( '{{WRAPPER}} .mlsimport-cat-list' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}
}

/** Saved Properties. */
class Mlsimport_Elementor_Saved_Widget extends Mlsimport_Elementor_Page_Block_Widget {
	protected function get_block_slug(): string {
		return 'saved';
	}
}

/**
 * One widget instance per page block, for registration with Elementor.
 *
 * @return Mlsimport_Elementor_Page_Block_Widget[]
 */
function mlsimport_elementor_page_block_widgets(): array {
	return array(
		new Mlsimport_Elementor_Item_List_Widget(),
		new Mlsimport_Elementor_Property_List_Filters_Widget(),
		new Mlsimport_Elementor_List_By_Id_Widget(),
		new Mlsimport_Elementor_Content_Slider_Widget(),
		new Mlsimport_Elementor_Agents_Directory_Widget(),
		new Mlsimport_Elementor_Featured_Property_Widget(),
		new Mlsimport_Elementor_Map_Listings_Widget(),
		new Mlsimport_Elementor_Search_Form_Widget(),
		new Mlsimport_Elementor_Contact_Form_Widget(),
		new Mlsimport_Elementor_Half_Map_Widget(),
		new Mlsimport_Elementor_Category_Slider_Widget(),
		new Mlsimport_Elementor_Category_List_Widget(),
		new Mlsimport_Elementor_Saved_Widget(),
	);
}
