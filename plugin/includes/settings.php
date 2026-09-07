<?php
/**
 * Settings validation and administration screen.
 *
 * @package Welcart_Tiered_Discounts
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the default stored settings shape.
 *
 * @return array<string, mixed>
 */
function wtd_default_settings(): array {
	return array(
		'schema_version' => 1,
		'target'         => array( 'mode' => 'all' ),
		'tiers'          => array(),
	);
}

/**
 * Validate and normalize a settings payload.
 *
 * Stored payloads use strict scalar types. Form payloads may use numeric
 * strings, and percentage values are converted from percent notation to
 * hundredths of a percent.
 *
 * @param mixed $input Settings payload.
 * @param bool  $form  Whether the payload came from the settings form.
 * @return array<string, mixed>
 * @throws wtd_settings_exception If the payload is invalid.
 */
function wtd_validate_settings( $input, bool $form = false ): array {
	if ( ! is_array( $input ) ) {
		throw wtd_settings_exception( 'invalid_schema', null, 'settings must be an array' );
	}
	if ( $form ) {
		$input = wtd_settings_normalize_empty_tiers( $input );
	}

	wtd_settings_require_keys( $input, array( 'schema_version', 'target', 'tiers' ), 'invalid_schema', null );
	$schema_version = wtd_settings_integer(
		$input['schema_version'],
		$form,
		1,
		1,
		'schema_version',
		null,
		'invalid_schema'
	);

	if ( ! is_array( $input['target'] ) ) {
		throw wtd_settings_exception( 'invalid_schema', null, 'target must be an array' );
	}
	wtd_settings_require_keys( $input['target'], array( 'mode' ), 'invalid_schema', null );
	if ( 'all' !== $input['target']['mode'] ) {
		throw wtd_settings_exception( 'invalid_schema', null, 'target mode is unsupported' );
	}

	if ( ! is_array( $input['tiers'] ) || ! wtd_settings_is_list( $input['tiers'] ) ) {
		throw wtd_settings_exception( 'invalid_schema', null, 'tiers must be a sequential list' );
	}

	$tiers      = array();
	$thresholds = array();
	foreach ( $input['tiers'] as $row => $tier ) {
		if ( ! is_array( $tier ) ) {
			throw wtd_settings_exception( 'invalid_tier', (int) $row, 'tier must be an array' );
		}
		wtd_settings_require_keys(
			$tier,
			array( 'threshold', 'type', 'value', 'enabled' ),
			'invalid_tier',
			(int) $row
		);

		$threshold = wtd_settings_integer(
			$tier['threshold'],
			$form,
			1,
			99999999,
			'threshold',
			(int) $row,
			'invalid_tier'
		);
		if ( array_key_exists( $threshold, $thresholds ) ) {
			throw wtd_settings_exception( 'invalid_tier', (int) $row, 'threshold is duplicated' );
		}
		$thresholds[ $threshold ] = true;

		if ( ! is_string( $tier['type'] ) || ! in_array( $tier['type'], array( 'fixed', 'percentage' ), true ) ) {
			throw wtd_settings_exception( 'invalid_tier', (int) $row, 'type is unsupported' );
		}

		if ( 'percentage' === $tier['type'] ) {
			$value = wtd_settings_percentage( $tier['value'], $form, (int) $row );
		} else {
			$value = wtd_settings_integer(
				$tier['value'],
				$form,
				1,
				99999999,
				'value',
				(int) $row,
				'invalid_tier'
			);
		}

		$enabled = wtd_settings_enabled( $tier['enabled'], $form, (int) $row );
		$tiers[] = array(
			'threshold' => $threshold,
			'type'      => $tier['type'],
			'value'     => $value,
			'enabled'   => $enabled,
		);
	}

	usort(
		$tiers,
		static function ( array $left, array $right ): int {
			return $left['threshold'] <=> $right['threshold'];
		}
	);

	return array(
		'schema_version' => $schema_version,
		'target'         => array( 'mode' => 'all' ),
		'tiers'          => $tiers,
	);
}

/**
 * Convert the explicit empty-list form marker into the stored list shape.
 *
 * The marker is form-only because a browser omits all row controls when the
 * last row is removed. Stored options continue to require a sequential list.
 *
 * @param array<string,mixed> $input Form payload.
 * @return array<string,mixed> Form payload with an empty list when marked.
 * @throws wtd_settings_exception If the marker is malformed or mixed with a row.
 */
