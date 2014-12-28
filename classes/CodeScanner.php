<?php

class CodeScanner {
    
	public $fileName;
	private $file, $width, $height;
	private $imageData = array();
	private $yImageArray = array();
	private $edges = array();
	private $caughtPoints = array();
	private $caughtGrids = array();
	private $barcodeImages = array();
	const THRESHOLD = 140;
	private $div = 10;
	private $magnitude = 200;
	public $barcodeValue = null;

	public function __construct($fileName) {
		
		$this->fileName = $fileName;

		switch(mime_content_type($fileName)) {
			case "image/png":
				$this->file = imagecreatefrompng($fileName);
				break;
			case "image/jpg":
			case "image/jpeg":
				$this->file = imagecreatefromjpeg($fileName);
				break;
			case "image/gif":
				$this->file = imagecreatefromgif($fileName);
				break;
			default:
				throw new Exception("Not sure what to do: ".mime_content_type($fileName)."!");
		}
		
		$this->getScale();

		$this->processGrayScale();
		$this->calculate();
	}

	private function calculate() {
		$this->calculateYGradiant();
		$this->calculateVerticalLines();
		$this->calculateGridPositions();
		$this->processCaughtPoints();
		$this->sortCaughtGrids();
		$this->processGridImages();
		$this->barcodeValue = $this->checkBarCodes();
	}

	private function processCaughtPoints() {
		
		$oldLinedImage = imagecreatefromjpeg("test_image_vertical_line.jpg");
		$linedImage = imagecreatetruecolor($this->width,$this->height);

		imagecopy($linedImage,$oldLinedImage,0,0,0,0,$this->width,$this->height);

		foreach($this->caughtPoints as $xPos => $caughtPointX) {
			foreach($caughtPointX as $yPos => $caughtPointY) {
				imagefilledrectangle($linedImage, $xPos*10, $yPos*10, ($xPos+1)*10, ($yPos+1)*10,0xBBAAAAFF);
			}
		}

		imagejpeg($linedImage, "test_image_grid_line.jpg");
		
		$caughtCounter = 0;

		foreach($this->caughtPoints as $xPos => $caughtPointX) {
			foreach($caughtPointX as $yPos => $caughtPointY) {

				$done = false;

				$xCounter = 0;

				$this->caughtGrids[$caughtCounter][] = array($xCounter+$xPos, $yPos);

				while(isset($this->caughtPoints[$xPos+$xCounter+1][$yPos])) {

					$xCounter++;
				}

				$this->caughtGrids[$caughtCounter][] = array($xCounter+$xPos, $yPos);
				
				$caughtCounter++;
			}
		}

	}
	
	private function processGridImages() {
		
		foreach($this->caughtGrids as $key => $caughtGrid) {
			
			$width = $caughtGrid[1][0] - $caughtGrid[0][0] + 1;
			$height = $caughtGrid[1][1] - $caughtGrid[0][1] + 1;

			if($width == 1) {
				continue;
			}
			
			$calcedWDiv = 10;
			$calcedHDiv = 10;
			
			$gridImage = imagecreatetruecolor($width*$calcedWDiv,$height*$calcedHDiv);
			
			imagecopy($gridImage,$this->file,0,0,($caughtGrid[0][0])*$calcedWDiv,$caughtGrid[0][1]*$calcedHDiv,$width*$calcedWDiv,$height*$calcedHDiv);

			$grayScaleData = $this->grayScaleImage($gridImage, $width*$calcedWDiv, $height*$calcedHDiv);
			
			$this->barcodeImages[$key] = $grayScaleData;

			imagejpeg($gridImage, "grid_images/grid_image_$key.jpg");

		}
	}

	private function checkBarCodes() {

		foreach($this->barcodeImages as $barCodeImage) {
			$returnedVal = $this->checkBarCode($barCodeImage);

			if($returnedVal) {
				return $returnedVal;
			}
		}

		return false;
	}

	private function checkBarCode($barCodeImage) {

		foreach($barCodeImage as $yKey => $codeRow) {

			$lengths = array();
			$lengthCounter = 0;
			$lastVal = null;

			foreach($codeRow as $xKey => $codeCell) {

				$posVal = $codeCell/255;

				if($lastVal !== null) {

					if($posVal == $lastVal) {
						$lengthCounter++;
					} else {
						$lengths[] = array("length" => $lengthCounter+1, "val" => $lastVal);
						$lengthCounter = 0;
					}

				}

				$lastVal = $posVal;
			}

			$processData = $this->processBarCodeLine($lengths,$yKey);

			if(count($processData) == 12) {

				$isValid = true;

				foreach($processData as $processCell) {
					if($processCell === false) {
						$isValid = false;
					}
				}

				if($isValid) {
					return $processData;
				}
			}
		}

		return false;

	}

