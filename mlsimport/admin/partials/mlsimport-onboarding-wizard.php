<?php
/**
 * Template for the MLSImport onboarding wizard
 *
 * This file provides the main structure for the onboarding wizard interface.
 * It displays the header, step navigation, and content for the current step.
 *
 * Outer shell rendered for the mlsimport-onboarding admin page: logo/title bar,
 * a progress bar with numbered step markers, the current step's body (delegated
 * to mlsimport_render_onboarding_step()), Back/Continue (or Complete Setup)
 * actions, and a footer with support/restart links. Step list and current step
 * come from mlsimport_get_steps() / mlsimport_get_current_step(). The form posts
 * back to itself with the onboarding nonce; step content is included inside it.
 *
 * NOTE: comments here live only inside PHP regions; the inline HTML below is
 * emitted verbatim and is intentionally left uncommented.
 *
 * @link       https://mlsimport.com/
 * @since      6.1.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/admin/partials
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Ordered map of wizard steps (id => step data).
// Get all steps
$steps = mlsimport_get_steps();

// Bail out early if there are no steps to render.
// Check if steps exist
if (empty($steps) || !is_array($steps)) {
    return;
}

// Resolve which step is currently being displayed.
// Get current step
$current_step = mlsimport_get_current_step();

// Derive index/total and the progress bar fill percentage from the step keys.
// Get all step keys for progress calculation
$step_keys = array_keys($steps);
$current_step_index = array_search($current_step, $step_keys);
$total_steps = count($steps);
$progress_percentage = ($current_step_index / ($total_steps - 1)) * 100;

// Data (title/description) for the current step, with a safe fallback.
// Get current step data
$current_step_data = isset($steps[$current_step]) ? $steps[$current_step] : array(
    'title' => __('Unknown Step', 'mlsimport'),
    'description' => '',
);

// Back button shows only when not on the first step.
// Check if we can go back
$can_go_back = $current_step_index > 0;

// True on the final step (switches the primary button to "Complete Setup").
// Check if we're on the last step
$is_last_step = $current_step_index === count($step_keys) - 1;

// Compute the next/previous step ids (empty at the ends).
// Get next/previous step URLs
$next_step = $current_step_index < count($step_keys) - 1 ? $step_keys[$current_step_index + 1] : '';
$prev_step = $current_step_index > 0 ? $step_keys[$current_step_index - 1] : '';

// Build the admin URLs used by the Back/next navigation.
$next_url = admin_url('admin.php?page=mlsimport-onboarding&step=' . $next_step);
$prev_url = admin_url('admin.php?page=mlsimport-onboarding&step=' . $prev_step);

?>
<div class="mlsimport-wizard-wrap">
    <div class="mlsimport-wizard-header-bar">
     

        <?php  
        echo '<img src="' . MLSIMPORT_PLUGIN_URL . '/img/mlsimport-logo.webp" alt="My Plugin Logo" style="max-width:200px;" />';
        ?>
        
        <h1 class="mlsimport-wizard-title">
            <?php _e('MLSImport Setup Wizard', 'mlsimport'); ?>
        </h1>

        <a href="<?php echo esc_url(admin_url('admin.php?page=mlsimport_plugin_options')); ?>" class="mlsimport-wizard-close">
            <span class="dashicons dashicons-no-alt"></span>
        </a>
    </div>
    
    <div class="mlsimport-wizard-header">
        <div class="mlsimport-wizard-progress">
            <div class="mlsimport-wizard-progress-bar">
                <div class="mlsimport-wizard-progress-bar-inner" style="width: <?php echo esc_attr($progress_percentage); ?>%"></div>
            </div>
            <div class="mlsimport-wizard-steps">
                <?php /* render one marker per step in the progress bar */ foreach ($steps as $step_id => $step) : ?>
                    <?php
                    // Position of this step, used to flag it completed/active.
                    $step_index = array_search($step_id, $step_keys);
                    $step_class = 'mlsimport-wizard-step';
                    // Steps before the current one are marked completed...
                    if ($step_index < $current_step_index) {
                        $step_class .= ' completed';
                    // ...and the current step is marked active.
                    } elseif ($step_index === $current_step_index) {
                        $step_class .= ' active';
                    }
                    // 1-based number shown in the step marker.
                    $step_number = $step_index + 1;
                    ?>
                    <div class="<?php echo esc_attr($step_class); ?>">
                        <div class="mlsimport-wizard-step-number"><?php echo esc_html($step_number); ?></div>
                        <div class="mlsimport-wizard-step-title"><?php echo esc_html($step['title']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    
    
    <div class="mlsimport-wizard-content mlsimport_2025_card mlsimport-wizard-content-30margin 
        <?php
        echo ' mlsimport-wizard-content-'.$current_step;
        ?>
    
    ">
        <div class="mlsimport-wizard-content-header">
            <h2><?php echo esc_html($current_step_data['title']); ?></h2>
            <p class="mlsimport-wizard-description"><?php echo esc_html($current_step_data['description']); ?></p>
        </div>
        
        <div class="mlsimport-wizard-content-body">
            <form id="mlsimport-wizard-form" method="post" action="">
                <?php 
                // Output nonce field
                // CSRF nonce for the onboarding form submission.
                wp_nonce_field('mlsimport_onboarding', 'mlsimport_onboarding_nonce');
                
                // Include the current step template
                // Render the body of the current step (one of the step-*.php partials).
                mlsimport_render_onboarding_step($current_step);
                ?>
                
                <div class="mlsimport-wizard-actions">
                    <?php if ($can_go_back) : /* Back link hidden on the first step */ ?>
                        <a href="<?php echo esc_url($prev_url); ?>" class="button mlsimport_button  secondary">
                            <?php _e('Back', 'mlsimport'); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($is_last_step) : /* final step: primary button completes setup */ ?>
                        <button type="submit" class="button button-primary mlsimport-wizard-next mlsimport_button mlsimport-wizard-submit">
                            <?php _e('Complete Setup', 'mlsimport'); ?>
                        </button>
                    <?php else : /* otherwise: primary button advances to the next step */ ?>
                        <button type="submit" class="button button-primary mlsimport-wizard-next mlsimport_button ">
                            <?php _e('Continue', 'mlsimport'); ?>
                        </button>
                    <?php endif; ?>
                    
                    <input type="hidden" name="mlsimport_onboarding_submit" value="1">
                    <input type="hidden" name="mlsimport_current_step" value="<?php echo esc_attr($current_step); ?>">
                </div>
            </form>
        </div>
    </div>
    
    <div class="mlsimport-wizard-footer">
        <div class="mlsimport-wizard-help">
            <p>
                <?php _e('Need help?', 'mlsimport'); ?> 
                <a href="https://mlsimport.com/support" target="_blank"><?php _e('Contact Support', 'mlsimport'); ?></a>
            </p>
        </div>
        <div class="mlsimport-wizard-restart">
            <a href="<?php echo esc_url(admin_url('admin.php?page=mlsimport-onboarding&restart_wizard=1')); ?>" class=" button mlsimport_button secondary">
                <?php _e('Restart Setup', 'mlsimport'); ?>
            </a>
        </div>
    </div>
</div>