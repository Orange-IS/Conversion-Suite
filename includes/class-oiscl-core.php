<?php

if ( ! defined( 'ABSPATH' ) ) exit;



class OISCL_Core {



    public function init() {

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_oiscl_assets' ) );

    }



    public function enqueue_oiscl_assets() {

        if ( is_admin() ) {

            return;

        }



        $settings = get_option( 'oiscl_settings', array( 'trackpro_enabled' => true ) );

        if ( empty( $settings['trackpro_enabled'] ) ) {

            return;

        }



        if ( ! empty( $settings['ignore_admins'] ) && current_user_can( 'manage_options' ) ) {

            return;

        }



        $post_id = (int) get_the_ID();

        if ( $post_id <= 0 || ! OISCL_Tracking::is_post_tracked( $post_id ) ) {

            return;

        }

        if ( ! OISCL_Activity::is_page_collecting( $post_id ) ) {

            return;

        }



        $fallback_ver = defined( 'OISCL_VERSION' ) ? OISCL_VERSION : '0.73.6';

        $css_file     = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/oiscl-public.css';

        $css_ver      = file_exists( $css_file ) ? filemtime( $css_file ) : $fallback_ver;

        wp_enqueue_style( 'oiscl-public-style', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/oiscl-public.css', array(), $css_ver );



        $js_file = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/oiscl-trackpro.min.js';

        $js_ver  = file_exists( $js_file ) ? filemtime( $js_file ) : $fallback_ver;

        wp_enqueue_script( 'oiscl-trackpro-js', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/oiscl-trackpro.min.js', array(), $js_ver, true );



        $tracking_payload = OISCL_Tracking::get_frontend_payload( $post_id );

        $tags_fallback = OISCL_Tracking::get_page_auto_tags( $post_id );
        if ( 'custom' === OISCL_Tracking::get_page_tracking_mode( $post_id ) && ! empty( $tracking_payload['instances'] ) ) {
            $tags_fallback = ! empty( $settings['separator_tags'] ) ? implode( ',', $settings['separator_tags'] ) : 'h2,h3,section,article';
        }



        wp_localize_script(

            'oiscl-trackpro-js',

            'oisclData',

            array(

                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),

                'nonce'         => wp_create_nonce( 'oiscl_track_nonce' ),

                'postId'        => $post_id,

                'trackTags'     => $tags_fallback,

                'auditMode'     => current_user_can( 'manage_options' ),

                'siteUrl'       => get_site_url(),

                'tracking'      => $tracking_payload,

                'blockEvent'    => OISCL_Plan::EVENT_BLOCK_VIEW,

                'pageviewEvent' => OISCL_Plan::EVENT_PAGEVIEW,

            )

        );

    }

}

