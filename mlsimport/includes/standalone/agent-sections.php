<?php
/**
 * Standalone (theme_id 990) single-agent profile sections.
 *
 * The agent page mirrors the single-property page's section principles: a view
 * model read once (mlsimport_agent_data), section render fns that return one
 * HTML string each, the shared section-card shell (mlsimport_property_section_open/
 * close) and icon set, and on-demand asset enqueueing. The page itself is a fixed
 * editorial layout — an Editorial hero, a sticky sub-nav, and a content column
 * (About, Listings, Credentials) beside a sticky contact rail — so unlike the
 * property page there is no reorderable registry here.
 *
 * Data degrades gracefully: agents carry only the meta the importer stores
 * (name, photo, bio, email, preferred phone, office, agent MLS id), so any block
 * with no value is omitted rather than rendered empty.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-sections.php';
require_once __DIR__ . '/class-mlsimport-property-lead.php';

/**
 * The agent view model — the single value every agent section reads. Cached
 * per request so repeated section calls don't re-query.
 *
 * @param int $id Agent post ID (0 = current loop post).
 * @return array
 */
function mlsimport_agent_data( int $id = 0 ): array {
	// Per-request memoisation keyed by agent id.
	static $cache = array();

	// Default to the current loop post; bail unless it's a real agent post.
	$id = $id ? $id : (int) get_the_ID();
	if ( ! $id || 'mlsimport_agent' !== get_post_type( $id ) ) {
		return array();
	}
	// Return the cached view model when already built this request.
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}

	// Local reader for the agent's mlsimport_<key> meta as a string.
	$meta = static function ( $key ) use ( $id ) {
		return (string) get_post_meta( $id, 'mlsimport_' . $key, true );
	};

	// Title, its first word (used for "Call <first>"), and raw post body.
	$name    = (string) get_the_title( $id );
	$first   = trim( (string) strtok( $name, ' ' ) );
	$content = (string) get_post_field( 'post_content', $id );

	// Assemble the view model — meta plus derived/formatted display values.
	$vm = array(
		'id'         => $id,
		'name'       => $name,
		'first'      => '' !== $first ? $first : $name,
		'permalink'  => (string) get_permalink( $id ),
		'photo_id'   => (int) get_post_thumbnail_id( $id ),
		'bio_html'   => '' !== $content ? (string) apply_filters( 'the_content', $content ) : '',
		// Hero teaser: the operator's dedicated Teaser field when set, else a 42-word
		// trim of the full bio so agents saved before the field existed still read well.
		'bio_teaser' => '' !== $meta( 'teaser' ) ? $meta( 'teaser' ) : ( '' !== $content ? wp_trim_words( wp_strip_all_tags( $content ), 42 ) : '' ),
		'email'      => $meta( 'ListAgentEmail' ),
		'phone'      => $meta( 'ListAgentPreferredPhone' ),
		'office'     => $meta( 'ListOfficeName' ),
		'mls_id'     => $meta( 'ListAgentMlsId' ),
		/** Filter the agent's eyebrow/title label — the metabox's JobTitle, else a default. @since 6.4 */
		'title'      => (string) apply_filters( 'mlsimport_agent_title', '' !== $meta( 'JobTitle' ) ? $meta( 'JobTitle' ) : __( 'Real Estate Agent', 'mlsimport' ), $id ),
		// Operator ticked the "Verified agent" checkbox (stored as mlsimport_featured);
		// gates the "Verified Agent" hero badge.
		'verified'   => '1' === $meta( 'featured' ),
	);

	// The agent's published listings (ids; count derives from it).
	$vm['listing_ids'] = get_posts(
		array(
			'post_type'      => 'mlsimport_property',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- agent's own listings.
			'meta_key'       => 'mlsimport_list_agent_id',
			'meta_value'     => $id,
		)
	);

	/** Filter the agent view model. @since 6.4 */
	$vm = (array) apply_filters( 'mlsimport_agent_data', $vm, $id );

	// Cache and return.
	$cache[ $id ] = $vm;
	return $vm;
}

/**
 * Enqueue the agent profile's assets: the shared design tokens + section shell,
 * the agent stylesheet, and the reused sub-nav + lead scripts. Card/grid styles
 * ride along on the always-enqueued mlsimport-listings stylesheet.
 *
 * @return void
 */
