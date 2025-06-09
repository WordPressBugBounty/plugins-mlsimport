<?php
// Basic stubs for WordPress functions used in tests

if ( ! function_exists( 'delete_transient' ) ) {
    $GLOBALS['deleted_transients'] = [];
    function delete_transient( $name ) {
        $GLOBALS['deleted_transients'][] = $name;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    $GLOBALS['deleted_options'] = [];
    function delete_option( $name ) {
        $GLOBALS['deleted_options'][] = $name;
        return true;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $text ) {
        return trim( strip_tags( $text ) );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $text ) {
        return stripslashes( $text );
    }
}

if ( ! function_exists( 'add_action' ) ) {
    $GLOBALS['actions_called'] = [];
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['actions_called'][] = func_get_args();
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    $GLOBALS['filters_called'] = [];
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['filters_called'][] = func_get_args();
    }
}

require __DIR__ . '/../includes/class-mlsimport-loader.php';
require __DIR__ . '/../includes/class-mlsimport-activator.php';
require __DIR__ . '/../includes/class-mlsimport-deactivator.php';
require __DIR__ . '/../includes/help_functions.php';
