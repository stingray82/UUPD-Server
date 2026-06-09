<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UUPD_Core
 *
 * Simple container that holds "modules" (classes) and lets them register hooks.
 * Each module just needs to implement a public register_hooks() method.
 */
class UUPD_Core {

    /**
     * @var array<int,object> List of registered modules.
     */
    protected $modules = [];

    /**
     * Register a module with the core container.
     *
     * @param object $module Any object with a register_hooks() method.
     */
    public function add_module( $module ) {
        if ( is_object( $module ) && method_exists( $module, 'register_hooks' ) ) {
            $this->modules[] = $module;
        }
    }

    /**
     * Called from plugins_loaded in the main plugin file.
     * Loops through all registered modules and lets them attach their hooks.
     */
    public function init() {
        foreach ( $this->modules as $module ) {
            $module->register_hooks();
        }
    }
}
