<?php
use PHPUnit\Framework\TestCase;

class LoaderTest extends TestCase {
    private function getPrivate( $object, $property ) {
        $ref = new ReflectionClass( $object );
        $prop = $ref->getProperty( $property );
        $prop->setAccessible( true );
        return $prop->getValue( $object );
    }

    public function test_add_action_stores_hook() {
        $loader = new Mlsimport_Loader();
        $loader->add_action( 'init', new stdClass(), 'method' );
        $actions = $this->getPrivate( $loader, 'actions' );
        $this->assertCount( 1, $actions );
        $this->assertEquals( 'init', $actions[0]['hook'] );
    }

    public function test_run_executes_registered_hooks() {
        global $actions_called, $filters_called;
        $actions_called = [];
        $filters_called = [];
        $loader = new Mlsimport_Loader();
        $component = new stdClass();
        $loader->add_action( 'action_hook', $component, 'callback' );
        $loader->add_filter( 'filter_hook', $component, 'callback2' );
        $loader->run();
        $this->assertEquals( 'action_hook', $actions_called[0][0] );
        $this->assertEquals( 'filter_hook', $filters_called[0][0] );
    }
}
