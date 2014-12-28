<?php

include("inc.php");

$testCases = scandir("mega_test_cases/");

//foreach($testCases as $key => $testCase) {
//
//	if($testCase == "." || $testCase == "..") {
//		continue;
//	}
//
//	// echo $testCase."\n";
//	$scanner = new CodeScanner("mega_test_cases/".$testCase);
//
//	echo " $key            \r";
//
//	if(!$scanner->barcodeValue) {
//		continue;
//	}
//
//	echo $key."\r";
//	echo $key." ".$testCase.": ".implode("",$scanner->barcodeValue)."\n";
//
//}

 $scanner = new CodeScanner("stingmon.jpg");
 echo "stingmon.jpg: ".implode("",$scanner->barcodeValue)."\n";
// $scanner = new CodeScanner("barcodetest.png");
// $scanner = new CodeScanner("bo_074t.jpg");
