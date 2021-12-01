<?php
/**
 * Facets widget
 *
 * @package elasticpress
 */

namespace ElasticPress\Feature\Facets;

use \WP_Widget as WP_Widget;
use ElasticPress\Features as Features;
use ElasticPress\Utils as Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Facets widget class
 */
class Widget extends WP_Widget {
	/**
	 * Create widget
	 */
	public function __construct() {
		$options = array( 'description' => esc_html__( 'Add a facet to an archive or search results page.', 'elasticpress' ) );
		parent::__construct( 'ep-facet', esc_html__( 'ElasticPress - Facet', 'elasticpress' ), $options );
	}

	/**
	 * Output widget
	 *
	 * @param  array $args Widget args
	 * @param  array $instance Instance settings
	 * @since 2.5
	 */
	public function widget( $args, $instance ) {
		global $wp_query;

		$feature = Features::factory()->get_registered_feature( 'facets' );

		if ( $wp_query->get( 'ep_facet', false ) ) {
			if ( ! $feature->is_facetable( $wp_query ) ) {
				return false;
			}
		}

		$es_success = ( ! empty( $wp_query->elasticsearch_success ) ) ? true : false;

		if ( ! $es_success ) {
			return;
		}

		if ( empty( $instance['facet'] ) ) {
			return;
		}

		$taxonomy = $instance['facet'];

		if ( ! is_search() ) {
			$post_type = $wp_query->get( 'post_type' );

			if ( empty( $post_type ) ) {
				$post_type = 'post';
			}

			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return;
			}
		}

		$selected_filters = $feature->get_selected();

		$match_type = ( ! empty( $instance['match_type'] ) ) ? $instance['match_type'] : 'all';

