<?php
/**
 * Field renderers — server-side HTML output for each field type.
 *
 * Each render method takes ($key, $value, $config) and outputs the full
 * field row (label + input + description). Renderers are static; state
 * lives in the registry, not here. Picker JS (in assets/fields/admin.js)
 * hooks the .wpvibe-field-* CSS classes the complex renderers emit.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Field_Renderers {

	/**
	 * Dispatch to the right renderer by type.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param array  $config Normalized field config.
	 * @param string $context 'meta' for post meta boxes, 'setting' for the settings page.
	 */
	public static function render( $key, $value, $config, $context = 'meta' ) {
		$method = 'render_' . $config['type'];
		if ( method_exists( __CLASS__, $method ) ) {
			self::$method( $key, $value, $config, $context );
		} else {
			self::render_text( $key, $value, $config, $context );
		}
	}

	// ------------------------------------------------------------------
	// Simple types
	// ------------------------------------------------------------------

	public static function render_text( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		printf(
			'<input id="%1$s" type="text" name="%2$s" value="%3$s" class="regular-text" />',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		self::description( $config );
		self::close_row();
	}

	public static function render_textarea( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		printf(
			'<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			esc_textarea( (string) $value )
		);
		self::description( $config );
		self::close_row();
	}

	public static function render_number( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		printf(
			'<input id="%1$s" type="number" step="any" name="%2$s" value="%3$s" class="small-text" />',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		self::description( $config );
		self::close_row();
	}

	public static function render_email( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		printf(
			'<input id="%1$s" type="email" name="%2$s" value="%3$s" class="regular-text" />',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		self::description( $config );
		self::close_row();
	}

	public static function render_url( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		printf(
			'<input id="%1$s" type="url" name="%2$s" value="%3$s" class="regular-text" placeholder="https://..." />',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		self::description( $config );
		self::close_row();
	}

	public static function render_date( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		printf(
			'<input id="%1$s" type="date" name="%2$s" value="%3$s" />',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		self::description( $config );
		self::close_row();
	}

	/**
	 * Checkboxes get a different label arrangement (label sits to the right
	 * of the input). A hidden 0-value sibling guarantees the meta key is
	 * always present in POST so unchecked boxes record explicitly as 0.
	 */
	public static function render_checkbox( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		printf(
			'<label for="%1$s" style="font-weight:normal"><input type="hidden" name="%2$s" value="0" /><input id="%1$s" type="checkbox" name="%2$s" value="1" %3$s /> %4$s</label>',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			checked( 1, (int) $value, false ),
			esc_html( $config['label'] )
		);
		self::description( $config );
		self::close_row();
	}

	// ------------------------------------------------------------------
	// Complex types (picker JS hooks via CSS classes)
	// ------------------------------------------------------------------

	public static function render_color( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		printf(
			'<input id="%1$s" type="text" name="%2$s" value="%3$s" class="wpvibe-field-color" />',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
		self::description( $config );
		self::close_row();
	}

	public static function render_image( $key, $value, $config, $context = 'meta' ) {
		$attachment_id = absint( $value );
		$image_url     = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';

		self::open_row();
		self::label( $key, $config );
		?>
		<div class="wpvibe-field-image">
			<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
			<div class="wpvibe-field-image-preview" style="margin-bottom:8px;<?php echo $image_url ? '' : 'display:none'; ?>">
				<img src="<?php echo esc_url( (string) $image_url ); ?>" alt="" style="max-width:240px;border:1px solid #c3c4c7;border-radius:4px;" />
			</div>
			<button type="button" class="button wpvibe-field-image-pick"><?php esc_html_e( 'Choose image', 'vibe-ai' ); ?></button>
			<button type="button" class="button wpvibe-field-image-clear" <?php echo $image_url ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove', 'vibe-ai' ); ?></button>
		</div>
		<?php
		self::description( $config );
		self::close_row();
	}

	public static function render_gallery( $key, $value, $config, $context = 'meta' ) {
		$ids = is_array( $value ) ? array_map( 'absint', $value ) : array();
		$ids = array_values( array_filter( $ids ) );

		self::open_row();
		self::label( $key, $config );
		?>
		<div class="wpvibe-field-gallery">
			<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
			<div class="wpvibe-field-gallery-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-bottom:8px;max-width:560px">
				<?php foreach ( $ids as $id ) :
					$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
					if ( ! $thumb ) {
						continue;
					}
				?>
					<div class="wpvibe-field-gallery-item" data-id="<?php echo esc_attr( (string) $id ); ?>" style="position:relative;aspect-ratio:1;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;cursor:move">
						<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:100%;height:100%;object-fit:cover" />
						<button type="button" class="wpvibe-field-gallery-remove" style="position:absolute;top:4px;right:4px;background:#1d2327;color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:14px;line-height:1;padding:0">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button wpvibe-field-gallery-add"><?php esc_html_e( 'Add images', 'vibe-ai' ); ?></button>
		</div>
		<?php
		self::description( $config );
		self::close_row();
	}

	public static function render_wysiwyg( $key, $value, $config, $context = 'meta' ) {
		self::open_row();
		self::label( $key, $config );
		wp_editor( (string) $value, self::input_id( $key ), array(
			'textarea_name' => $key,
			'media_buttons' => true,
			'textarea_rows' => 6,
			'tinymce'       => array(
				'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,blockquote,undo,redo',
			),
		) );
		self::description( $config );
		self::close_row();
	}

	public static function render_post_select( $key, $value, $config, $context = 'meta' ) {
		$target_type = isset( $config['options']['post_type'] ) ? $config['options']['post_type'] : 'post';
		$selected_id = absint( $value );

		$posts = get_posts( array(
			'post_type'      => $target_type,
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => 'publish',
		) );

		self::open_row();
		self::label( $key, $config );
		printf(
			'<select id="%1$s" name="%2$s" class="wpvibe-field-post-select" style="min-width:280px">',
			esc_attr( self::input_id( $key ) ),
			esc_attr( $key )
		);
		echo '<option value="0">' . esc_html__( '— none —', 'vibe-ai' ) . '</option>';
		foreach ( $posts as $post ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $post->ID,
				selected( $selected_id, (int) $post->ID, false ),
				esc_html( $post->post_title )
			);
		}
		echo '</select>';
		self::description( $config );
		self::close_row();
	}

	public static function render_repeater( $key, $value, $config, $context = 'meta' ) {
		$rows       = is_array( $value ) ? $value : array();
		$sub_fields = isset( $config['sub_fields'] ) ? $config['sub_fields'] : array();

		self::open_row();
		self::label( $key, $config );
		?>
		<div class="wpvibe-field-repeater">
			<input type="hidden" name="<?php echo esc_attr( $key ); ?>" class="wpvibe-field-repeater-input" value="<?php echo esc_attr( wp_json_encode( $rows ) ); ?>" />
			<div class="wpvibe-field-repeater-rows">
				<?php foreach ( $rows as $row ) : ?>
					<?php self::render_repeater_row( $row, $sub_fields ); ?>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button wpvibe-field-repeater-add"><?php esc_html_e( '+ Add row', 'vibe-ai' ); ?></button>
			<template class="wpvibe-field-repeater-template"><?php self::render_repeater_row( array(), $sub_fields ); ?></template>
		</div>
		<?php
		self::description( $config );
		self::close_row();
	}

	private static function render_repeater_row( $row, $sub_fields ) {
		?>
		<div class="wpvibe-field-repeater-row" style="display:flex;gap:8px;align-items:center;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;margin-bottom:6px;flex-wrap:wrap">
			<span class="wpvibe-field-repeater-handle" style="cursor:grab;color:#8c8f94;flex:0 0 auto">&#x22EE;&#x22EE;</span>
			<?php foreach ( $sub_fields as $sub_key => $sub_config ) :
				if ( ! is_array( $sub_config ) ) {
					$sub_config = array( 'type' => 'text' );
				}
				$sub_type  = isset( $sub_config['type'] ) ? $sub_config['type'] : 'text';
				$sub_label = isset( $sub_config['label'] ) ? $sub_config['label'] : ucfirst( str_replace( '_', ' ', $sub_key ) );
				$sub_value = isset( $row[ $sub_key ] ) ? $row[ $sub_key ] : '';
				$html_type = in_array( $sub_type, array( 'number', 'email', 'url', 'date' ), true ) ? $sub_type : 'text';
				$style     = 'number' === $html_type ? 'width:100px' : 'flex:1;min-width:120px';
			?>
				<input type="<?php echo esc_attr( $html_type ); ?>" data-sub="<?php echo esc_attr( $sub_key ); ?>" value="<?php echo esc_attr( is_scalar( $sub_value ) ? (string) $sub_value : '' ); ?>" placeholder="<?php echo esc_attr( $sub_label ); ?>" style="<?php echo esc_attr( $style ); ?>" />
			<?php endforeach; ?>
			<button type="button" class="wpvibe-field-repeater-remove" style="background:transparent;border:none;color:#b32d2e;cursor:pointer;font-size:18px;flex:0 0 auto">&times;</button>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private static function open_row() {
		echo '<div class="wpvibe-field-row">';
	}

	private static function close_row() {
		echo '</div>';
	}

	private static function label( $key, $config ) {
		if ( '' === $config['label'] ) {
			return;
		}
		printf(
			'<label for="%1$s">%2$s</label>',
			esc_attr( self::input_id( $key ) ),
			esc_html( $config['label'] )
		);
	}

	private static function description( $config ) {
		if ( '' === $config['description'] ) {
			return;
		}
		echo '<p class="description">' . esc_html( $config['description'] ) . '</p>';
	}

	private static function input_id( $key ) {
		return 'wpvibe-field-' . preg_replace( '/[^a-z0-9_-]/i', '-', $key );
	}
}