	private function processBarCodeLine($lengthArray,$setKey) {

		$startedCheck = false;
		$gotSecondHeader = false;
		$firstBytesLength = 0;

		if(!$lengthArray) {
			return false;
		}

		if($lengthArray[0]['val'] == 0) {
			array_shift($lengthArray);
		}

		$compartmentalisedLengths = array();
		$compartmentalisedLength = array();

		$start = false;

		if(!isset($lengthArray[1]) || !isset($lengthArray[3])) {
			return false;
		}

		// starting with UPCA barcode definition
		if($lengthArray[1]['length'] != $lengthArray[3]['length']) {
			return false;
		}

		$spaceLength = $lengthArray[1]['length'];

		for($i = 0 ; $i < 4 ; $i++) {
			array_shift($lengthArray);
		}

		$barSum = 0;
		$spaceSum = 0;

		$binaryValue = "";

		$maxCount = 4;
		$counter = 0;

		$values = array(
			0b1110010 => 0,
			0b1100110 => 1,
			0b1101100 => 2,
			0b1000010 => 3,
			0b1011100 => 4,
			0b1001110 => 5,
			0b1010000 => 6,
			0b1000100 => 7,
			0b1001000 => 8,
			0b1110100 => 9,
		);

		$setting = 1;
		$settingString = "";
		for($i = 0 ; $i < 5 ; $i++) {

			$settingString .= str_pad("",1,$setting);

			if($setting == 1) {
				$setting = 0;
			} else {
				$setting = 1;
			}
		}

		$settingArray = str_split($setting);

		$dataRow = array();

		$LR = false;
		$skippedMiddleCount = 0;

		foreach($lengthArray as $keyPosition => $partData) {

			if($LR && $skippedMiddleCount < 5) {
				$skippedMiddleCount++;
				continue;
			}

			$binaryValue .= str_pad("",$partData['length'],$partData['val']);

			$counter++;

			if($counter == 4) {

				$compressedBinary = "";
				$binaryValue = str_split($binaryValue);
				for($i = 0 ; $i < count($binaryValue) ; $i += $spaceLength) {

					$compressedBinary .= $binaryValue[$i]; 
				}

				$valueConverted = bindec($compressedBinary);

				if($LR) {
					$valueConverted = ~$valueConverted & 0x7F;
				}

				// echo "Testing ".decbin($valueConverted)."\n";

				if(isset($values[$valueConverted])) {
					// echo $values[$valueConverted]." \n";
					$dataRow[] = $values[$valueConverted];
				} else {
					// echo "N \n";
					$dataRow[] = false;
				}

				$binaryValue = "";

				$counter = 0;

				if(count($dataRow) == 6) {
					$LR = true;
				}

				if(count($dataRow) == 12) {
					break;
				}

			}

		}

		// echo implode(" ",$dataRow)."\n";

		return $dataRow;
	}

	private function sortCaughtGrids() {

		$sortedCaughtGrids = array();

		while($this->caughtGrids) {

			$biggestVal = -1;
			$biggestKey = -1;

			foreach($this->caughtGrids as $key => $caughtGrid) {

				$count = $caughtGrid[1][0]-$caughtGrid[0][0];

				// var_dump($count);

				if($count > $biggestVal) {
					$biggestKey = $key;
					$biggestVal = $count;
				}
			}

			$sortedCaughtGrids[] = $this->caughtGrids[$biggestKey];
			unset($this->caughtGrids[$biggestKey]);
		}

		$this->caughtGrids = $sortedCaughtGrids;

	}
	
	private function grayScaleImage($imageres, $width, $height) {
		
		$grayScaleArray = array();

		for($i = 0 ; $i < $width ; $i++) {
			for($j = 0 ; $j < $height ; $j++) {

				$pixelValue = imagecolorat($imageres,$i,$j);

				$rgbData = array(
					"r" => ($pixelValue & 0xFF0000) >> (8*2),
					"g" => ($pixelValue & 0xFF00) >> (8*1),
					"b" => ($pixelValue & 0xFF)
				);

				$grayScale = ($rgbData['r']*0.299)+($rgbData['g']*0.587)+($rgbData['b']*0.114);
				
				if($grayScale > 150) {
					$grayScale = 255;
				} else {
					$grayScale = 0;
				}
				
				imagesetpixel($imageres, $i, $j, ($grayScale << 16) + ($grayScale << 8) + $grayScale);

				$grayScaleArray[$j][$i] = $grayScale;
			}
		}
		
		return $grayScaleArray;
	}
	
