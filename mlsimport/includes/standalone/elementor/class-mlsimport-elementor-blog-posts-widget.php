<?php
/**
 * Elementor widget: Blog Posts (the blog_posts page block).
 *
 * The Content tab comes from the block's option schema through the base
 * widget (page-blocks-blog.php: count, categories, order, columns per
 * device, the show / hide switches, the pager switch). This class adds the
 * Style tab: the card background and corner radius, the image height, the
 * title color and typography. Every control name here is the one the block's
 * style table names (mlsimport_page_block_style_blog()), so the AI tool
 * update-page-block writes the same controls the editor shows.
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/standalone/elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blog Posts.
 */
class Mlsimport_Elementor_Blog_Posts_Widget extends Mlsimport_Elementor_Page_Block_Widget {

	/** The card box. */
	const CARD = '{{WRAPPER}} .mlsimport-blog-card';

	/**
	 * The block this widget renders.
	 *
	 * @return string
	 */
	protected function get_block_slug(): string {
		return 'blog_posts';
	}

	/**
	 * Style tab: the card, then the title.
	 *
	 * @return void
	 */
	protected function register_style_controls(): void {
		$this->register_card_controls();
		$this->register_title_controls();
	}

	/**
	 * Card: background, corner radius, image height.
	 *
	 * @return void
	 */
	private function register_card_controls(): void {
		$this->start_controls_section(
			'mlsimport_blog_card',
			array(
				'label' => esc_html__( 'Card', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'mlsimport_blog_card_background',
			array(
				'label'     => esc_html__( 'Background Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::CARD => 'background-color: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'mlsimport_blog_card_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( self::CARD => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'mlsimport_blog_image_height',
			array(
				'label'      => esc_html__( 'Image Height', 'mlsimport' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 80,
						'max' => 600,
					),
				),
				'selectors'  => array( self::CARD . '__media' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Title: color and typography.
	 *
	 * @return void
	 */
	private function register_title_controls(): void {
		$this->start_controls_section(
			'mlsimport_blog_title',
			array(
				'label' => esc_html__( 'Title', 'mlsimport' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'mlsimport_blog_title_color',
			array(
				'label'     => esc_html__( 'Color', 'mlsimport' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( self::CARD . '__title a' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'mlsimport_blog_title_typography',
				'label'    => esc_html__( 'Typography', 'mlsimport' ),
				'selector' => self::CARD . '__title',
			)
		);
		$this->end_controls_section();
	}
}
