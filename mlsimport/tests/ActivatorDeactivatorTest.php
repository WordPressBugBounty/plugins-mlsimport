<?php
use PHPUnit\Framework\TestCase;

class ActivatorDeactivatorTest extends TestCase {
    public function test_activate_deletes_transient() {
        global $deleted_transients;
        $deleted_transients = [];
        Mlsimport_Activator::activate();
        $this->assertContains( 'mlsimport_plugin_data_schema', $deleted_transients );
    }

    public function test_deactivate_deletes_options() {
        global $deleted_transients, $deleted_options;
        $deleted_transients = [];
        $deleted_options = [];
        Mlsimport_Deactivator::deactivate();
        $this->assertContains( 'mlsimport_plugin_data_schema', $deleted_transients );
        $this->assertContains( 'mlsimport_admin_options', $deleted_options );
    }
}
