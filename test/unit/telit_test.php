<?php

/*
 * Recognising Telit devices from their user agents
 *
 */

require_once 'test_helper.php';

class TelitTest extends UnitTestCase {

  var $wurfl;


  function test_telit_gu1100_ver1() {
    foreach(array(
'GU1100'
    ) as $ua) {
        $this->checkUA($ua, 'telit_gu1100_ver1');
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

  function telitTest() {
    $this->UnitTestCase('telit Test');
  }

  function setUp() {
    $this->wurfl = new TeraWurfl();
  }

  function tearDown() {
  }
}

$test = new TelitTest();
$test->run(new TextReporter());

?>