function wtd_settings_normalize_empty_tiers( array $input ): array {
	if ( ! isset( $input['tiers'] ) || ! is_array( $input['tiers'] ) || ! array_key_exists( '__empty', $input['tiers'] ) ) {
		return $input;
	}

	if ( 1 !== count( $input['tiers'] ) || '1' !== $input['tiers']['__empty'] ) {
		throw wtd_settings_exception( 'invalid_schema', null, 'empty tier marker is malformed' );
	}

	$input['tiers'] = array();
	return $input;
}

/**
 * Require the exact set of keys used by a schema object.
 *
 * @param array<mixed>  $value Expected object.
 * @param array<string> $keys  Allowed keys.
 * @param string        $reason Error reason.
 * @param int|null      $row    Optional row number.
 * @return void
 * @throws wtd_settings_exception If keys are missing or unknown.
 */
function wtd_settings_require_keys( array $value, array $keys, string $reason, ?int $row ): void {
	$actual   = array_keys( $value );
	$expected = $keys;
	sort( $actual );
	sort( $expected );
	if ( $actual !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- key names and reason are internal validation metadata.
		throw wtd_settings_exception( $reason, $row, 'unexpected or missing keys' );
	}
}

/**
 * Check whether an array is a zero-based sequential list.
 *
 * @param array<mixed> $value Candidate list.
 * @return bool
 */
function wtd_settings_is_list( array $value ): bool {
	if ( array() === $value ) {
		return true;
	}

	return array_keys( $value ) === range( 0, count( $value ) - 1 );
}

/**
 * Normalize an integer field.
 *
 * @param mixed    $value  Field value.
 * @param bool     $form   Whether form coercion is allowed.
 * @param int      $minimum Inclusive lower bound.
 * @param int      $maximum Inclusive upper bound.
 * @param string   $field  Field name for the safe error.
 * @param int|null $row    Optional row number.
 * @param string   $reason Error reason.
 * @return int
 * @throws wtd_settings_exception If the value is invalid.
 */
function wtd_settings_integer(
	$value,
	bool $form,
	int $minimum,
	int $maximum,
	string $field,
	?int $row,
	string $reason
): int {
	if ( ! $form ) {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field and bounds are internal validation metadata.
			throw wtd_settings_exception( $reason, $row, $field . ' is out of range' );
		}
		return $value;
	}

	if ( is_int( $value ) ) {
		$digits = (string) $value;
	} elseif ( is_string( $value ) && preg_match( '/\A[0-9]+\z/D', $value ) ) {
		$digits = ltrim( $value, '0' );
		$digits = '' === $digits ? '0' : $digits;
	} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field name is internal validation metadata.
		throw wtd_settings_exception( $reason, $row, $field . ' must be an integer' );
	}

	$maximum_string = (string) $maximum;
	if ( strlen( $digits ) > strlen( $maximum_string ) || ( strlen( $digits ) === strlen( $maximum_string ) && strcmp( $digits, $maximum_string ) > 0 ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field name is internal validation metadata.
		throw wtd_settings_exception( $reason, $row, $field . ' is out of range' );
	}

	$normalized = (int) $digits;
	if ( $normalized < $minimum || $normalized > $maximum ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field name is internal validation metadata.
		throw wtd_settings_exception( $reason, $row, $field . ' is out of range' );
	}

	return $normalized;
}

/**
 * Normalize a percentage field to hundredths of a percent.
 *
 * @param mixed $value Field value.
 * @param bool  $form  Whether form coercion is allowed.
 * @param int   $row   Row number.
 * @return int
 * @throws wtd_settings_exception If the value is invalid.
 */
