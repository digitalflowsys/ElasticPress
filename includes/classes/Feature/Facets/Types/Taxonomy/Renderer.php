<?php
/**
 * Class responsible for rendering the filters.
 *
 * @since 4.2.0
 * @package elasticpress
 */

namespace ElasticPress\Feature\Facets\Types\Taxonomy;

use ElasticPress\Features as Features;
use ElasticPress\Utils as Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Facets render class
 */
class Renderer {
	/**
	 * Output the widget or block HTML.
	 *
	 * @param array $args     Widget args
	 * @param array $instance Instance settings
	 */
	public function render( $args, $instance ) {
		global $wp_query;

		$args     = wp_parse_args(
			$args,
			[
				'before_widget' => '',
				'before_title'  => '',
				'after_title'   => '',
				'after_widget'  => '',
			]
		);
		$instance = wp_parse_args(
			$instance,
			[
				'title' => '',
			]
		);

		$feature = Features::factory()->get_registered_feature( 'facets' );

		if ( $wp_query->get( 'ep_facet', false ) && ! $feature->is_facetable( $wp_query ) ) {
			return false;
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

			if ( is_tax() ) {
				$post_type = get_taxonomy( get_queried_object()->taxonomy )->object_type;
			} else {
				$post_type = $wp_query->get( 'post_type' ) ? $wp_query->get( 'post_type' ) : 'post';
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
		 * Filter the taxonomy facet terms.
		 *
		 * Example of usage, to hide unavailable category terms:
		 * ```
		 * add_filter(
		 *     'ep_facet_taxonomy_terms',
		 *     function ( $terms, $taxonomy ) {
		 *         if ( 'category' !== $taxonomy ) {
		 *             return $terms;
		 *         }
		 *
		 *         return array_filter(
		 *              $terms,
		 *              function ( $term ) {
		 *                  return $term->count > 0;
		 *              }
		 *         );
		 *      },
		 *      10,
		 *      2
		 * );
		 * ```
		 *
		 * @since 4.3.1
		 * @hook ep_facet_taxonomy_terms
		 * @param {array} $terms Terms
		 * @param {string} $taxonomy Taxonomy name
		 * @return {array} New terms
		 */
		$terms_by_slug = apply_filters( 'ep_facet_taxonomy_terms', $terms_by_slug, $taxonomy );

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

		$terms = Utils\get_term_tree( $terms_by_slug, $orderby, $order, true );


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
	 * @return string HTML for an individual facet term.
	 */
	public function get_facet_term_html( $term, $url, $selected = false ) {
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

		/**
		 * Filter the accessible label for an individual facet term link.
		 *
		 * Used as the aria-label attribute for filter links. The accessible
		 * label should include additional context around what action will be
		 * performed by visiting the link, such as whether the filter will be
		 * added or removed.
		 *
		 * @since 4.0.0
		 * @hook ep_facet_widget_term_accessible_label
		 * @param {string} $label Facet term accessible label.
		 * @param {WP_Term} $term Term object.
		 * @param {boolean} $selected Whether the term is selected.
		 * @return {string} Individual facet term accessible label.
		 */
		$accessible_label = apply_filters(
			'ep_facet_widget_term_accessible_label',
			$selected
				/* translators: %s: Filter term name. */
				? sprintf( __( 'Remove filter: %s', 'elasticpress' ), $term->name )
				/* translators: %s: Filter term name. */
				: sprintf( __( 'Apply filter: %s', 'elasticpress' ), $term->name ),
			$term,
			$selected
		);

		$link = sprintf(
			'<a aria-label="%1$s" %2$s rel="nofollow"><div class="ep-checkbox %3$s" role="presentation"></div>%4$s</a>',
			esc_attr( $accessible_label ),
			$term->count ? $href : 'aria-role="link" aria-disabled="true"',
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
}