	private function setPixel($imgres,$x,$y,$colour) {
		
		$fullColour = ($colour << 16) + ($colour << 8) + $colour;

		imagesetpixel($imgres, $x, $y, $fullColour);
	}
	
	private function calculateGridPositions() {
		
		$oldLinedImage = imagecreatefromjpeg("test_image_line.jpg");
		$linedImage = imagecreatetruecolor($this->width,$this->height);

		imagecopy($linedImage,$oldLinedImage,0,0,0,0,$this->width,$this->height);
		
		for($i = 0 ; $i < intval(ceil($this->width/$this->div)) ; $i++) {

			$linePos = $i * $this->div;

			for($j = 0 ; $j < $this->height ; $j++) {

				imagesetpixel($linedImage, $linePos, $j, 0xFF0000);
			}

		}
		
		imagejpeg($linedImage, "test_image_vertical_line.jpg");
		
	}
	
	private function calculateVerticalLines() {
		
		$linedImage = imagecreatetruecolor($this->width,$this->height);

		for($i = 0 ; $i < $this->width ; $i++) {

			for($j = 0 ; $j < $this->height ; $j++) {
				$this->setPixel($linedImage,$i,$j,$this->yImageArray[$i][$j]);
			}
		}
		
		$counter = 0;

		for($j = 0 ; $j < intval(ceil($this->height/$this->div)) ; $j++) {

			for($i = 1 ; $i < $this->width ; $i++) {

				$linePos = $j * $this->div;

				imagesetpixel($linedImage, $i, $linePos, 0xFF0000);

				if($this->yImageArray[$i][$linePos] > $this->magnitude) {

					$downCounter = 0;

					while($linePos+$downCounter < $this->height && $this->yImageArray[$i][$linePos+$downCounter] > $this->magnitude) {
						$downCounter++;
					}

					if($downCounter > 10) {

						for($k = 0 ; $k < $downCounter ; $k++) {
							imagesetpixel($linedImage, $i, $linePos+$k, 0x00FF00);
						}

						$drawnX = intval($i/10);

						$this->caughtPoints[$drawnX][$j] = true;

						$counter++;
					}
				}

			}
		}
		
		imagejpeg($linedImage, "test_image_line.jpg");
	}
	
	private function calculateYGradiant() {
		
		$yKernel = array(
			array(-1,-2,-1),
			array(0,0,0),
			array(1,2,1),
		);
		
		$yMask = array();
		
		$yImage = imagecreatetruecolor($this->width,$this->height);

		for($i = 0 ; $i < $this->width ; $i++) {

			for($j = 0 ; $j < $this->height ; $j++) {

				$convX = 0;
				$convY = 0;

				for($xi = 0 ; $xi < 3; $xi++) {
					for($xj = 0 ; $xj < 3; $xj++) {

						$yPosVal = $yKernel[$xi][$xj];
						$value = $this->getPixel($i+$xi-1,$j+$xj-1);
						$convY += $yPosVal*$value;

						$this->yImageArray[$i][$j] = $convY;

						$this->setPixel($yImage,$i,$j,$convY);
					}
				}
			}
		}
		
		imagejpeg($yImage, "test_image_y.jpg");
	}
	
	private function validatePos($x,$y) {
		
		return ($x >= 0 && $y >= 0 && $x < $this->width && $y < $this->height);
	}
	
	private function getScale() {

		$size = getimagesize($this->fileName);
		$this->width = $size[0];
		$this->height = $size[1];
	}
	
	private function processGrayScale() {
		
		for($i = 0 ; $i < $this->width ; $i++) {
			for($j = 0 ; $j < $this->height ; $j++) {
				$grayscale = $this->getPixel($i,$j);
				
//				$grayscale = ($rgbData['r']*0.299)+($rgbData['g']*0.587)+($rgbData['b']*0.114);
//				var_dump($grayscale);
				$this->imageData[$i][$j] = intval(round($grayscale));
			}
		}
	}
	
	private function getPixel($x,$y) {
		
		if($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
			return 0;
		}

		if(isset($this->imageData[$x][$y])) {
			return $this->imageData[$x][$y];
		}
		
		$pixelValue = imagecolorat($this->file,$x,$y);
		
//		die(var_dump(dechex($pixelValue)));

		$rgbData = array(
			"r" => ($pixelValue & 0xFF0000) >> (8*2),
			"g" => ($pixelValue & 0xFF00) >> (8*1),
			"b" => ($pixelValue & 0xFF)
		);
		
		return ($rgbData['r']*0.299)+($rgbData['g']*0.587)+($rgbData['b']*0.114);
	}
    
}