function mlsimport_agent_enqueue(): void {
	// Guard for non-WP contexts.
	if ( ! function_exists( 'wp_enqueue_style' ) ) {
		return;
	}
	// Make sure the shared section design tokens + shell are registered.
	Mlsimport_Property_Section_Assets::ensure_registered();

	// Shared base style, then the agent stylesheet if it was registered.
	wp_enqueue_style( Mlsimport_Property_Section_Assets::BASE_STYLE );
	if ( wp_style_is( 'mlsimport-agent', 'registered' ) ) {
		wp_enqueue_style( 'mlsimport-agent' );
	}
	// Reuse the property sub-nav (scrollspy) and lead-form scripts.
	wp_enqueue_script( 'mlsimport-property-subnav' );
	wp_enqueue_script( 'mlsimport-property-lead' );
}

/**
 * Pre-enqueue the agent assets into the <head> on a single agent page. The
 * template prints the header before any section renders, so the on-demand
 * enqueue at render time lands in the footer and flashes unstyled (issue #188).
 * The in-template call stays and no-ops for the already-queued handles.
 *
 * @return void
 */
function mlsimport_agent_enqueue_for_single(): void {
	if ( ! function_exists( 'is_singular' ) || ! is_singular( 'mlsimport_agent' ) ) {
		return;
	}
	mlsimport_agent_enqueue();
}

/**
 * One icon + label + value contact row used in the hero's direct-contact block.
 *
 * @param string $icon  Icon name (mlsimport_property_icon).
 * @param string $label Field label.
 * @param string $value Display value.
 * @param string $href  Link target (tel:/mailto:/https:).
 * @return string HTML, or '' when the value is empty.
 */
function mlsimport_agent_contact_line( string $icon, string $label, string $value, string $href ): string {
	// Omit the row entirely when there's no value to show.
	if ( '' === $value ) {
		return '';
	}
	// Icon + label + value wrapped in a single tel:/mailto: link.
	return '<a class="mlsimport-agent-contact" href="' . esc_url( $href ) . '">'
		. '<span class="mlsimport-agent-contact__icon" aria-hidden="true">' . mlsimport_property_icon( $icon ) . '</span>'
		. '<span class="mlsimport-agent-contact__text">'
		. '<span class="mlsimport-agent-contact__label">' . esc_html( $label ) . '</span>'
		. '<span class="mlsimport-agent-contact__value">' . esc_html( $value ) . '</span>'
		. '</span></a>';
}

/**
 * A wa.me link that opens a chat already addressed to this agent, so they get
 * "Hi Dana, I saw your profile ..." instead of a bare "hi". The listing-page
 * equivalent is mlsimport_property_whatsapp_link(), which talks about a property
 * rather than an agent.
 *
 * @param string $tel   Agent phone, as entered (may hold spaces, +, punctuation).
 * @param string $first Agent first name, used to open the message.
 * @return string wa.me URL, or '' when the phone holds no digits.
 */
function mlsimport_agent_whatsapp_link( string $tel, string $first ): string {
	// wa.me wants the number bare: digits only, no +, no spaces.
	$number = preg_replace( '/[^0-9]/', '', $tel );
	// No digits → no link.
	if ( '' === $number ) {
		return '';
	}

	// Opening line, mirroring the contact rail's pre-filled message.
	$message = sprintf( /* translators: %s: agent first name. */ __( "Hi %s, I saw your profile and I'd like to talk about ", 'mlsimport' ), $first );

	/** Filter the WhatsApp message a visitor sends from an agent profile. @since 6.4 */
	$message = (string) apply_filters( 'mlsimport_agent_whatsapp_message', $message, $first );

	// wa.me deep link with the pre-filled, URL-encoded message.
	return 'https://wa.me/' . $number . '?text=' . rawurlencode( $message );
}

/**
 * Editorial hero — tall portrait beside the agent's identity, bio teaser, the
 * direct-contact block (email visible) and the primary call/email actions.
 *
 * @param int $id Agent post ID.
 * @return string
 */
