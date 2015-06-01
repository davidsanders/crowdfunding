<?php
/**
 * ATCF Upgrade class.
 * 
 * The responsibility of this class is to manage migrations between versions of ATCF.
 *
 * @package     ATCF
 * @subpackage  ATCF/ATCF Upgrade
 * @copyright   Copyright (c) 2014, Eric Daams  
 * @license     http://opensource.org/licenses/gpl-1.0.0.php GNU Public License
 * @since       1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'ATCF_Upgrade' ) ) : 

/**
 * ATCF_Upgrade
 *
 * @since       1.0.0
 */
class ATCF_Upgrade {

    /**
     * Current database version. 
     * @var     false|string
     * @access  protected
     */
    protected $db_version;

    /**
     * Edge version.
     * @var     string
     * @access  protected
     */
    protected $edge_version;

    /**
     * Array of methods to perform when upgrading to specific versions.      
     * @var     array
     * @access  protected
     */
    protected $upgrade_actions = array(
        '1.8' => array( self, 'upgrade_1_8' ), 
        '1.9' => array( self, 'upgrade_1_9' )
    );

    /**
     * Option key for upgrade log. 
     * @var     string
     * @access  protected
     */
    protected $upgrade_log_key = 'atcf_upgrade_log';
    
    /**
     * Option key for plugin version.
     * @var     string
     * @access  protected
     */
    protected $version_key = 'atcf_version';

    /**
     * Upgrade from the current version stored in the database to the live version. 
     *
     * @param   false|string $db_version    
     * @param   string $edge_version    
     * @return  void
     * @static
     * @access  public
     * @since   1.0.0
     */
    public static function upgrade_from( $db_version, $edge_version ) {        
        if ( self::requires_upgrade( $db_version, $edge_version ) ) {

            new ATCF_Upgrade( $db_version, $edge_version );

        }
    }

    /**
     * Manages the upgrade process. 
     *
     * @param   false|string    $db_version
     * @param   string          $edge_version
     * @access  protected
     * @since   1.0.0
     */
    protected function __construct( $db_version, $edge_version ) {
        $this->db_version = $db_version;
        $this->edge_version = $edge_version;

        /**
         * Perform version upgrades.
         */
        $this->do_upgrades();

        /**
         * Log the upgrade and update the database version.
         */
        $this->save_upgrade_log();
        $this->update_db_version();
    }

    /**
     * Perform version upgrades. 
     *
     * @return  void
     * @access  protected
     * @since   1.0.0
     */
    protected function do_upgrades() {
        if ( empty( $this->upgrade_actions ) || ! is_array( $this->upgrade_actions ) ) {
            return;
        }

        foreach ( $this->upgrade_actions as $version => $method ) {
            if ( self::requires_upgrade( $this->db_version, $version ) ) {
                call_user_func( $method );
            }
        }
    }

    /**
     * Evaluates two version numbers and determines whether an upgrade is 
     * required for version A to get to version B. 
     *
     * @param   false|string $version_a
     * @param   string $version_b
     * @return  bool
     * @static
     * @access  public
     * @since   1.0.0
     */
    public static function requires_upgrade( $version_a, $version_b ) {
        return $version_a === false || version_compare( $version_a, $version_b, '<' );
    }   

    /**
     * Saves a log of the version to version upgrades made. 
     *
     * @return  void
     * @access  protected
     * @since   1.0.0
     */
    protected function save_upgrade_log() {
        $log = get_option( $this->upgrade_log_key );

        if ( false === $log || ! is_array( $log ) ) {
            $log = array();
        }

        $log[] = array(
            'timestamp'     => time(), 
            'from'          => $this->db_version, 
            'to'            => $this->edge_version
        );

        update_option( $this->upgrade_log_key, $log );
    }

    /**
     * Upgrade complete. This saves the new version to the database. 
     *
     * @return  void
     * @access  protected
     * @since   1.0.0
     */
    protected function update_db_version() {
        update_option( $this->version_key, $this->edge_version );
    }

    /**
     * Upgrade to version 1.8. Also called if the db version is not set yet. 
     *
     * @return  void
     * @access  protected
     * @since   1.8
     */
    protected function upgrade_1_8() {
        flush_rewrite_rules();

        ATCF_Install::init(); // Just run the installer again
    }

    /**
     * Upgrade to version 1.9.
     *
     * The key thing that changes in this version is the addition of a new meta 
     * record for campaigns that keeps track of the total preapproved amount.
     * 
     * @return  void
     * @access  protected
     * @since   1.9
     */
    protected function upgrade_1_9() {
        global $wpdb;

        /* Find the IDs of all pre-approved payments */
        $sql = "SELECT DISTINCT( p.ID ) AS payment_id
        FROM $wpdb->posts p
        WHERE p.post_type = 'edd_payment'
        AND p.post_status = 'preapproval';";

        $payments = $wpdb->get_col( $sql );

        /* No preapproved payments, so nothing further to be done. */
        if ( empty( $payments ) ) {
            return;
        }

        /* Save the preapproved amount for each download. */
        $preapproval_earnings = $this->get_download_earnings( $payments );

        foreach ( $preapproval_earnings as $download_id => $preapproved ) {
            update_post_meta( $download_id, '_edd_download_preapproved_earnings', $preapproved );
        }

        /* 
         * There is a good chance that the earnings total reported by EDD is now incorrect, 
         * since it probably includes preapproved earnings as well as completed. Let's ensure those
         * are all correct. 
         */

        /* Find the IDs of all *completed* payments for the downloads updated above. */
        $download_ids = implode( ', ', array_keys( $preapproval_earnings ) );

        $sql = "SELECT DISTINCT( p.ID ) AS payment_id
        FROM $wpdb->posts p
        LEFT JOIN $wpdb->postmeta m
        ON (m.meta_value = p.ID AND m.meta_key = '_edd_log_payment_id')
        LEFT JOIN $wpdb->posts p1
        ON (p1.ID = m.post_id)
        WHERE p.post_type = 'edd_payment'
        AND p.post_status = 'publish'
        AND p1.post_parent IN ( $download_ids );";

        $payments = $wpdb->get_col( $sql );

        /* This should never really happen... */
        if ( empty( $payments ) ) {
            return;
        }

        /* Save the preapproved amount for each download. */
        foreach ( $this->get_download_earnings( $payments ) as $download_id => $earned ) {
            update_post_meta( $download_id, '_edd_download_earnings', $earned );
        }   
    }  

    /**
     * Returns an array with the download earnings for all payments passed.
     *
     * @param   int[]
     * @return  float[]
     * @access  protected
     * @since   1.9
     */
    protected function get_download_earnings( $payments ) {
        $earnings = array();

        foreach ( $payments as $payment_id ) {
            
            foreach ( edd_get_payment_meta_cart_details( $payment_id ) as $download ) {

                $download_id = $download[ 'id' ];

                if ( ! isset( $earnings[ $download_id ] ) ) {
                    $earnings[ $download_id ] = 0;
                }

                $earnings[ $download_id ] += $download[ 'price' ];
            }
        }

        return $earnings;
    }  
}

endif; // End class_exists check