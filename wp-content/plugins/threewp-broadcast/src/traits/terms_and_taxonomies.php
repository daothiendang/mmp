<?php

namespace threewp_broadcast\traits;

use \threewp_broadcast\actions;

/**
	@brief		Methods related to terms and taxonomies.
	@since		2014-10-19 15:44:39
**/
trait terms_and_taxonomies
{
	/**
		@brief		Collects the post type's taxonomies into the broadcasting data object.
		@details

		The taxonomies are places in $bcd->parent_blog_taxonomies.
		Requires only that $bcd->post->post_type be filled in.
		If $bcd->post->ID exists, only the terms used in the post will be collected, else all the terms will be inserted into $bcd->parent_post_taxonomies[ taxonomy ].

		@since		2014-04-08 13:40:44
	**/
	public function collect_post_type_taxonomies( $bcd )
	{
		$bcd->parent_blog_taxonomies = get_object_taxonomies( [ 'object_type' => $bcd->post->post_type ], 'array' );
		$bcd->parent_post_taxonomies = [];
		foreach( $bcd->parent_blog_taxonomies as $parent_blog_taxonomy => $taxonomy )
		{
			if ( isset( $bcd->post->ID ) )
				$taxonomy_terms = get_the_terms( $bcd->post->ID, $parent_blog_taxonomy );
			else
				$taxonomy_terms = get_terms( [ $parent_blog_taxonomy ], [
					'hide_empty' => false,
				] );

			// No terms = empty = false.
			if ( ! $taxonomy_terms )
				$taxonomy_terms = [];

			$bcd->parent_post_taxonomies[ $parent_blog_taxonomy ] = $this->array_rekey( $taxonomy_terms, 'term_id' );

			// Parent blog taxonomy terms are used for creating missing target term ancestors
			$o = (object)[];
			$o->taxonomy = $taxonomy;
			$o->terms = $bcd->parent_post_taxonomies[ $parent_blog_taxonomy ];
			$this->get_parent_terms( $o );

			$bcd->parent_blog_taxonomies[ $parent_blog_taxonomy ] =
			[
				'taxonomy' => $taxonomy,
				'terms'    => $o->terms,
			];
		}
	}

	public function get_current_blog_taxonomy_terms( $taxonomy )
	{
		$terms = get_terms( $taxonomy, array(
			'hide_empty' => false,
		) );
		$terms = (array) $terms;
		$terms = $this->array_rekey( $terms, 'term_id' );
		return $terms;
	}

	/**
		@brief		Return all of the terms and their parents.
		@param		object	$taxonomy		The taxonomy object.
		@param		array	$terms			The terms array.
		@since		2015-01-14 22:18:39
	**/
	public function get_parent_terms( $o )
	{
		// Wanted terms = those which are referenced as parents which we don't know about.
		$wanted_terms = [];
		foreach( $o->terms as $term_id => $term )
		{
			$term = (object)$term;
			$parent_term_id = $term->parent;
			if ( $parent_term_id < 1 )
				continue;
			if ( ! isset( $o->terms[ $parent_term_id ] ) )
				$wanted_terms[ $parent_term_id ] = true;
		}

		if ( count( $wanted_terms ) < 1 )
			return;

		// Fetch them and then try to find their parents.
		$new_terms = get_terms( $o->taxonomy->name, [
			'include' => array_keys( $wanted_terms ),
		] );
		foreach( $new_terms as $new_term )
			$o->terms[ $new_term->term_id ] = $new_term;

		// And since we have added new terms, they might have parents themselves.
		$this->get_parent_terms( $o );
	}

