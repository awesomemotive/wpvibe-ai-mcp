<?php
/**
 * Checks an Elementor element tree before save-page writes anything (#1342), with Elementor's own registries and
 * validators: unique ids, registered element and widget types, no widget at the root, and an atomic (v4) element's
 * settings against its props schema (Props_Parser). Each problem names the element, so the caller can fix that one.
 */
defined( 'ABSPATH' ) || exit;

class WPVibe_Elementor_Validator {
	const MAX_ERRORS = 50;
	const MAX_DEPTH  = 64;

	private $errors = array();
	private $ids    = array();
	private $elements;
	private $widgets;
	private $root_widget = false;

	/** @return array[] One entry per problem: id, path, code, message. Empty when the tree can be saved. */
	public static function validate( array $data, $template_type = '' ) {
		$validator = new self();
		// A global-widget template (Elementor Pro) stores its one widget at the root.
		$validator->root_widget = 'widget' === $template_type;
		$plugin    = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance : null;
		// Without the registries (never on a real site) only the structure is checked.
		$validator->elements = $plugin && isset( $plugin->elements_manager ) ? $plugin->elements_manager : null;
		$validator->widgets  = $plugin && isset( $plugin->widgets_manager ) ? $plugin->widgets_manager : null;
		$validator->walk( $data, 'data', 0 );
		return array_slice( $validator->errors, 0, self::MAX_ERRORS );
	}

	private function walk( $nodes, $path, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			$this->error( null, $path, 'too_deep', sprintf( 'Elements are nested more than %d levels deep.', self::MAX_DEPTH ) );
			return;
		}
		foreach ( array_values( $nodes ) as $i => $node ) {
			$this->node( $node, "{$path}[{$i}]", $depth );
		}
	}

	private function node( $node, $path, $depth ) {
		if ( ! is_array( $node ) ) {
			$this->error( null, $path, 'not_an_element', 'Each element must be an object with id, elType, settings and elements.' );
			return;
		}
		$id = $node['id'] ?? null;
		// Elementor keeps whole-number ids as they are, and a page read back returns them as numbers.
		$id = is_int( $id ) ? (string) $id : $id;
		if ( ! is_string( $id ) || '' === $id ) {
			$this->error( null, $path, 'missing_id', 'The element has no id. Give every element a unique id (for example 8 random hex characters).' );
		} elseif ( isset( $this->ids[ $id ] ) ) {
			$this->error( $id, $path, 'duplicate_id', sprintf( 'The id "%s" is also used at %s. Every element needs its own id.', $id, $this->ids[ $id ] ) );
		} else {
			$this->ids[ $id ] = $path;
		}
		$id      = is_string( $id ) ? $id : null;
		$el_type = $node['elType'] ?? null;
		$type    = null;
		if ( ! is_string( $el_type ) || '' === $el_type ) {
			$this->error( $id, $path, 'missing_el_type', 'The element has no elType (for example "container", "e-flexbox" or "widget").' );
		} elseif ( 'widget' === $el_type ) {
			if ( 0 === $depth && ! $this->root_widget ) {
				$this->error( $id, $path, 'widget_at_root', 'A widget cannot sit at the top level. Put it inside a container (or an e-flexbox / e-div-block on Elementor 4).' );
			}
			$widget_type = $node['widgetType'] ?? null;
			if ( ! is_string( $widget_type ) || '' === $widget_type ) {
				$this->error( $id, $path, 'missing_widget_type', 'The widget has no widgetType.' );
			} elseif ( $this->widgets ) {
				$type = $this->widgets->get_widget_types( $widget_type );
				if ( ! $type ) {
					$this->error( $id, $path, 'unknown_widget_type', sprintf( 'No widget "%s" is registered on this site (the plugin that adds it may be inactive). GET /wpvibe/v1/elementor/widgets lists the registered ones.', $widget_type ) );
				}
			}
		} elseif ( $this->elements ) {
			$type = $this->elements->get_element_types( $el_type );
			if ( ! $type ) {
				$this->error( $id, $path, 'unknown_element_type', sprintf( 'No element type "%s" is registered on this site.', $el_type ) );
			}
		}
		$settings = $node['settings'] ?? array();
		if ( ! is_array( $settings ) ) {
			$this->error( $id, $path, 'invalid_settings', 'settings must be an object.' );
		} elseif ( $type ) {
			$this->atomic_settings( $type, $settings, $id, $path, 'widget' === $el_type ? ( $node['widgetType'] ?? '' ) : $el_type );
		}
		$children = $node['elements'] ?? array();
		if ( ! is_array( $children ) ) {
			$this->error( $id, $path, 'invalid_elements', 'elements must be a list of child elements.' );
			return;
		}
		// A Pro element shown as a promotion without Pro (e-form, e-collection-loop) keeps its stored children as they are.
		if ( $type && method_exists( $type, 'get_meta_item' ) && $type->get_meta_item( 'is_pro_promotion' ) ) {
			return;
		}
		$this->walk( $children, "{$path}.elements", $depth + 1 );
	}

	// Elementor's own check for v4 data: each prop in the element's schema must be a valid {"$$type": ..., "value": ...}.
	private function atomic_settings( $type, array $settings, $id, $path, $slug ) {
		$parser = '\Elementor\Modules\AtomicWidgets\Parsers\Props_Parser';
		if ( ! method_exists( $type, 'get_props_schema' ) || ! class_exists( $parser ) ) {
			return;
		}
		try {
			$result = $parser::make( $type::get_props_schema() )->validate( $settings );
			if ( $result->is_valid() ) {
				return;
			}
			$errors = $result->errors()->to_string();
		} catch ( \Throwable $e ) {
			// An Elementor whose parser differs is left to Elementor's own save, as before.
			return;
		}
		$this->error( $id, $path, 'invalid_settings', sprintf( 'These settings do not match the element\'s props schema (%1$s). Each value needs its {"$$type": ..., "value": ...} wrapper with the type the schema names: GET /wpvibe/v1/elementor/schema?slug=%2$s lists them.', $errors, rawurlencode( (string) $slug ) ) );
	}

	private function error( $id, $path, $code, $message ) {
		$this->errors[] = array( 'id' => $id, 'path' => $path, 'code' => $code, 'message' => $message );
	}
}