function mlsimport_agent_hero( int $id = 0 ): string {
	// Load the view model; nothing to render without one.
	$a = mlsimport_agent_data( $id );
	if ( empty( $a ) ) {
		return '';
	}

	// Portrait (featured image) or a neutral placeholder.
	$portrait = $a['photo_id']
		? get_the_post_thumbnail( $a['id'], 'large', array( 'class' => 'mlsimport-agent-hero__img' ) )
		: '<span class="mlsimport-agent-hero__img mlsimport-agent-hero__img--empty" aria-hidden="true">' . mlsimport_property_icon( 'user' ) . '</span>';

	// Office + (optional) MLS id meta line.
	$meta_bits = '';
	if ( '' !== $a['office'] ) {
		$meta_bits .= '<span class="mlsimport-agent-hero__meta-item">' . mlsimport_property_icon( 'building' ) . esc_html( $a['office'] ) . '</span>';
	}
	if ( '' !== $a['mls_id'] ) {
		$meta_bits .= '<span class="mlsimport-agent-hero__meta-dot" aria-hidden="true"></span>';
		$meta_bits .= '<span class="mlsimport-agent-hero__meta-item">' . mlsimport_property_icon( 'badge' ) . esc_html__( 'MLS', 'mlsimport' ) . ' ' . esc_html( $a['mls_id'] ) . '</span>';
	}

	// Direct-contact rows (only those with a value render). Office already shows
	// in the meta line above, so the grid carries the actionable phone + email.
	$contacts  = mlsimport_agent_contact_line( 'phone', __( 'Phone', 'mlsimport' ), $a['phone'], 'tel:' . preg_replace( '/[^0-9+]/', '', $a['phone'] ) );
	$contacts .= mlsimport_agent_contact_line( 'mail', __( 'Email', 'mlsimport' ), $a['email'], 'mailto:' . $a['email'] );

	// CTAs.
	$ctas = '';
	if ( '' !== $a['phone'] ) {
		$ctas .= '<a class="mlsimport-agent-btn mlsimport-agent-btn--primary" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $a['phone'] ) ) . '">'
			. mlsimport_property_icon( 'phone' ) . esc_html( sprintf( /* translators: %s: agent first name. */ __( 'Call %s', 'mlsimport' ), $a['first'] ) ) . '</a>';
	}
	if ( '' !== $a['email'] ) {
		$ctas .= '<a class="mlsimport-agent-btn mlsimport-agent-btn--outline" href="mailto:' . esc_attr( $a['email'] ) . '">'
			. mlsimport_property_icon( 'mail' ) . esc_html__( 'Email', 'mlsimport' ) . '</a>';
	}
	// WhatsApp opens a chat already addressed to the agent by name. Same phone as
	// the Call CTA, so it only renders when there's a number with digits in it.
	$whatsapp = mlsimport_agent_whatsapp_link( $a['phone'], $a['first'] );
	if ( '' !== $whatsapp ) {
		$ctas .= '<a class="mlsimport-agent-btn mlsimport-agent-btn--whatsapp" href="' . esc_url( $whatsapp ) . '" target="_blank" rel="noopener noreferrer">'
			. mlsimport_property_icon( 'whatsapp' ) . esc_html__( 'WhatsApp', 'mlsimport' ) . '</a>';
	}

	// Assemble the hero: portrait column, then the identity column.
	$html  = '<section id="mlsimport-section-hero" class="mlsimport-agent-hero">';
	$html .= '<div class="mlsimport-agent-hero__portrait">' . $portrait;
	// Only a verified agent (operator-ticked) wears the badge.
	if ( ! empty( $a['verified'] ) ) {
		$html .= '<span class="mlsimport-agent-hero__verified">' . mlsimport_property_icon( 'check' ) . esc_html__( 'Verified Agent', 'mlsimport' ) . '</span>';
	}
	$html .= '</div>';

	$html .= '<div class="mlsimport-agent-hero__identity">';
	$html .= '<span class="mlsimport-agent-hero__eyebrow">' . esc_html( $a['title'] ) . '</span>';
	$html .= '<h1 class="mlsimport-agent-hero__name">' . esc_html( $a['name'] ) . '</h1>';
	if ( '' !== $meta_bits ) {
		$html .= '<div class="mlsimport-agent-hero__meta">' . $meta_bits . '</div>';
	}
	if ( '' !== $a['bio_teaser'] ) {
		$html .= '<p class="mlsimport-agent-hero__lead">' . esc_html( $a['bio_teaser'] ) . '</p>';
	}
	if ( '' !== $contacts ) {
		$html .= '<div class="mlsimport-agent-hero__contact">';
		$html .= '<div class="mlsimport-agent-hero__contact-label">' . esc_html__( 'Direct contact', 'mlsimport' ) . '</div>';
		$html .= '<div class="mlsimport-agent-hero__contact-grid">' . $contacts . '</div>';
		$html .= '</div>';
	}
	if ( '' !== $ctas ) {
		$html .= '<div class="mlsimport-agent-hero__cta">' . $ctas . '</div>';
	}
	$html .= '</div>'; // identity
	$html .= '</section>';

	return $html;
}

