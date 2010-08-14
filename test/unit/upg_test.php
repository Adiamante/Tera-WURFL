<?php

/*
 * Recognising Upg devices from their user agents
 *
 */

require_once 'test_helper.php';

class UpgTest extends TeraWurflTestCase {

  var $wurfl;


  function test_upg1_ver1_subblazer30() {
    foreach(array(
'Mozilla/4.0 (compatible; MSIE 6.0; Windows 95; PalmSource; Blazer 3.0) 16;160x160'
    ) as $ua) {
        $this->checkUA($ua, 'upg1_ver1_subblazer30');
      }
  }



  function test_upg1_ver1_subblazer40() {
    foreach(array(
'Mozilla/4.0 (compatible; MSIE 6.0; Windows 98; PalmSource/hspr-H102; Blazer/4.0) 16;320x320',
'Mozilla/4.0 (compatible; MSIE 6.0; Windows 98; PalmSource/hspr-H102; Blazer/4.2) 16;320x320'
    ) as $ua) {
        $this->checkUA($ua, 'upg1_ver1_subblazer40');
      }
  }



  function test_upg1_ver_1_subblazer43do50() {
    foreach(array(
'Mozilla/4.0 (compatible; MSIE 6.0; Windows 98; PalmSource/Palm-D050; Blazer/4.3) 16;320x320',
'Mozilla/4.0 (compatible; MSIE 6.0; Windows 98; PalmSource/Palm-D050; Blazer/4.3) 16;320x448',
'Mozilla/4.0 (compatible; MSIE 6.0; Windows 98; PalmSource/Palm-D050; Blazer/4.3) 16;448x320'
    ) as $ua) {
        $this->checkUA($ua, 'upg1_ver_1_subblazer43do50');
      }
  }



  ############################################################


  function upgTest() {
    $this->UnitTestCase('upg Test');
  }


}

$test = new UpgTest();
$test->run(new TextReporter());

?>
