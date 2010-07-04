<?php

/*
 * Recognising Uniscope devices from their user agents
 *
 */

require_once 'test_helper.php';

class UniscopeTest extends UnitTestCase {

  var $wurfl;


  function test_uniscope_u2ver1() {
    foreach(array(
'UNISCOPE-U2/(2006.01.01)Ver1.0.1/WAP1.2'
    ) as $ua) {
        $this->checkUA($ua, 'uniscope_u2ver1');
      }
  }



  ############################################################

  function checkUA($agent, $expected) {
    $this->wurfl->getDeviceCapabilitiesFromAgent($agent);
    $actual =  $this->wurfl->getDeviceCapability('actual_root_device');
    if ($expected != $actual)
      echo "Expected: $expected, got: $actual\nUA: $agent\n";
    $this->assertEqual($expected, $actual);
  }

  function uniscopeTest() {
    $this->UnitTestCase('uniscope Test');
  }

  function setUp() {
    $this->wurfl = new TeraWurfl();
  }

  function tearDown() {
  }
}

$test = new UniscopeTest();
$test->run(new TextReporter());

?>