/**
 * Sticky sub-nav — reuses the property sub-nav markup so its CSS + scrollspy JS
 * apply unchanged. Links whose target section isn't on the page hide themselves.
 *
 * @param int $id Agent post ID.
 * @return string
 */
function mlsimport_agent_subnav( int $id = 0 ): string {
	// Load the view model; nothing to render without one.
	$a = mlsimport_agent_data( $id );
	if ( empty( $a ) ) {
		return '';
	}

	// Nav items as label => anchor-id of the target section. Links whose target
	// section isn't on the page hide themselves, so About drops out for a bio-less agent.
	$items = array(
		__( 'About', 'mlsimport' )       => 'mlsimport-section-about',
		__( 'Listings', 'mlsimport' )    => 'mlsimport-section-listings',
		__( 'Credentials', 'mlsimport' ) => 'mlsimport-section-credentials',
		__( 'Contact', 'mlsimport' )     => 'mlsimport-section-contact',
	);
	/** Filter the agent sub-nav items (label => anchor id). @since 6.4 */
	$items = (array) apply_filters( 'mlsimport_agent_subnav_items', $items, $id );

	// Build one sub-nav link per item.
	$links = '';
	foreach ( $items as $label => $target ) {
		$links .= '<a class="mlsimport-property-subnav__link" href="#' . esc_attr( $target ) . '" data-target="' . esc_attr( $target ) . '">' . esc_html( $label ) . '</a>';
	}

	// Wrap the links in the property sub-nav markup so its CSS/JS applies.
	return '<div class="mlsimport-agent-subnav">'
		. '<nav class="mlsimport-property-subnav" data-mlsimport-subnav aria-label="' . esc_attr__( 'Agent sections', 'mlsimport' ) . '">' . $links . '</nav>'
		. '</div>';
}

/**
 * Render one reorderable agent content-column section by slug. The single source
 * of truth for slug => section fn, mirroring mlsimport_standalone_agent_section_catalog().
 * The hero, sub-nav and contact rail are fixed and not routed here.
 *
 * @param string $slug Section slug (listings|credentials).
 * @param int    $id   Agent post ID.
 * @return string Section HTML, or '' for an unknown slug.
 */
function mlsimport_render_agent_section( string $slug, int $id = 0 ): string {
	// Route the slug to its section renderer; unknown slugs return ''.
	switch ( $slug ) {
		case 'about':
			return mlsimport_agent_about( $id );
		case 'listings':
			return mlsimport_agent_listings( $id );
		case 'credentials':
			return mlsimport_agent_credentials( $id );
	}
	return '';
}

/**
 * About — the agent's full bio (the post body) in the shared section-card shell.
 * The hero shows only the short teaser; this is where the complete description
 * lives. Omitted when the agent has no bio.
 *
 * @param int $id Agent post ID.
 * @return string
 */
