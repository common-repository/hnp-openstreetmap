<?php
/*
  Plugin Name: HNP OpenStreetMap Shortcode
  Description: Creates a frontend OpenStreetMap map with a pin using a shortcode
  Version: 1.1.1
  Author: Christopher Rohde 
  Author URI: https://homepage-nach-preis.de/
  License: GPLv3
  License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// Security check to prevent direct access to the plugin file
defined('ABSPATH') or die('No script kiddies please!');

// Function to enqueue Leaflet library
function hnp_openmaps_enqueue_leaflet_scripts() {
    // Define a version number for your CSS file
    $version = '1.9.4';

    // Check if Leaflet CSS is not already enqueued
    if (!wp_style_is('leaflet-css')) {
        // Enqueue Leaflet CSS with the defined version
        wp_enqueue_style('leaflet-css', plugin_dir_url(__FILE__) . 'leaflet/leaflet.css', array(), $version);
    }

    // Check if Leaflet JavaScript is not already enqueued
    if (!wp_script_is('leaflet-js')) {
        // Enqueue Leaflet JavaScript with the defined version
        wp_enqueue_script('leaflet-js', plugin_dir_url(__FILE__) . 'leaflet/leaflet.js', array(), $version, true);
    }
}
add_action('wp_enqueue_scripts', 'hnp_openmaps_enqueue_leaflet_scripts');


// Function to display OpenStreetMap maps with a pin
function hnp_openmaps_display_map_with_pin() {
    // Load options and sanitize address
    $raw_address = get_option('hnp_openmaps_map_address', 'Hardenbergpl. 8, 10787 Berlin, Germany');
    $clean_address = sanitize_text_field($raw_address);

    // Load options and sanitize marker name
    $marker_name = get_option('hnp_openmaps_map_name', 'Berlin Zoological Garden'); 

    // Load zoom level, map style, height, and width of the map
    $zoom = get_option('hnp_openmaps_map_zoom', 12);
    $style = get_option('hnp_openmaps_map_style', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
    $height = get_option('hnp_openmaps_map_height', '400px');
    $width = get_option('hnp_openmaps_map_width', '100%');

    // JavaScript variable for map initialization and adding markers
    $map = "
        <script>
            document.addEventListener('DOMContentLoaded', function() {
    ";

    // Split address into individual parts and encode
    $address_parts = explode(',', $clean_address);
    $encoded_address_parts = array_map('urlencode', $address_parts);
    $encoded_address = implode(',', $encoded_address_parts);

    // Construct Nominatim URL
    $nominatim_url = "https://nominatim.openstreetmap.org/search?format=json&q={$encoded_address}";

    // Get geocoding data from Nominatim
    $response = wp_remote_get($nominatim_url);

    // Check if the request was successful
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        // Check if geocoding results were obtained
        if (!empty($data)) {
            $latitude = $data[0]['lat'];
            $longitude = $data[0]['lon'];

            // Initialize map with center at marker coordinates
            $map .= "
                var hnp_openmaps_map = L.map('hnp_openmaps_map').setView([$latitude, $longitude], $zoom);
            ";

            // Add tiles to the map
            $map .= "
                L.tileLayer('$style', {
                    attribution: '&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors'
                }).addTo(hnp_openmaps_map);
            ";

            // Add marker to the map
            $map .= "
                L.marker([$latitude, $longitude]).addTo(hnp_openmaps_map).bindPopup('" . esc_js($clean_address) . "').bindTooltip('" . esc_js($marker_name) . "');
                console.log('Geocoding successful for address:', " . wp_json_encode($clean_address) . ");
            ";
        } else {
            // Error retrieving geocoding data
            error_log("Error retrieving geocoding data for address: $clean_address");
            $map .= "
                console.log('Error retrieving geocoding data for address:', " . wp_json_encode($clean_address) . ");
            ";
        }
    } else {
        // Error retrieving geocoding data
        error_log("Error retrieving geocoding data for address: $clean_address");
        $map .= "
            console.log('Error retrieving geocoding data for address:', " . wp_json_encode($clean_address) . ");
        ";
    }

    // Add JavaScript end
    $map .= "
            });
        </script>
    ";

    // Return map div and JavaScript
    return '<div id="hnp_openmaps_map" style="height: ' . esc_attr($height) . '; width: ' . esc_attr($width) . ';"></div>' . $map;
}

// Register shortcode
add_shortcode('hnp_openmaps_display_map_with_pin', 'hnp_openmaps_display_map_with_pin');

// Function to add plugin options to the main menu
function hnp_openmaps_add_plugin_options_page() {
    // Check permission
    if (current_user_can('manage_options')) {
        add_menu_page(
            'HNP OpenStreetMap Settings',
            'HNP OpenStreetMap',
            'manage_options',
            'hnp-openmaps-osm-settings',
            'hnp_openmaps_render_plugin_options_page',
            plugin_dir_url(__FILE__) . 'img/hnp-favi.png' 
        );
    }
}
add_action('admin_menu', 'hnp_openmaps_add_plugin_options_page');

// Add settings link to plugin on the Plugins page
function hnp_openmaps_add_settings_link($links) {
    $settings_link = '<a href="admin.php?page=hnp-openmaps-osm-settings">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link); // Add the settings link at the beginning of the array
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'hnp_openmaps_add_settings_link');


// Function to render plugin options page
function hnp_openmaps_render_plugin_options_page() {
    ?>
    <div class="wrap">
        <h1>HNP OpenStreetMap Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('hnp_openmaps_osm_settings_group'); ?>
            <?php do_settings_sections('hnp-openmaps-osm-settings'); ?>
            <?php 
                // Add nonce
                wp_nonce_field('hnp_openmaps_osm_settings_nonce', 'hnp_openmaps_osm_settings_nonce'); 
            ?>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Function to register plugin options
function hnp_openmaps_register_plugin_options() {
    // Adress
    add_settings_section(
        'hnp_openmaps_osm_address_section',
        'Address',
        'hnp_openmaps_osm_address_section_callback',
        'hnp-openmaps-osm-settings'
    );
    add_settings_field(
        'hnp_openmaps_map_address',
        'Address',
        'hnp_openmaps_map_address_callback',
        'hnp-openmaps-osm-settings',
        'hnp_openmaps_osm_address_section'
    );
    register_setting('hnp_openmaps_osm_settings_group', 'hnp_openmaps_map_address');

    // Name
    add_settings_section(
        'hnp_openmaps_osm_name_section',
        'Name',
        'hnp_openmaps_osm_name_section_callback',
        'hnp-openmaps-osm-settings'
    );
    add_settings_field(
        'hnp_openmaps_map_name',
        'Name',
        'hnp_openmaps_map_name_callback',
        'hnp-openmaps-osm-settings',
        'hnp_openmaps_osm_name_section'
    );
    register_setting('hnp_openmaps_osm_settings_group', 'hnp_openmaps_map_name');

    // Zoom
    add_settings_section(
        'hnp_openmaps_osm_zoom_section',
        'Map Zoom',
        'hnp_openmaps_osm_zoom_section_callback',
        'hnp-openmaps-osm-settings'
    );
    add_settings_field(
        'hnp_openmaps_map_zoom',
        'Map Zoom',
        'hnp_openmaps_map_zoom_callback',
        'hnp-openmaps-osm-settings',
        'hnp_openmaps_osm_zoom_section'
    );
    register_setting('hnp_openmaps_osm_settings_group', 'hnp_openmaps_map_zoom');

    // 
    add_settings_section(
        'hnp_openmaps_osm_style_section',
        'Map Style',
        'hnp_openmaps_osm_style_section_callback',
        'hnp-openmaps-osm-settings'
    );
    add_settings_field(
        'hnp_openmaps_map_style',
        'Map Style',
        'hnp_openmaps_map_style_callback',
        'hnp-openmaps-osm-settings',
        'hnp_openmaps_osm_style_section'
    );
    register_setting('hnp_openmaps_osm_settings_group', 'hnp_openmaps_map_style');

    add_settings_section(
        'hnp_openmaps_osm_height_section',
        'Map Height',
        'hnp_openmaps_osm_height_section_callback',
        'hnp-openmaps-osm-settings'
    );
    add_settings_field(
        'hnp_openmaps_map_height',
        'Map Height',
        'hnp_openmaps_map_height_callback',
        'hnp-openmaps-osm-settings',
        'hnp_openmaps_osm_height_section'
    );
    register_setting('hnp_openmaps_osm_settings_group', 'hnp_openmaps_map_height');

    add_settings_section(
        'hnp_openmaps_osm_width_section',
        'Map Width',
        'hnp_openmaps_osm_width_section_callback',
        'hnp-openmaps-osm-settings'
    );
    add_settings_field(
        'hnp_openmaps_map_width',
        'Map Width',
        'hnp_openmaps_map_width_callback',
        'hnp-openmaps-osm-settings',
        'hnp_openmaps_osm_width_section'
    );
    register_setting('hnp_openmaps_osm_settings_group', 'hnp_openmaps_map_width');

    add_settings_section(
        'hnp_openmaps_shortcode_section',
        'Shortcode',
        'hnp_openmaps_shortcode_section_callback',
        'hnp-openmaps-osm-settings'
    );
}

// Callback functions for each option
function hnp_openmaps_osm_address_section_callback() {
    echo '<p>Enter the address to be displayed on the map. <br>Format: Streetname + Housenumber, City Name + ZIP Code, Country <br>(Separate each part with a comma)</p>';
}
function hnp_openmaps_map_address_callback() {
    $address = get_option('hnp_openmaps_map_address', 'Hardenbergpl. 8, 10787 Berlin, Germany');
    echo '<input type="text" name="hnp_openmaps_map_address" value="' . esc_attr($address) . '" />';
}

function hnp_openmaps_osm_name_section_callback() {
    echo '<p>Enter the name for the marker to be displayed on the map.</p>';
}
function hnp_openmaps_map_name_callback() {
    $name = get_option('hnp_openmaps_map_name', 'Berlin Zoological Garden');
    echo '<input type="text" name="hnp_openmaps_map_name" value="' . esc_attr($name) . '" />';
}

function hnp_openmaps_osm_zoom_section_callback() {
    echo '<p>Set the zoom level of the map.</p>';
}
function hnp_openmaps_map_zoom_callback() {
    $zoom = get_option('hnp_openmaps_map_zoom', 12);
    echo '<input type="number" name="hnp_openmaps_map_zoom" value="' . esc_attr($zoom) . '" />';
}
function hnp_openmaps_osm_style_section_callback() {
    echo '<p>Select the map style.</p>';
}
function hnp_openmaps_map_style_callback() {
    $style = get_option('hnp_openmaps_map_style', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
    $styles = array(
        'Standard (OpenStreetMap)' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        'Hot' => 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
        'Cycle' => 'https://tile.thunderforest.com/cycle/{z}/{x}/{y}.png',
        'Transport' => 'https://{s}.tile.thunderforest.com/transport/{z}/{x}/{y}.png'
        // More styles can be added here
    );
    echo '<select name="hnp_openmaps_map_style">';
    foreach ($styles as $label => $url) {
        echo '<option value="' . esc_attr($url) . '" ' . selected($style, $url, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
}

function hnp_openmaps_osm_height_section_callback() {
    echo '<p>Set the height of the map.</p>';
}
function hnp_openmaps_map_height_callback() {
    $height = get_option('hnp_openmaps_map_height', '400px');
    echo '<input type="text" name="hnp_openmaps_map_height" value="' . esc_attr($height) . '" />';
}
function hnp_openmaps_osm_width_section_callback() {
    echo '<p>Set the width of the map.</p>';
}
function hnp_openmaps_map_width_callback() {
    $width = get_option('hnp_openmaps_map_width', '100%');
    echo '<input type="text" name="hnp_openmaps_map_width" value="' . esc_attr($width) . '" />';
}
function hnp_openmaps_shortcode_section_callback() {
    echo '<p>Shortcode: &#x5B;hnp_openmaps_display_map_with_pin]</p>';
}

// Register plugin options and security measures
add_action('admin_init', 'hnp_openmaps_register_plugin_options');

// Security measures: Nonce verification for options update
function hnp_openmaps_validate_settings($input) {
    return $input; // Simply return the input, no further validation here
}

// Function to register security and update plugin options
function hnp_openmaps_register_security_options() {
    if (isset($_POST['option_page']) && $_POST['option_page'] == 'hnp_openmaps_osm_settings_group') {
        // Check and sanitize the nonce
        if (!isset($_POST['hnp_openmaps_osm_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hnp_openmaps_osm_settings_nonce'])), 'hnp_openmaps_osm_settings_nonce')) {
            wp_die('Unauthorized request.'); // Handle unauthorized request
        }

        // Prepare and sanitize the user inputs
        $safe_map_address = sanitize_text_field(wp_unslash($_POST['hnp_openmaps_map_address']));
        $safe_map_name = sanitize_text_field(wp_unslash($_POST['hnp_openmaps_map_name']));
		$safe_map_zoom = intval($_POST['hnp_openmaps_map_zoom']);
		if ($safe_map_zoom < 1 || $safe_map_zoom > 18) {
			$safe_map_zoom = 12;  // Default zoom level
		}
        $safe_map_style = esc_url_raw(wp_unslash($_POST['hnp_openmaps_map_style']));
        $safe_map_height = sanitize_text_field(wp_unslash($_POST['hnp_openmaps_map_height']));
        $safe_map_width = sanitize_text_field(wp_unslash($_POST['hnp_openmaps_map_width']));

        // Update options after sanitation
        update_option('hnp_openmaps_map_address', $safe_map_address);
        update_option('hnp_openmaps_map_name', $safe_map_name);
        update_option('hnp_openmaps_map_zoom', $safe_map_zoom);
        update_option('hnp_openmaps_map_style', $safe_map_style);
        update_option('hnp_openmaps_map_height', $safe_map_height);
        update_option('hnp_openmaps_map_width', $safe_map_width);
    }
}
add_action('admin_init', 'hnp_openmaps_register_security_options');