		global $sitepress;
		$df_term_options = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		];
		if($sitepress){
			$df_term_options['lang'] = 'sq';
			$original_lang = ICL_LANGUAGE_CODE; // Save the current language
			$new_lang = 'sq'; // The language in which you want to get the terms
			$sitepress->switch_lang($new_lang); // Switch to new language
		}
		$terms = get_terms(
				/**
				 * Filter arguments passed to get_terms() while getting all possible terms for the facet widget.
				 *
				 * @since  3.5.0
				 * @hook ep_facet_search_get_terms_args
				 * @param  {array} $query Weighting query
				 * @param  {string} $post_type Post type
				 * @param  {array} $args WP Query arguments
				 * @return  {array} New query
				 */
				apply_filters(
						'ep_facet_search_get_terms_args',
						$df_term_options,
						$args,
						$instance
				)
		);
		if($sitepress){
			$sitepress->switch_lang($original_lang);
		}

		/**
		 * Terms validity check
		 */
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$terms_by_slug = array();

		foreach ( $terms as $term ) {
			$terms_by_slug[ $term->slug ] = $term;

			if ( ! empty( $GLOBALS['ep_facet_aggs'][ $taxonomy ][ $term->slug ] ) ) {
				$term->count = $GLOBALS['ep_facet_aggs'][ $taxonomy ][ $term->slug ];
			} else {
				$term->count = 0;
			}
		}

		/**
		 * Check to make sure all terms exist before proceeding
		 */
		if ( ! empty( $selected_filters['taxonomies'][ $taxonomy ] ) && ! empty( $selected_filters['taxonomies'][ $taxonomy ]['terms'] ) ) {
			foreach ( $selected_filters['taxonomies'][ $taxonomy ]['terms'] as $term_slug => $nothing ) {
				if ( empty( $terms_by_slug[ $term_slug ] ) ) {
					/**
					 * Term does not exist!
					 */
					return;
				}
			}
		}

		$orderby = isset( $instance['orderby'] ) ? $instance['orderby'] : 'count';
		$order   = isset( $instance['order'] ) ? $instance['order'] : 'count';

		$terms     = Utils\get_term_tree( $terms, $orderby, $order, true );

		$outputted_terms = array();

		echo wp_kses_post( $args['before_widget'] );

        $selected_terms = $selected_filters['taxonomies'][$taxonomy]['terms'] ?? [];
        $terms = array_filter($terms, fn ($t) => $t->count > 0  || count($selected_terms) > 0); ?>
		<div class="terms" data-terms='<?php
			echo esc_attr(
				wp_json_encode(
						[
							'title' => $instance['title'],
							'taxonomy' => $taxonomy,
							'selected_terms' => array_map(fn ($t) => (string) $t, array_keys($selected_terms)),
							'terms' => array_map(
								fn ($t) => [
												'slug' => (string) ($t->slug),
												'name' => ($sitepress === null || $original_lang == 'sq') ?
																	$t->name :
																	get_term_by('id', icl_object_id($t->term_id, $taxonomy, false, $original_lang) ?? $t->term_id, $taxonomy)->name
											],
								$this->order_by_selected($terms ?? [], $selected_terms, $order, $orderby)
							)
						]
					)
			); ?>'>
		</div>
	<?php
		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Get the markup for an individual facet item.
	 *
	 * @param WP_Term $term     Term object.
	 * @param string  $url      Filter URL.
	 * @param boolean $selected Whether the term is currently selected.
	 * @since 3.6.3
	 * @return string HTML for an individual facet term.
	 */
	protected function get_facet_term_html( $term, $url, $selected = false ) {
		$href = sprintf(
			'href="%s"',
			esc_url( $url )
		);

		/**
		 * Filter the label for an individual facet term.
		 *
		 * @since 3.6.3
		 * @hook ep_facet_widget_term_label
		 * @param {string} $label Facet term label.
		 * @param {WP_Term} $term Term object.
		 * @param {boolean} $selected Whether the term is selected.
		 * @return {string} Individual facet term label.
		 */
		$label = apply_filters( 'ep_facet_widget_term_label', $term->name, $term, $selected );

		$link = sprintf(
			'<a %1$s rel="nofollow"><div class="ep-checkbox %2$s" role="presentation"></div>%3$s</a>',
			$term->count ? $href : '',
			$selected ? 'checked' : '',
			wp_kses_post( $label )
		);

		$html = sprintf(
			'<div class="term level-%1$d %2$s %3$s" data-term-name="%4$s" data-term-slug="%5$s">%6$s</div>',
			absint( $term->level ),
			$selected ? 'selected' : '',
			! $term->count ? 'empty-term' : '',
			esc_attr( strtolower( $term->name ) ),
			esc_attr( strtolower( $term->slug ) ),
			$link
		);

		/**
		 * Filter the HTML for an individual facet term.
		 *
		 * For term search to work correctly the outermost wrapper of the term
		 * HTML must have data-term-name and data-term-slug attributes set to
		 * lowercase versions of the term name and slug respectively.
		 *
		 * @since 3.6.3
		 * @hook ep_facet_widget_term_html
		 * @param {string} $html Facet term HTML.
		 * @param {WP_Term} $term Term object.
		 * @param {string} $url Filter URL.
		 * @param {boolean} $selected Whether the term is selected.
		 * @return {string} Individual facet term HTML.
		 */
		return apply_filters( 'ep_facet_widget_term_html', $html, $term, $url, $selected );
	}

	/**
	 * Order terms putting selected at the top
	 *
	 * @param  array  $terms Array of terms
	 * @param  array  $selected_terms Selected terms
	 * @param  string $order The order to sort from. Desc or Asc.
	 * @param  string $orderby The orderby to sort items from.
	 * @since  2.5
	 * @return array
	 */
	private function order_by_selected( $terms, $selected_terms, $order = false, $orderby = false ) {
		$ordered_terms = [];
		$terms_by_slug = [];

		foreach ( $terms as $term ) {
			$terms_by_slug[ $term->slug ] = $term;
		}

		foreach ( $selected_terms as $term_slug ) {
			if ( ! empty( $terms_by_slug[ $term_slug ] ) ) {
				$ordered_terms[ $term_slug ] = $terms_by_slug[ $term_slug ];
			}
		}

		foreach ( $terms_by_slug as $term_slug => $term ) {
			if ( empty( $ordered_terms[ $term_slug ] ) ) {
				$ordered_terms[ $term_slug ] = $terms_by_slug[ $term_slug ];
			}
		}

		if ( 'count' === $orderby ) {
			if ( 'asc' === $order ) {
				uasort(
					$ordered_terms,
					function( $a, $b ) {
						return $a->count > $b->count;
					}
				);
			} else {
				uasort(
					$ordered_terms,
					function( $a, $b ) {
						return $a->count < $b->count;
					}
				);
			}
		} else {
			if ( 'asc' === $order ) {
				ksort( $ordered_terms );
			} else {
				krsort( $ordered_terms );
			}
		}

		return array_values( $ordered_terms );
	}

	/**
	 * Output widget form
	 *
	 * @param  array $instance Instance settings
	 * @since 2.5
	 */
	public function form( $instance ) {
		$dashboard_url = admin_url( 'admin.php?page=elasticpress' );

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			$dashboard_url = network_admin_url( 'admin.php?page=elasticpress' );
		}

		$feature  = Features::factory()->get_registered_feature( 'facets' );
		$settings = [];

		if ( $feature ) {
			$settings = $feature->get_settings();
		}

		$settings = wp_parse_args(
			$settings,
			array(
				'match_type' => 'all',
			)
		);

		$set     = esc_html__( 'all', 'elasticpress' );
		$not_set = esc_html__( 'any', 'elasticpress' );

		if ( 'any' === $settings['match_type'] ) {
			$set     = esc_html__( 'any', 'elasticpress' );
			$not_set = esc_html__( 'all', 'elasticpress' );
		}

		$title   = ( ! empty( $instance['title'] ) ) ? $instance['title'] : '';
		$facet   = ( ! empty( $instance['facet'] ) ) ? $instance['facet'] : '';
		$orderby = ( ! empty( $instance['orderby'] ) ) ? $instance['orderby'] : '';
		$order   = ( ! empty( $instance['order'] ) ) ? $instance['order'] : '';

		$taxonomies = get_taxonomies( array( 'public' => true ), 'object' );
		/**
		 * Filter taxonomies made available for faceting
		 *
		 * @hook ep_facet_include_taxonomies
		 * @param  {array} $taxonomies Taxonomies
		 * @return  {array} New taxonomies
		 */
		$taxonomies = apply_filters( 'ep_facet_include_taxonomies', $taxonomies );

		$orderby_options = [
			'count' => __( 'Count', 'elasticpress' ),
			'name'  => __( 'Term Name', 'elasticpress' ),
		];

		$order_options = [
			'desc' => __( 'Descending', 'elasticpress' ),
			'asc'  => __( 'Ascending', 'elasticpress' ),
		];

		?>
		<div class="widget-ep-facet">
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
					<?php esc_html_e( 'Title:', 'elasticpress' ); ?>
				</label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'facet' ) ); ?>">
					<?php esc_html_e( 'Taxonomy:', 'elasticpress' ); ?>
				</label><br>

				<select id="<?php echo esc_attr( $this->get_field_id( 'facet' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'facet' ) ); ?>">
					<?php foreach ( $taxonomies as $slug => $taxonomy_object ) : ?>
						<option <?php selected( $facet, $taxonomy_object->name ); ?> value="<?php echo esc_attr( $taxonomy_object->name ); ?>"><?php echo esc_html( $taxonomy_object->labels->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>">
					<?php esc_html_e( 'Order Terms By:', 'elasticpress' ); ?>
				</label><br>

				<select id="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'orderby' ) ); ?>">
					<?php foreach ( $orderby_options as $name => $title ) : ?>
						<option <?php selected( $orderby, $name ); ?>
								value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>">
					<?php esc_html_e( 'Term Order:', 'elasticpress' ); ?>
				</label><br>

				<select id="<?php echo esc_attr( $this->get_field_id( 'order' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'order' ) ); ?>">
					<?php foreach ( $order_options as $name => $title ) : ?>
						<option <?php selected( $order, $name ); ?>
								value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<?php // translators: "all" or "any", depending on configuration values, 3: URL ?>
			<p><?php echo wp_kses_post( sprintf( __( 'Faceting will  filter out any content that is not tagged to all selected terms; change this to show <strong>%1$s</strong> content tagged to <strong>%2$s</strong> selected term in <a href="%3$s">ElasticPress settings</a>.', 'elasticpress' ), $set, $not_set, esc_url( $dashboard_url ) ) ); ?></p>
		</div>

		<?php
	}

	/**
	 * Sanitize fields
	 *
	 * @param  array $new_instance New instance settings
	 * @param  array $old_instance Old instance settings
	 * @since 2.5
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = [];

		$instance['title']   = sanitize_text_field( $new_instance['title'] );
		$instance['facet']   = sanitize_text_field( $new_instance['facet'] );
		$instance['orderby'] = sanitize_text_field( $new_instance['orderby'] );
		$instance['order']   = sanitize_text_field( $new_instance['order'] );

		return $instance;
	}
}