function mlsimport_agent_about( int $id = 0 ): string {
	// Load the view model; nothing to render without a bio.
	$a = mlsimport_agent_data( $id );
	if ( empty( $a ) || '' === $a['bio_html'] ) {
		return '';
	}

	// Section-card shell wrapping the rendered bio.
	$html  = mlsimport_property_section_open( 'about', sprintf( /* translators: %s: agent first name. */ __( 'About %s', 'mlsimport' ), $a['first'] ), 'user' );
	$html .= '<div class="mlsimport-agent-about">' . wp_kses_post( $a['bio_html'] ) . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Listings — the agent's active listings as the shared listing cards, with a
 * count line. Reuses Mlsimport_Standalone_Render::cards_for_posts (and so card.php).
 *
 * @param int $id Agent post ID.
 * @return string
 */
function mlsimport_agent_listings( int $id = 0 ): string {
	// Load the view model; nothing to render without one.
	$a = mlsimport_agent_data( $id );
	if ( empty( $a ) ) {
		return '';
	}

	// The agent's full listing-id set and its total count.
	$ids   = (array) $a['listing_ids'];
	$count = count( $ids );

	// Open the section-card shell.
	$html  = mlsimport_property_section_open( 'listings', sprintf( /* translators: %s: agent first name. */ __( "%s's Listings", 'mlsimport' ), $a['first'] ), 'grid' );

	// Render the paged card grid, or an empty-state line.
	if ( $count ) {
		// GET-based paging: slice the agent's full ID set to the current page, then emit
		// the shared pager. The agent page is a SINGULAR post, where WP's redirect_canonical
		// strips a bare ?page= (a reserved var for <!--nextpage--> content) — so this surface
		// pages on its own ?agent_page= key instead, while reusing the identical pager markup.
		// Per-page size (min 1), current page from ?agent_page=, and this page's id slice.
		$per_page = max( 1, (int) mlsimport_standalone_option( 'agent_listings_per_page', 12 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET paging of the agent's own listings.
		$current  = isset( $_GET['agent_page'] ) ? max( 1, (int) $_GET['agent_page'] ) : 1;
		$page_ids = array_slice( $ids, ( $current - 1 ) * $per_page, $per_page );

		// Count line above the grid.
		$html .= '<p class="mlsimport-agent-listings__count">'
			. esc_html( sprintf( /* translators: %s: number of listings. */ _n( '%s listing', '%s listings', $count, 'mlsimport' ), number_format_i18n( $count ) ) )
			. '</p>';

		// Cards per row: --mli-cols drives the grid, so the narrow-screen media
		// queries (2 then 1 across) still override it.
		$per_row = (int) mlsimport_standalone_option( 'agent_listings_per_row', 3 );
		$per_row = max( 2, min( 4, $per_row ) );

		$html .= '<div class="mlsimport-results__grid mlsimport-agent-listings__grid" style="--mli-cols:' . esc_attr( (string) $per_row ) . '">' . Mlsimport_Standalone_Render::cards_for_posts( $page_ids ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card.php escapes at source.
		// Same pager markup (the <nav> inside .mlsimport-results__pager) as every other listing surface.
		$html .= '<div class="mlsimport-results__pager">' . Mlsimport_Pagination::render( $count, $per_page, $current, array( 'param' => 'agent_page' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pager returns escaped markup.
	} else {
		// Empty state — the agent has no active listings.
		$html .= '<p class="mlsimport-results__empty">' . esc_html__( 'No active listings.', 'mlsimport' ) . '</p>';
	}

	// Close the section-card shell.
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Credentials & Licenses — the RESO Member/Office fields the importer stored,
 * each carrying its standard field name as provenance. Empty rows are omitted.
 *
 * @param int $id Agent post ID.
 * @return string
 */
function mlsimport_agent_credentials( int $id = 0 ): string {
	// Load the view model; nothing to render without one.
	$a = mlsimport_agent_data( $id );
	if ( empty( $a ) ) {
		return '';
	}

	// label => [ value, RESO field name ].
	$rows = array(
		__( 'Agent MLS ID', 'mlsimport' ) => array( $a['mls_id'], 'MemberMlsId' ),
		__( 'Brokerage', 'mlsimport' )    => array( $a['office'], 'OfficeName' ),
		__( 'Email', 'mlsimport' )        => array( $a['email'], 'MemberEmail' ),
		__( 'Direct Phone', 'mlsimport' ) => array( $a['phone'], 'MemberPreferredPhone' ),
	);
	/** Filter the agent credential rows (label => [value, reso]). @since 6.4 */
	$rows = (array) apply_filters( 'mlsimport_agent_credentials', $rows, $id );

	// Build a credential cell per row, skipping rows with no value.
	$cells = '';
	foreach ( $rows as $label => $row ) {
		// Omit an empty-value credential.
		if ( '' === (string) $row[0] ) {
			continue;
		}
		$cells .= '<div class="mlsimport-agent-cred">'
			. '<span class="mlsimport-agent-cred__label">' . esc_html( $label ) . '</span>'
			. '<div class="mlsimport-agent-cred__value">' . esc_html( $row[0] ) . '</div>'
			. '</div>';
	}
	// No populated credentials — omit the whole section.
	if ( '' === $cells ) {
		return '';
	}

	// Section-card shell wrapping the credential grid.
	$html  = mlsimport_property_section_open( 'credentials', __( 'Credentials & Licenses', 'mlsimport' ), 'badge' );
	$html .= '<div class="mlsimport-agent-cred-grid">' . $cells . '</div>';

	// Close the section-card shell.
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Sticky contact rail — the solid-clay lead card: an agent mini-header and a
 * lead form that posts to the shared lead endpoint, routed to this agent.
 *
 * @param int $id Agent post ID.
 * @return string
 */
function mlsimport_agent_contact_rail( int $id = 0 ): string {
	// Load the view model; nothing to render without one.
	$a = mlsimport_agent_data( $id );
	if ( empty( $a ) ) {
		return '';
	}

	// Lead-form nonce and the mini-header avatar (thumbnail or placeholder).
	$nonce = wp_create_nonce( Mlsimport_Property_Lead::NONCE );
	$mini  = $a['photo_id']
		? get_the_post_thumbnail( $a['id'], 'thumbnail', array( 'class' => 'mlsimport-agent-rail__avatar-img' ) )
		: '<span class="mlsimport-agent-rail__avatar-img mlsimport-agent-rail__avatar-img--empty" aria-hidden="true">' . mlsimport_property_icon( 'user' ) . '</span>';

	// Pre-filled opening line for the message textarea.
	$prefill = sprintf( /* translators: %s: agent first name. */ __( "Hi %s, I'd like to talk about ", 'mlsimport' ), $a['first'] );

	// Build the rail: mini-header, lead form, and the hidden success panel.
	$html  = '<aside id="mlsimport-section-contact" class="mlsimport-agent-rail">';
	$html .= '<div class="mlsimport-agent-rail__card">';

	$html .= '<div class="mlsimport-agent-rail__head">';
	$html .= '<span class="mlsimport-agent-rail__avatar">' . $mini . '</span>';
	$html .= '<span class="mlsimport-agent-rail__who">';
	$html .= '<span class="mlsimport-agent-rail__name">' . esc_html( $a['name'] ) . '</span>';
	$html .= '<span class="mlsimport-agent-rail__title">' . esc_html( $a['title'] ) . '</span>';
	$html .= '</span></div>';

	$html .= '<form class="mlsimport-property-lead-form mlsimport-agent-rail__form" data-mlsimport-lead method="post">';
	$html .= '<div class="mlsimport-agent-rail__form-title">' . esc_html( sprintf( /* translators: %s: agent first name. */ __( 'Contact %s', 'mlsimport' ), $a['first'] ) ) . '</div>';
	$html .= '<input type="text" name="mlsimport_name" required placeholder="' . esc_attr__( 'Full name', 'mlsimport' ) . '" />';
	$html .= '<input type="email" name="mlsimport_email" required placeholder="' . esc_attr__( 'Email', 'mlsimport' ) . '" />';
	$html .= '<input type="tel" name="mlsimport_phone" placeholder="' . esc_attr__( 'Phone', 'mlsimport' ) . '" />';
	$html .= '<textarea name="mlsimport_message" rows="3" placeholder="' . esc_attr__( 'Message', 'mlsimport' ) . '">' . esc_textarea( $prefill ) . '</textarea>';
	// Honeypot — bots fill it, real users don't.
	$html .= '<input type="text" name="mlsimport_hp" class="mlsimport-property-lead-form__hp" tabindex="-1" autocomplete="off" aria-hidden="true" />';
	$html .= '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $a['id'] ) . '" />';
	$html .= '<input type="hidden" name="nonce" value="' . esc_attr( $nonce ) . '" />';
	// Same markup/classes as the property page's lead-form Send Message button
	// (property-sections.php), so both inherit .mlsimport-property-lead-form
	// button[type=submit] and look identical instead of the agent "light" variant.
	$html .= '<button type="submit">' . esc_html__( 'Send Message', 'mlsimport' ) . '</button>';
	$html .= '<p class="mlsimport-property-lead-form__status" role="status" aria-live="polite"></p>';
	$html .= '</form>';

	$html .= '<div class="mlsimport-agent-rail__success" data-lead-success hidden>';
	$html .= '<span class="mlsimport-agent-rail__success-icon" aria-hidden="true">' . mlsimport_property_icon( 'check' ) . '</span>';
	$html .= '<div class="mlsimport-agent-rail__success-title">' . esc_html__( 'Message sent', 'mlsimport' ) . '</div>';
	$html .= '<div class="mlsimport-agent-rail__success-text">' . esc_html( sprintf( /* translators: %s: agent name. */ __( '%s will get back to you shortly.', 'mlsimport' ), $a['name'] ) ) . '</div>';
	$html .= '</div>';

	$html .= '</div>'; // card
	$html .= '</aside>';
	return $html;
}