	/**
	 * Recursively adds the missing ancestors of the given source term at the
	 * target blog.
	 *
	 * @param array $source_post_term           The term to add ancestors for
	 * @param array $source_post_taxonomy       The taxonomy we're working with
	 * @param array $target_blog_terms          The existing terms at the target
	 * @param array $parent_blog_taxonomy_terms The existing terms at the source
	 * @return int The ID of the target parent term
	 */
	public function insert_term_ancestors( $source_post_term, $source_post_taxonomy, $target_blog_terms, $parent_blog_taxonomy_terms )
	{
		// Fetch the parent of the current term among the source terms
		foreach ( $parent_blog_taxonomy_terms as $term )
			if ( $term->term_id == $source_post_term->parent )
				$source_parent = $term;

		if ( ! isset( $source_parent ) )
			// Sanity check, the source term's parent doesn't exist! Orphan!
			return 0;

		// Check if the parent already exists at the target
		foreach ( $target_blog_terms as $term )
			if ( $term->slug === $source_parent->slug )
				// The parent already exists, return its ID
				return $term->term_id;

		// Does the parent also have a parent, and if so, should we create the parent?
		$target_grandparent_id = 0;
		if ( 0 != $source_parent->parent )
			// Recursively insert ancestors, and get the newly inserted parent's ID
			$target_grandparent_id = $this->insert_term_ancestors( $source_parent, $source_post_taxonomy, $target_blog_terms, $parent_blog_taxonomy_terms );

		// Check if the parent exists at the target grandparent
		$term_id = term_exists( $source_parent->name, $source_post_taxonomy, $target_grandparent_id );

		if ( is_null( $term_id ) || $term_id == 0 )
		{
			// The target parent does not exist, we need to create it
			$new_term = $source_parent;
			$new_term->parent = $target_grandparent_id;
			$action = new actions\wp_insert_term;
			$action->taxonomy = $source_post_taxonomy;
			$action->term = $new_term;
			$action->execute();
			if ( $action->new_term )
				$term_id = $action->new_term->term_id;
		}
		elseif ( is_array( $term_id ) )
			// The target parent exists and we got an array as response, extract parent id
			$term_id = $term_id[ 'term_id' ];

		return $term_id;
	}

	/**
		@brief		Syncs the terms of a taxonomy from the parent blog in the BCD to the current blog.
		@details	If $bcd->add_new_taxonomies is set, new taxonomies will be created, else they are ignored.
		@param		broadcasting_data		$bcd			The broadcasting data.
		@param		string					$taxonomy		The taxonomy to sync.
		@since		20131004
	**/
	public function sync_terms( $bcd, $taxonomy )
	{
		$source_terms = $bcd->parent_blog_taxonomies[ $taxonomy ][ 'terms' ];

		// Select only those terms that exist in the blog. We select them by slugs.
		$needed_slugs = [];
		foreach( $source_terms as $source_term )
			$needed_slugs[ $source_term->slug ] = true;
		$target_terms = get_terms( $taxonomy, [
			'slug' => array_keys( $needed_slugs ),
			'hide_empty' => false,
		] );
		$target_terms = $this->array_rekey( $target_terms, 'term_id' );

		$refresh_cache = false;

		// Keep track of which terms we've found.
		$found_targets = [];
		$found_sources = [];

		// Also keep track of which sources we haven't found on the target blog.
		$unfound_sources = $source_terms;

		// Rekey the terms in order to find them faster.
		$source_slugs = [];
		foreach( $source_terms as $source_term_id => $source_term )
			$source_slugs[ $source_term->slug ] = $source_term_id;
		$target_slugs = [];
		foreach( $target_terms as $target_term )
			$target_slugs[ $target_term->slug ] = $target_term->term_id;

		// Step 1.
		$this->debug( 'Find out which of the source terms exist on the target blog.' );
		foreach( $source_slugs as $source_slug => $source_term_id )
		{
			if ( ! isset( $target_slugs[ $source_slug ] ) )
				continue;
			$target_term_id = $target_slugs[ $source_slug ];
			$this->debug( 'Found source term %s. Source ID: %s. Target ID: %s.', $source_slug, $source_term_id, $target_term_id );
			$found_targets[ $target_term_id ] = $source_term_id;
			$found_sources[ $source_term_id ] = $target_term_id;
			unset( $unfound_sources[ $source_term_id ] );
		}

		// These sources were not found. Add them.
		if ( isset( $bcd->add_new_taxonomies ) && $bcd->add_new_taxonomies )
		{
			$this->debug( '%s taxonomies are missing on this blog.', count( $unfound_sources ) );
			foreach( $unfound_sources as $unfound_source_id => $unfound_source )
			{
				// We need to clone to unset the parent.
				$unfound_source = clone( $unfound_source );
				unset( $unfound_source->parent );
				$action = new actions\wp_insert_term;
				$action->taxonomy = $taxonomy;
				$action->term = $unfound_source;
				$action->execute();

				if ( $action->new_term )
				{
					$new_term = $action->new_term;
					$new_term_id = $new_term->term_id;
					$target_terms[ $new_term_id ] = $new_term;
					$found_sources[ $unfound_source_id ] = $new_term_id;
					$found_targets[ $new_term_id ] = $unfound_source_id;
					$refresh_cache = true;
				}
			}
		}

		// Now we know which of the terms on our target blog exist on the source blog.
		// Next step: see if the parents are the same on the target as they are on the source.
		// "Same" meaning pointing to the same slug.

		$this->debug( 'About to update taxonomy terms.' );
		foreach( $found_targets as $target_term_id => $source_term_id)
		{
			$source_term = (object)$source_terms[ $source_term_id ];
			$target_term = (object)$target_terms[ $target_term_id ];

			$action = new actions\wp_update_term;
			$action->taxonomy = $taxonomy;

			// The old term is the target term, since it contains the old values.
			$action->set_old_term( $target_term );
			// The new term is the source term, since it has the newer data.
			$action->set_new_term( $source_term );

			// ... but the IDs have to be switched around, since the target term has the new ID.
			$action->switch_data();

			// Does the source term even have a parent?
			if ( $source_term->parent > 0 )
			{
				$parent_of_equivalent_source_term = $source_term->parent;
				// Does the parent of the source have an equivalent target?
				if ( isset( $found_sources[ $parent_of_equivalent_source_term ] ) )
					$new_parent = $found_sources[ $parent_of_equivalent_source_term ];
			}
			else
				$new_parent = 0;

			$action->new_term->parent = $new_parent;

			$action->execute();
			$refresh_cache |= $action->updated;
		}

		// wp_update_category alone won't work. The "cache" needs to be cleared.
		// see: http://wordpress.org/support/topic/category_children-how-to-recalculate?replies=4
		if ( $refresh_cache )
			delete_option( 'category_children' );
	}

