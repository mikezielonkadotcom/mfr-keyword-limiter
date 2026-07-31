<?php

defined( 'ABSPATH' ) || exit;

final class MFR_Feature_Telemetry {
	public static function config(): array {
		return array(
			'schema_version' => 1,
			'fields' => array(
				'media_file_renamer_available' => array( 'type' => 'boolean' ),
				'seo_provider' => array( 'type' => 'enum', 'values' => array( 'multiple', 'none', 'rankmath', 'seopress', 'yoast' ) ),
				'wprm_available' => array( 'type' => 'boolean' ),
			),
			'callback' => array( self::class, 'snapshot' ),
		);
	}

	public static function snapshot(): array {
		$providers = array();
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) { $providers[] = 'yoast'; }
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) { $providers[] = 'rankmath'; }
		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) ) { $providers[] = 'seopress'; }
		return array(
			'media_file_renamer_available' => defined( 'MFRH_VERSION' ) || class_exists( 'Meow_MFRH_Core' ) || has_filter( 'mfrh_ai_prompt' ),
			'seo_provider' => count( $providers ) > 1 ? 'multiple' : ( $providers[0] ?? 'none' ),
			'wprm_available' => class_exists( 'WPRM_Recipe_Manager' ),
		);
	}
}
