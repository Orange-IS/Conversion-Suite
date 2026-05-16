<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Custom_Reports_Trait {

	/**
	 * Send Reports: scheduled snapshots from Custom Dashboard templates (CSV + HTML intro).
	 */
	public function display_custom_reports_page() {
		if ( ! current_user_can( 'manage_ois_marketing' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ois-conversion-suite' ) );
		}

		$dashboards = get_option( 'oiscl_custom_dashboards', array() );
		if ( ! is_array( $dashboards ) ) {
			$dashboards = array();
		}

		$box = OISCL_Scheduled_Reports::get_jobs_container();

		echo '<div class="wrap oiscl-layout-root">';
		echo '<h1 class="oiscl-admin-page-title">' . esc_html__( '📑 Send Reports', 'ois-conversion-suite' ) . '</h1>';

		if ( ! empty( $_GET['oiscl_sched_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Programación guardada. El primer envío puede tardar hasta una hora (cron horario).', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( ! empty( $_GET['oiscl_sched_deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Programación eliminada.', 'ois-conversion-suite' ) . '</p></div>';
		}
		if ( isset( $_GET['oiscl_sched_err'] ) ) {
			$err = sanitize_key( wp_unslash( $_GET['oiscl_sched_err'] ) );
			$msg = 'dashboard' === $err
				? __( 'Selecciona un tablero válido.', 'ois-conversion-suite' )
				: __( 'Indica al menos un correo válido.', 'ois-conversion-suite' );
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'Los envíos son snapshots: el CSV refleja las columnas configuradas en Custom Dashboards para el rango de fechas elegido (termina normalmente en «ayer», según el preset). Los gráficos del tablero no van en el CSV; amplía columnas tabulares si necesitas más datos en el adjunto.', 'ois-conversion-suite' );
		echo '</p></div>';

		if ( empty( $dashboards ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Aún no hay tableros en Custom Dashboards. Crea uno antes de programar envíos.', 'ois-conversion-suite' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=oiscl-custom-dashboards' ) ) . '">' . esc_html__( 'Abrir Custom Dashboards', 'ois-conversion-suite' ) . '</a>';
			echo '</p></div>';
		}

		$form_action = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;max-width:920px;margin-bottom:24px;">';
		echo '<h2 class="title" style="margin-top:0;">' . esc_html__( 'Nueva programación', 'ois-conversion-suite' ) . '</h2>';
		echo '<form method="post" action="' . $form_action . '">';
		wp_nonce_field( 'oiscl_save_report_schedule', 'oiscl_report_sched_nonce' );
		echo '<input type="hidden" name="action" value="oiscl_save_report_schedule" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="oiscl_sched_dash">' . esc_html__( 'Tablero / plantilla', 'ois-conversion-suite' ) . '</label></th><td>';
		echo '<select name="dashboard_id" id="oiscl_sched_dash" class="regular-text" required>';
		echo '<option value="">' . esc_html__( '— Seleccionar —', 'ois-conversion-suite' ) . '</option>';
		foreach ( $dashboards as $id => $row ) {
			$t = isset( $row['title'] ) ? (string) $row['title'] : $id;
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $t ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="oiscl_sched_emails">' . esc_html__( 'Destinatarios', 'ois-conversion-suite' ) . '</label></th><td>';
		echo '<textarea name="recipients" id="oiscl_sched_emails" class="large-text" rows="3" placeholder="cliente@empresa.com, otro@empresa.com" required></textarea>';
		echo '<p class="description">' . esc_html__( 'Separar con comas o saltos de línea.', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Cada cuánto enviar', 'ois-conversion-suite' ) . '</th><td>';
		echo '<select name="cadence">';
		echo '<option value="' . esc_attr( OISCL_Scheduled_Reports::CADENCE_WEEKLY ) . '">' . esc_html__( 'Cada 7 días', 'ois-conversion-suite' ) . '</option>';
		echo '<option value="' . esc_attr( OISCL_Scheduled_Reports::CADENCE_BIWEEKLY ) . '">' . esc_html__( 'Cada 14 días', 'ois-conversion-suite' ) . '</option>';
		echo '<option value="' . esc_attr( OISCL_Scheduled_Reports::CADENCE_MONTHLY ) . '">' . esc_html__( 'Cada ~30 días', 'ois-conversion-suite' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Intervalo aproximado entre envíos (cron horario).', 'ois-conversion-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Rango de datos (snapshot)', 'ois-conversion-suite' ) . '</th><td>';
		echo '<select name="period">';
		$presets = array(
			OISCL_Scheduled_Reports::PERIOD_ROLLING_7           => __( 'Últimos 7 días (hasta ayer)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_ROLLING_14          => __( 'Últimos 14 días (hasta ayer)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_ROLLING_30          => __( 'Últimos 30 días (hasta ayer)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_PREV_CALENDAR_MONTH => __( 'Mes calendario anterior (completo)', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_PREV_MONTH_1_15     => __( 'Mes anterior: día 1 al 15', 'ois-conversion-suite' ),
			OISCL_Scheduled_Reports::PERIOD_PREV_MONTH_16_END   => __( 'Mes anterior: día 16 al fin', 'ois-conversion-suite' ),
		);
		foreach ( $presets as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Guardar programación', 'ois-conversion-suite' ) );
		echo '</form>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Programaciones activas', 'ois-conversion-suite' ) . '</h2>';
		if ( empty( $box['jobs'] ) ) {
			echo '<p>' . esc_html__( 'No hay envíos programados.', 'ois-conversion-suite' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Tablero', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Destinatarios', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Cadencia', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Rango', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Próximo envío', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Último envío', 'ois-conversion-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Acciones', 'ois-conversion-suite' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $box['jobs'] as $job ) {
				$jid    = isset( $job['id'] ) ? (string) $job['id'] : '';
				$title  = isset( $job['dashboard_title'] ) ? (string) $job['dashboard_title'] : '';
				$rec    = isset( $job['recipients'] ) && is_array( $job['recipients'] ) ? implode( ', ', $job['recipients'] ) : '';
				$cad       = isset( $job['cadence'] ) ? (string) $job['cadence'] : '';
				$cad_label = OISCL_Scheduled_Reports::CADENCE_BIWEEKLY === $cad
					? __( 'Cada 14 días', 'ois-conversion-suite' )
					: ( OISCL_Scheduled_Reports::CADENCE_MONTHLY === $cad
						? __( 'Cada ~30 días', 'ois-conversion-suite' )
						: __( 'Cada 7 días', 'ois-conversion-suite' ) );
				$per    = isset( $job['period'] ) ? (string) $job['period'] : '';
				$next   = isset( $job['next_run'] ) ? (int) $job['next_run'] : 0;
				$last   = isset( $job['last_sent'] ) ? (int) $job['last_sent'] : 0;
				$delurl = wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'oiscl_delete_report_schedule',
							'job_id'  => rawurlencode( $jid ),
						),
						admin_url( 'admin-post.php' )
					),
					'oiscl_delete_report_schedule_' . $jid
				);
				echo '<tr>';
				echo '<td>' . esc_html( $title ) . '</td>';
				echo '<td>' . esc_html( $rec ) . '</td>';
				echo '<td>' . esc_html( $cad_label ) . '</td>';
				echo '<td><code>' . esc_html( $per ) . '</code></td>';
				echo '<td>' . ( $next ? esc_html( wp_date( 'Y-m-d H:i', $next ) ) : '—' ) . '</td>';
				echo '<td>' . ( $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : '—' ) . '</td>';
				echo '<td><a href="' . esc_url( $delurl ) . '" class="button-link-delete" onclick="return confirm(\'' . esc_js( __( '¿Eliminar esta programación?', 'ois-conversion-suite' ) ) . '\');">' . esc_html__( 'Eliminar', 'ois-conversion-suite' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}
}