	/**
		@brief		Allows Broadcast plugins to update the term with their own info.
		@since		2014-04-08 15:12:05
	**/
	public function threewp_broadcast_wp_insert_term( $action )
	{
		if ( $action->is_finished() )
			return;

		if ( ! isset( $action->term->parent ) )
			$action->term->parent = 0;

		$term = wp_insert_term(
			$action->term->name,
			$action->taxonomy,
			[
				'description' => $action->term->description,
				'parent' => $action->term->parent,
				'slug' => $action->term->slug,
			]
		);

		// Sometimes the search didn't find the term because it's SIMILAR and not exact.
		// WP will complain and give us the term tax id.
		if ( is_wp_error( $term ) )
		{
			$wp_error = $term;
			$this->debug( 'Error creating the term: %s. Error was: %s', $action->term->name, serialize( $wp_error->error_data ) );
			if ( isset( $wp_error->error_data[ 'term_exists' ] ) )
			{
				$term_id = $wp_error->error_data[ 'term_exists' ];
				$this->debug( 'Term exists already with the term ID: %s', $term_id );
				$term = get_term_by( 'id', $term_id, $action->taxonomy, ARRAY_A );
			}
			else
			{
				throw new \Exception( 'Unable to create a new term.' );
			}
		}

		$term = (object)$term;
		$term_taxonomy_id = $term->term_taxonomy_id;

		$this->debug( 'Created the new term %s with the term taxonomy ID of %s.', $action->term->name, $term_taxonomy_id );

		$action->new_term = get_term_by( 'term_taxonomy_id', $term_taxonomy_id, $action->taxonomy );

		$action->finish();
	}

	/**
		@brief		[Maybe] update a term.
		@since		2014-04-10 14:26:23
	**/
	public function threewp_broadcast_wp_update_term( $action )
	{
		$update = true;

		// If we are given an old term, then we have a chance of checking to see if there should be an update called at all.
		if ( $action->has_old_term() )
		{
			// Assume they match.
			$update = false;
			foreach( [ 'name', 'description', 'parent' ] as $key )
				if ( $action->old_term->$key != $action->new_term->$key )
					$update = true;
		}

		if ( $update )
		{
			$this->debug( 'Updating the term %s.', $action->new_term->name );
			wp_update_term( $action->new_term->term_id, $action->taxonomy, array(
				'description' => $action->new_term->description,
				'name' => $action->new_term->name,
				'parent' => $action->new_term->parent,
			) );
			$action->updated = true;
		}
		else
			$this->debug( 'Will not update the term %s.', $action->new_term->name );
	}
}