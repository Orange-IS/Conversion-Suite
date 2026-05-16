<?php
/**
 * Metric dictionary for Custom Dashboards (shared by admin UI and snapshot/cron code).
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OISCL_Dashboard_Dictionary {

	/**
	 * @return array{charts:array,donuts:array,columns:array}
	 */
	public static function all() {
		return array(
			'charts'  => array(
				'chart_traffic' => array(
					'label' => '📈 Evolución Tráfico',
					'desc'  => 'Vistas vs Usuarios',
				),
				'chart_hourly'  => array(
					'label' => '📊 Clics por Hora',
					'desc'  => 'Distribución diaria',
				),
			),
			'donuts'  => array(
				'donut_source'  => array(
					'label' => '🍩 Tráfico por Fuente',
					'desc'  => 'Origen del visitante',
				),
				'donut_device'  => array(
					'label' => '📱 Dispositivos',
					'desc'  => 'Móvil vs PC vs Tablet',
				),
				'donut_os'      => array(
					'label' => '💻 Sistema Operativo',
					'desc'  => 'Windows, Mac, iOS...',
				),
				'donut_browser' => array(
					'label' => '🌐 Navegador',
					'desc'  => 'Chrome, Safari...',
				),
			),
			'columns' => array(
				'origin_url'      => array(
					'label' => '🔗 URL Origen',
					'type'  => 'dim',
				),
				'destination_url' => array(
					'label' => '🎯 URL Destino',
					'type'  => 'dim',
				),
				'anchor_text'     => array(
					'label' => '🖱️ Botón Clicado',
					'type'  => 'dim',
				),
				'context_text'    => array(
					'label' => '📖 Bloque Lectura',
					'type'  => 'dim',
				),
				'device'          => array(
					'label' => '📱 Dispositivo',
					'type'  => 'dim',
				),
				'os'              => array(
					'label' => '💻 Sistema',
					'type'  => 'dim',
				),
				'country'         => array(
					'label' => '🏳️ País',
					'type'  => 'dim',
				),
				'utm_campaign'    => array(
					'label' => '🚀 Campaña (UTM)',
					'type'  => 'dim',
				),
				'utm_term'        => array(
					'label' => '🔑 Keyword (UTM)',
					'type'  => 'dim',
				),
				'is_bot'          => array(
					'label' => '🤖 Tipo Tráfico',
					'type'  => 'dim',
				),
				'total_clicks'    => array(
					'label' => '📊 Total Acciones',
					'type'  => 'metric',
					'sql'   => 'SUM(clicks) as total_clicks',
				),
				'uniques'         => array(
					'label' => '👤 Usuarios Únicos',
					'type'  => 'metric',
					'sql'   => 'COUNT(DISTINCT session_id) as uniques',
				),
				'avg_time'        => array(
					'label' => '⏱️ Tiempo (s)',
					'type'  => 'metric',
					'sql'   => 'AVG(time_spent) as avg_time',
				),
			),
		);
	}
}
