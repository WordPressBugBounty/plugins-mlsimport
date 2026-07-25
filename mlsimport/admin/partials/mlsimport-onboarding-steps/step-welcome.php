<?php
/**
 * Template for the Welcome step of the MLSImport onboarding wizard
 *
 * First step of the wizard. Renders intro copy, a "Start the Wizard" button,
 * and a System Requirements Check table (PHP version, memory limit, max
 * execution time, and active-theme compatibility). This is a display template
 * with no AJAX action of its own — it computes requirement pass/fail flags in
 * PHP, then outputs the HTML. Included by mlsimport_render_onboarding_step().
 *
 * NOTE: comments here live only inside PHP regions; the inline-HTML below is
 * left uncommented because HTML comments are part of the rendered output.
 *
 * @link       https://mlsimport.com/
 * @since      6.1.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/admin/partials/mlsimport-onboarding-steps
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Read the running environment values that feed the requirements table.
// Get server info
$php_version = phpversion();
$wp_version = get_bloginfo('version');
$memory_limit = WP_MEMORY_LIMIT;
$max_execution_time = ini_get('max_execution_time');
$upload_max_filesize = ini_get('upload_max_filesize');
$post_max_size = ini_get('post_max_size');

// Build the requirements list. Each entry holds a display name, detected value,
// required minimum, a boolean pass/fail status, and help text for failures.
// Check requirements
$requirements = array(
    // PHP version must be at least 7.2.
    'php_version' => array(
        'name' => __('PHP Version', 'mlsimport'),
        'value' => $php_version,
        'required' => '7.2',
        'status' => version_compare($php_version, '7.2', '>='),
        'help' => __('MLSImport requires PHP 7.2 or higher.', 'mlsimport'),
    ),
    // Memory passes when the numeric part is >= 256 OR memory is unlimited (-1).
    'memory_limit' => array(
        'name' => __('Memory Limit', 'mlsimport'),
        'value' => $memory_limit,
        'required' => '64M',
        'status' => intval($memory_limit) >= 256 || $memory_limit === '-1',
        'help' => __('We recommend setting memory to at least 64MB if not more.', 'mlsimport'),
    ),
    // Execution time passes when >= 120s OR 0 (no limit).
    'max_execution_time' => array(
        'name' => __('Max Execution Time', 'mlsimport'),
        'value' => $max_execution_time . 's',
        'required' => '120s',
        'status' => $max_execution_time >= 120 || $max_execution_time == 0,
        'help' => __('We recommend setting max execution time to at least 120 seconds.', 'mlsimport'),
    ),
);

// Read the active theme name so it can be matched against the supported list.
// Check if theme is supported
$current_theme = wp_get_theme();
$theme_name = $current_theme->get('Name');
// Map of supported theme slugs to their human-facing labels.
$supported_themes = array(
    'WpResidence' => 'WP Residence',
    'houzez' => 'Houzez',
    'RealHomes' => 'Real Homes',
    'Wpestate' => 'WP Estate',
);

// Tracks whether the active theme matched a supported one, and its label.
$theme_detected = false;
$detected_theme_name = '';

// Match on exact (case-insensitive) name OR the slug appearing as a substring.
foreach ($supported_themes as $theme_key => $theme_label) {
    // First clause: exact case-insensitive match. Second: slug is a substring.
    if (strtolower($theme_name) === strtolower($theme_key) || strpos(strtolower($theme_name), strtolower($theme_key)) !== false) {
        $theme_detected = true;
        $detected_theme_name = $theme_label;
        break;
    }
}

// Append the theme-compatibility entry to the requirements table.
// Theme requirement
$requirements['theme'] = array(
    'name' => __('Theme Compatibility', 'mlsimport'),
    'value' => $theme_name,
    'required' => implode(', ', $supported_themes),
    'status' => $theme_detected,
    'help' => $theme_detected 
        ? sprintf(__('Great! We detected your theme as %s, which is supported by MLSImport.', 'mlsimport'), $detected_theme_name)
        : __('MLSImport works best with WP Residence, Houzez, Real Homes, or WP Estate themes.', 'mlsimport'),
);

// Reduce all requirement statuses to a single flag; any failure flips it false.
// Calculate overall status
$all_requirements_met = true;
foreach ($requirements as $req) {
    // A single failing requirement means the system does not fully pass.
    if (!$req['status']) {
        $all_requirements_met = false;
        break;
    }
}
?>

<div class="mlsimport-welcome-content  ">
    <p class="mlsimport-welcome-intro">
        <?php _e('Welcome to the MLSImport Setup Wizard! This will guide you through connecting to your MLS provider, configuring how property data is imported, and setting up your first import.', 'mlsimport'); ?>
    </p>
    
    <div class="mlsimport-welcome-note">
        <p>
            <?php _e('Before you begin, make sure you have:', 'mlsimport'); ?>
        </p>
        <ul>
            <li><?php _e('Your MLSImport.com account credentials', 'mlsimport'); ?></li>
            <li><?php _e('Your MLS provider information and access credentials', 'mlsimport'); ?></li>
            <li><?php _e('Approximately 10 minutes to complete the setup', 'mlsimport'); ?></li>
        </ul>
    </div>
    
    
    <button type="submit" class="button button-primary mlsimport-wizard-next mlsimport_button">
        <?php _e('Start the Wizard', 'mlsimport'); ?>
    </button>



    <div class="mlsimport-requirements-check">
        <h3><?php _e('System Requirements Check', 'mlsimport'); ?></h3>
        
        <table class="mlsimport-requirements-table widefat">
            <thead>
                <tr>
                    <th><?php _e('Requirement', 'mlsimport'); ?></th>
                    <th><?php _e('Your Value', 'mlsimport'); ?></th>
                    <th><?php _e('Required', 'mlsimport'); ?></th>
                    <th><?php _e('Status', 'mlsimport'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php /* One row per requirement: name, value, required, status icon. */ foreach ($requirements as $req_key => $req) : ?>
                    <tr>
                        <td><?php echo esc_html($req['name']); ?></td>
                        <td><?php echo esc_html($req['value']); ?></td>
                        <td><?php echo esc_html($req['required']); ?></td>
                        <td>
                            <?php if ($req['status']) : /* passing: green check icon */ ?>
                                <span class="mlsimport-requirement-status success dashicons dashicons-yes"></span>
                            <?php else : /* failing: warning icon */ ?>
                                <span class="mlsimport-requirement-status error dashicons dashicons-warning"></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!$req['status']) : /* extra help row only when this requirement failed */ ?>
                        <tr class="mlsimport-requirement-help">
                            <td colspan="4"><?php echo esc_html($req['help']); ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!$all_requirements_met) : /* one or more checks failed: caution notice */ ?>
            <div class="mlsimport-warning-message">
                <p>
                    <?php _e('Some system requirements are not met. You can still proceed, but you might encounter issues during import.', 'mlsimport'); ?>
                </p>
            </div>
        <?php else : ?>
            <div class="mlsimport-success-message">
                <p>
                    <?php _e('Great! Your system meets all the requirements for MLSImport.', 'mlsimport'); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
    
    
</div>

<style>

</style>