function wtd_settings_percentage( $value, bool $form, int $row ): int {
	if ( ! $form ) {
		return wtd_settings_integer( $value, false, 1, 10000, 'value', $row, 'invalid_tier' );
	}

	if ( is_int( $value ) ) {
		$value = (string) $value;
	}
	if ( ! is_string( $value ) || ! preg_match( '/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/D', $value, $matches ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- row number and field name are internal validation metadata.
		throw wtd_settings_exception( 'invalid_tier', $row, 'value must be a percentage' );
	}

	$whole = ltrim( $matches[1], '0' );
	$whole = '' === $whole ? '0' : $whole;
	if ( strlen( $whole ) > 3 || ( strlen( $whole ) === 3 && strcmp( $whole, '100' ) > 0 ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- row number and field name are internal validation metadata.
		throw wtd_settings_exception( 'invalid_tier', $row, 'value is out of range' );
	}

	$fraction   = isset( $matches[2] ) ? str_pad( $matches[2], 2, '0' ) : '00';
	$normalized = ( (int) $whole * 100 ) + (int) $fraction;
	if ( $normalized < 1 || $normalized > 10000 ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- row number and field name are internal validation metadata.
		throw wtd_settings_exception( 'invalid_tier', $row, 'value is out of range' );
	}

	return $normalized;
}

/**
 * Normalize the enabled checkbox.
 *
 * @param mixed $value Field value.
 * @param bool  $form  Whether form coercion is allowed.
 * @param int   $row   Row number.
 * @return bool
 * @throws wtd_settings_exception If the value is invalid.
 */
function wtd_settings_enabled( $value, bool $form, int $row ): bool {
	if ( ! $form ) {
		if ( ! is_bool( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- row number and field name are internal validation metadata.
			throw wtd_settings_exception( 'invalid_tier', $row, 'enabled must be boolean' );
		}
		return $value;
	}

	if ( true === $value || 1 === $value || '1' === $value ) {
		return true;
	}
	if ( false === $value || 0 === $value || '0' === $value ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- row number and field name are internal validation metadata.
	throw wtd_settings_exception( 'invalid_tier', $row, 'enabled must be a checkbox value' );
}

/* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reason, row, and detail are internal validation metadata. */
/**
 * Build an exception without including submitted values.
 *
 * @param string   $reason Safe reason code.
 * @param int|null $row    Optional row number.
 * @param string   $detail Safe detail text.
 * @return InvalidArgumentException
 */
function wtd_settings_exception( string $reason, ?int $row, string $detail ): InvalidArgumentException {
	$message = $reason;
	if ( null !== $row ) {
		$message .= ': row ' . $row;
	}
	if ( '' !== $detail ) {
		$message .= ': ' . $detail;
	}

	return new InvalidArgumentException( $message );
}
/* phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped */

/**
 * Read the raw option while preserving the distinction between absent and false.
 *
 * @return array{exists: bool, value: mixed}
 */
function wtd_settings_read_raw_option(): array {
	if ( ! function_exists( 'get_option' ) ) {
		return array(
			'exists' => false,
			'value'  => wtd_default_settings(),
		);
	}

	$missing = new stdClass();
	$value   = get_option( 'welcart_tiered_discounts', $missing );
	if ( $missing === $value ) {
		return array(
			'exists' => false,
			'value'  => wtd_default_settings(),
		);
	}

	return array(
		'exists' => true,
		'value'  => $value,
	);
}

/**
 * Return the validated settings, or an error for a corrupt stored option.
 *
 * @return array<string, mixed>|WP_Error
 */
function wtd_get_settings() {
	$stored = wtd_settings_read_raw_option();
	if ( ! $stored['exists'] ) {
		return wtd_default_settings();
	}

	try {
		return wtd_validate_settings( $stored['value'] );
	} catch ( InvalidArgumentException $exception ) {
		$reason = wtd_settings_exception_reason( $exception );
		wtd_settings_log_error( $reason, 'wtd_get_settings' );
		return new WP_Error(
			'wtd_invalid_settings',
			__( '設定データを読み込めません。管理者へ連絡してください。', 'welcart-tiered-discounts' ),
			array( 'reason' => $reason )
		);
	}
}

/**
 * Settings API sanitization callback.
 *
 * @param mixed $input Submitted form payload or already normalized settings.
 * @return mixed Normalized settings or the original option on failure.
 */
function wtd_sanitize_settings( $input ) {
	$stored = wtd_settings_read_raw_option();
	try {
		// update_option() may call add_option(), which sanitizes again. Form versions are strings; normalized versions are integers.
		$form = ! ( is_array( $input ) && isset( $input['schema_version'] ) && is_int( $input['schema_version'] ) );
		return wtd_validate_settings( $input, $form );
	} catch ( InvalidArgumentException $exception ) {
		$reason = wtd_settings_exception_reason( $exception );
		wtd_settings_log_error( $reason, 'wtd_sanitize_settings' );
		wtd_settings_add_error( $exception );
		return $stored['value'];
	}
}

/**
 * Register the plugin's administration hooks.
 *
 * @return void
 */
function wtd_settings_hooks(): void {
	if ( ! function_exists( 'add_action' ) ) {
		return;
	}

	add_action( 'admin_menu', 'wtd_register_settings_page' );
	add_action( 'admin_init', 'wtd_register_settings' );
	add_action( 'admin_enqueue_scripts', 'wtd_enqueue_settings_assets' );
}

/**
 * Add the settings page under Settings.
 *
 * @return void
 */
function wtd_register_settings_page(): void {
	if ( ! function_exists( 'add_options_page' ) ) {
		return;
	}

	add_options_page(
		__( 'ステップ割引', 'welcart-tiered-discounts' ),
		__( 'ステップ割引', 'welcart-tiered-discounts' ),
		'manage_options',
		'welcart-tiered-discounts',
		'wtd_render_settings_page'
	);
}

/**
 * Register the option with the WordPress Settings API.
 *
 * @return void
 */
function wtd_register_settings(): void {
	if ( ! function_exists( 'register_setting' ) ) {
		return;
	}

	register_setting(
		'welcart_tiered_discounts',
		'welcart_tiered_discounts',
		array(
			'type'              => 'array',
			'description'       => __( 'ステップ割引のティア一覧', 'welcart-tiered-discounts' ),
			'sanitize_callback' => 'wtd_sanitize_settings',
			'default'           => wtd_default_settings(),
			'capability'        => 'manage_options',
		)
	);
}

/**
 * Enqueue settings JavaScript only on this page.
 *
 * @param string $hook_suffix Current admin screen hook suffix.
 * @return void
 */
function wtd_enqueue_settings_assets( string $hook_suffix ): void {
	if ( 'settings_page_welcart-tiered-discounts' !== $hook_suffix ) {
		return;
	}
	if ( ! defined( 'WTD_FILE' ) || ! function_exists( 'plugins_url' ) || ! function_exists( 'wp_enqueue_script' ) ) {
		return;
	}

	$version = defined( 'WTD_VERSION' ) ? WTD_VERSION : false;
	wp_enqueue_script(
		'wtd-settings',
		plugins_url( 'assets/settings.js', WTD_FILE ),
		array(),
		$version,
		true
	);
	if ( function_exists( 'wp_localize_script' ) ) {
		wp_localize_script(
			'wtd-settings',
			'wtdSettings',
			array(
				'labels' => array(
					'enabled'     => __( 'このティアを有効にする', 'welcart-tiered-discounts' ),
					'threshold'   => __( 'しきい値（円）', 'welcart-tiered-discounts' ),
					'type'        => __( '割引方式', 'welcart-tiered-discounts' ),
					'fixed'       => __( '固定額', 'welcart-tiered-discounts' ),
					'percentage'  => __( '割合（%）', 'welcart-tiered-discounts' ),
					'value'       => __( '割引値', 'welcart-tiered-discounts' ),
					'delete'      => __( 'このティアを削除', 'welcart-tiered-discounts' ),
					'deleteLabel' => __( '削除', 'welcart-tiered-discounts' ),
				),
			)
		);
	}
}

/**
 * Render the settings page.
 *
 * @return void
 */
function wtd_render_settings_page(): void {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = wtd_get_settings();
	if ( is_wp_error( $settings ) ) {
		wtd_settings_add_error_message( __( '設定データが壊れているため、修復まで割引を利用できません。', 'welcart-tiered-discounts' ) );
		$settings = wtd_default_settings();
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html( __( 'ステップ割引', 'welcart-tiered-discounts' ) ); ?></h1>
		<?php
		if ( function_exists( 'settings_errors' ) ) {
			settings_errors( 'welcart_tiered_discounts' );
		}
		?>
		<form method="post" action="options.php">
			<?php
			if ( function_exists( 'settings_fields' ) ) {
				settings_fields( 'welcart_tiered_discounts' );
			}
			?>
			<input type="hidden" name="welcart_tiered_discounts[schema_version]" value="1" />
			<input type="hidden" name="welcart_tiered_discounts[target][mode]" value="all" />
			<p><?php echo esc_html( __( '対象小計に応じて、商品代金全体へ1回だけ割引を適用します。', 'welcart-tiered-discounts' ) ); ?></p>
			<?php wtd_render_tiers_table( $settings['tiers'] ); ?>
			<?php submit_button( __( '変更を保存', 'welcart-tiered-discounts' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Render the editable tier table.
 *
 * @param array<int, array<string, mixed>> $tiers Validated tier list.
 * @return void
 */
function wtd_render_tiers_table( array $tiers ): void {
	$has_tiers = ! empty( $tiers );
	$hidden    = $has_tiers ? ' hidden' : '';
	?>
	<div class="wtd-settings-field" id="wtd-tiers-field">
		<p id="wtd-tiers-description" class="description">
			<?php echo esc_html( __( 'しきい値の昇順で保存されます。同じしきい値は有効・無効を問わず登録できません。', 'welcart-tiered-discounts' ) ); ?>
		</p>
		<?php if ( ! $has_tiers ) : ?>
			<input type="hidden" data-wtd-empty-sentinel name="welcart_tiered_discounts[tiers][__empty]" value="1" />
		<?php endif; ?>
		<table class="widefat striped" data-wtd-tier-list aria-describedby="wtd-tiers-description"<?php echo esc_attr( $hidden ); ?>>
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( __( '有効', 'welcart-tiered-discounts' ) ); ?></th>
					<th scope="col"><?php echo esc_html( __( 'しきい値（円）', 'welcart-tiered-discounts' ) ); ?></th>
					<th scope="col"><?php echo esc_html( __( '方式', 'welcart-tiered-discounts' ) ); ?></th>
					<th scope="col"><?php echo esc_html( __( '割引値', 'welcart-tiered-discounts' ) ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php echo esc_html( __( '操作', 'welcart-tiered-discounts' ) ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $tiers as $index => $tier ) : ?>
				<?php wtd_render_tier_row( (int) $index, $tier ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p data-wtd-empty<?php echo $has_tiers ? ' hidden' : ''; ?>>
			<?php echo esc_html( __( 'ティアがありません。割引は適用されません。', 'welcart-tiered-discounts' ) ); ?>
		</p>
		<p>
			<button type="button" class="button" data-wtd-add-tier><?php echo esc_html( __( 'ティアを追加', 'welcart-tiered-discounts' ) ); ?></button>
		</p>
		<noscript>
			<p class="description"><?php echo esc_html( __( 'ティアの追加と削除にはJavaScriptを有効にしてください。', 'welcart-tiered-discounts' ) ); ?></p>
		</noscript>
	</div>
	<?php
}

/**
 * Render one tier row.
 *
 * @param int                  $index Row index.
 * @param array<string, mixed> $tier  Validated tier.
 * @return void
 */
function wtd_render_tier_row( int $index, array $tier ): void {
	$base          = 'welcart_tiered_discounts[tiers][' . $index . ']';
	$threshold_id  = 'wtd-tier-' . $index . '-threshold';
	$type_id       = 'wtd-tier-' . $index . '-type';
	$value_id      = 'wtd-tier-' . $index . '-value';
	$enabled_id    = 'wtd-tier-' . $index . '-enabled';
	$is_percentage = 'percentage' === $tier['type'];
	$value         = $is_percentage ? wtd_settings_percentage_display( (int) $tier['value'] ) : (string) $tier['value'];
	?>
	<tr data-wtd-tier-row data-row-index="<?php echo (int) $index; ?>">
		<td>
			<input type="hidden" data-wtd-field="enabled" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="0" />
			<input type="checkbox" data-wtd-field="enabled" id="<?php echo esc_attr( $enabled_id ); ?>" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1"<?php checked( $tier['enabled'], true ); ?> />
			<label for="<?php echo esc_attr( $enabled_id ); ?>" class="screen-reader-text"><?php echo esc_html( __( 'このティアを有効にする', 'welcart-tiered-discounts' ) ); ?></label>
		</td>
		<td>
			<label for="<?php echo esc_attr( $threshold_id ); ?>" class="screen-reader-text"><?php echo esc_html( __( 'しきい値（円）', 'welcart-tiered-discounts' ) ); ?></label>
			<input type="number" data-wtd-field="threshold" id="<?php echo esc_attr( $threshold_id ); ?>" name="<?php echo esc_attr( $base . '[threshold]' ); ?>" value="<?php echo esc_attr( $tier['threshold'] ); ?>" min="1" max="99999999" step="1" inputmode="numeric" required />
		</td>
		<td>
			<label for="<?php echo esc_attr( $type_id ); ?>" class="screen-reader-text"><?php echo esc_html( __( '割引方式', 'welcart-tiered-discounts' ) ); ?></label>
			<select data-wtd-field="type" id="<?php echo esc_attr( $type_id ); ?>" name="<?php echo esc_attr( $base . '[type]' ); ?>">
				<option value="fixed"<?php selected( $tier['type'], 'fixed' ); ?>><?php echo esc_html( __( '固定額', 'welcart-tiered-discounts' ) ); ?></option>
				<option value="percentage"<?php selected( $tier['type'], 'percentage' ); ?>><?php echo esc_html( __( '割合（%）', 'welcart-tiered-discounts' ) ); ?></option>
			</select>
		</td>
		<td>
			<label for="<?php echo esc_attr( $value_id ); ?>" class="screen-reader-text"><?php echo esc_html( __( '割引値', 'welcart-tiered-discounts' ) ); ?></label>
			<input type="number" data-wtd-field="value" id="<?php echo esc_attr( $value_id ); ?>" name="<?php echo esc_attr( $base . '[value]' ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo $is_percentage ? '0.01' : '1'; ?>" max="<?php echo $is_percentage ? '100' : '99999999'; ?>" step="<?php echo $is_percentage ? '0.01' : '1'; ?>" inputmode="<?php echo $is_percentage ? 'decimal' : 'numeric'; ?>" required />
		</td>
		<td>
			<button type="button" class="button-link-delete" data-wtd-remove-tier aria-label="<?php echo esc_attr( __( 'このティアを削除', 'welcart-tiered-discounts' ) ); ?>"><?php echo esc_html( __( '削除', 'welcart-tiered-discounts' ) ); ?></button>
		</td>
	</tr>
	<?php
}
/**
 * Convert stored percentage hundredths to display notation.
 *
 * @param int $value Stored percentage.
 * @return string
 */
function wtd_settings_percentage_display( int $value ): string {
	$whole    = intdiv( $value, 100 );
	$fraction = $value % 100;
	if ( 0 === $fraction ) {
		return (string) $whole;
	}

	return $whole . '.' . str_pad( (string) $fraction, 2, '0', STR_PAD_LEFT );
}

/**
 * Add a settings error for a failed sanitizer callback.
 *
 * @param InvalidArgumentException $exception Validation exception.
 * @return void
 */
function wtd_settings_add_error( InvalidArgumentException $exception ): void {
	$message = __( '設定を保存できません。入力内容を確認してください。', 'welcart-tiered-discounts' );
	if ( preg_match( '/row ([0-9]+)/', $exception->getMessage(), $matches ) ) {
		$message = sprintf(
			/* translators: %d: one-based settings row number. */
			__( '%d行目の入力を確認してください。', 'welcart-tiered-discounts' ),
			(int) $matches[1] + 1
		);
	}
	wtd_settings_add_error_message( $message );
}

/**
 * Add an escaped settings error through the Settings API.
 *
 * @param string $message Safe message.
 * @return void
 */
function wtd_settings_add_error_message( string $message ): void {
	static $added_messages = array();

	if ( isset( $added_messages[ $message ] ) ) {
		return;
	}

	$added_messages[ $message ] = true;

	if ( function_exists( 'add_settings_error' ) ) {
		if ( function_exists( 'get_settings_errors' ) ) {
			foreach ( get_settings_errors( 'welcart_tiered_discounts' ) as $error ) {
				if ( isset( $error['code'], $error['message'] ) && 'wtd_invalid_settings' === $error['code'] && $message === $error['message'] ) {
					return;
				}
			}
		}
		add_settings_error(
			'welcart_tiered_discounts',
			'wtd_invalid_settings',
			esc_html( $message ),
			'error'
		);
	}
}

/**
 * Extract a safe reason code from a validation exception.
 *
 * @param InvalidArgumentException $exception Validation exception.
 * @return string
 */
function wtd_settings_exception_reason( InvalidArgumentException $exception ): string {
	$reason = strtok( $exception->getMessage(), ':' );
	if ( ! is_string( $reason ) || ! preg_match( '/\A[a-z_]+\z/D', $reason ) ) {
		return 'invalid_schema';
	}

	return $reason;
}

/**
 * Write a non-secret diagnostic to the existing PHP/WordPress log.
 *
 * @param string $reason  Safe reason code.
 * @param string $context Safe function name.
 * @return void
 */
function wtd_settings_log_error( string $reason, string $context ): void {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- SSoT requires a non-secret reason code in the existing PHP log.
	error_log( '[welcart-tiered-discounts] ' . $reason . ' in ' . $context );
}
