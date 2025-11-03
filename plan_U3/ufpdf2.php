<?php
/*******************************************************************************
* Software: UFPDF2, Unicode Free PDF 2 generator                               *
* Version:  0.1                                                                *
*           based on FPDF 1.6 by Olivier PLATHEY                               *
*           based on UFPDF 0.1 by Steven Wittens                               *
* Date:     2008-10-01                                                         *
* Author:   Dmitry Shovchko <d.shovchko@gmail.com>                             *
* License:  GPL                                                                *
*                                                                              *
* UFPDF2 is a modification of FPDF & UFPDF to support Unicode through UTF-8.   *
*                                                                              *
*******************************************************************************/

if(!class_exists('UFPDF2'))
{
define('UFPDF2_VERSION','0.1');

include_once 'ufpdf.php';

class UFPDF2 extends UFPDF
{
var $widths;
var $aligns;
var $height=5;

function UFPDF_2($orientation='P',$unit='mm',$format='A4')
{
	UFPDF::UFPDF($orientation, $unit, $format);
}

function SetWidths($w)
{
	//Set the array of column widths
	$this->widths=$w;
}

function SetAligns($a)
{
	//Set the array of column alignments
	$this->aligns=$a;
}

function SetHeight($h)
{
	//Set the height of row
	$this->height=$h;
}

function Row($data)
{
	//Calculate the height of the row
	$nb=0;
	for($i=0;$i<count($data);$i++)
		$nb=max($nb,$this->NbLines($this->widths[$i],$data[$i]));
	$h=$this->height*$nb;
	//Issue a page break first if needed
	$this->CheckPageBreak($h);
	//Draw the cells of the row
	for($i=0;$i<count($data);$i++)
	{
		$w=$this->widths[$i];
		$a=isset($this->aligns[$i]) ? $this->aligns[$i] : 'L';
		//Save the current position
		$x=$this->GetX();
		$y=$this->GetY();
		//Draw the border
		$this->Rect($x,$y,$w,$h);
		//Print the text
		$this->MultiCell($w,$this->height,$data[$i],0,$a);
		//Put the position to the right of the cell
		$this->SetXY($x+$w,$y);
	}
	//Go to the next line
	$this->Ln($h);
}

function CheckPageBreak($h)
{
	//If the height h would cause an overflow, add a new page immediately
	if($this->GetY()+$h>$this->PageBreakTrigger)
		$this->AddPage($this->CurOrientation);
}

function NbLines($w,$txt)
{
	//Computes the number of lines a MultiCell of width w will take
	$cw=&$this->CurrentFont['cw'];
	if($w==0)
		$w=$this->w-$this->rMargin-$this->x;
	$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
	$s=$this->utf8_to_codepoints(str_replace("\r",'',$txt));
//	$s=str_replace("\r",'',$txt);
//	$nb=strlen($s);
//	if($nb>0 and $s[$nb-1]=="\n")
//		$nb--;
	$sep=-1;
	$i=0;
	$j=0;
	$l=0;
	$nl=1;
//	while($i<$nb)
	foreach($s as $c)
	{
//		$c=$s[$i];
//		if($c=="\n")
		if ($c == 10)
		{
			$i++;
			$sep=-1;
			$j=$i;
			$l=0;
			$nl++;
			continue;
		}
//		if($c==' ')
		if ($c == 32)
			$sep=$i;
		$l+=$cw[$c];
		if($l>$wmax)
		{
			if($sep==-1)
			{
				if($i==$j)
					$i++;
			}
			else
				$i=$sep+1;
			$sep=-1;
			$j=$i;
			$l=0;
			$nl++;
		}
		else
			$i++;
	}
	return $nl;
}

function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
{
	//Output text with automatic or explicit line breaks
	$cw=&$this->CurrentFont['cw'];
	if($w==0)
		$w=$this->w-$this->rMargin-$this->x;
	$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
	$s=$this->utf8_to_codepoints(str_replace("\r",'',$txt));
//	$s=str_replace("\r",'',$txt);
//	$nb=strlen($s);
//	if($nb>0 && $s[$nb-1]=="\n")
//		$nb--;
	$b=0;
	if($border)
	{
		if($border==1)
		{
			$border='LTRB';
			$b='LRT';
			$b2='LR';
		}
		else
		{
			$b2='';
			if(strpos($border,'L')!==false)
				$b2.='L';
			if(strpos($border,'R')!==false)
				$b2.='R';
			$b=(strpos($border,'T')!==false) ? $b2.'T' : $b2;
		}
	}
	$sep=-1;
	$i=0;
	$j=0;
	$l=0;
	$ns=0;
	$nl=1;
//	while($i<$nb)
	foreach($s as $c)
	{
		//Get next character
//		$c=$s[$i];
//		if($c=="\n")
		if ($c == 10)
		{
			//Explicit line break
			if($this->ws>0)
			{
				$this->ws=0;
				$this->_out('0 Tw');
			}
//			$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
			$this->Cell($w,$h,$this->usubstr($s,$j,$i-$j),$b,2,$align,$fill);
			$i++;
			$sep=-1;
			$j=$i;
			$l=0;
			$ns=0;
			$nl++;
			if($border && $nl==2)
				$b=$b2;
			continue;
		}
//		if($c==' ')
		if($c == 32)
		{
			$sep=$i;
			$ls=$l;
			$ns++;
		}
		$l+=$cw[$c];
		if($l>$wmax)
		{
			//Automatic line break
			if($sep==-1)
			{
				if($i==$j)
					$i++;
				if($this->ws>0)
				{
					$this->ws=0;
					$this->_out('0 Tw');
				}
//				$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
				$this->Cell($w,$h,$this->usubstr($s,$j,$i-$j),$b,2,$align,$fill);
			}
			else
			{
				if($align=='J')
				{
					$this->ws=($ns>1) ? ($wmax-$ls)/1000*$this->FontSize/($ns-1) : 0;
					$this->_out(sprintf('%.3F Tw',$this->ws*$this->k));
				}
//				$this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill);
				$this->Cell($w,$h,$this->usubstr($s,$j,$sep-$j),$b,2,$align,$fill);
				$i=$sep+1;
			}
			$sep=-1;
			$j=$i;
			$l=0;
			$ns=0;
			$nl++;
			if($border && $nl==2)
				$b=$b2;
		}
		else
			$i++;
	}
	//Last chunk
	if($this->ws>0)
	{
		$this->ws=0;
		$this->_out('0 Tw');
	}
	if($border && strpos($border,'B')!==false)
		$b.='B';
//	$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
	$this->Cell($w,$h,$this->usubstr($s,$j),$b,2,$align,$fill);
	$this->x=$this->lMargin;
}

function Write($h, $txt, $link='')
{
	//Output text in flowing mode
	$cw=&$this->CurrentFont['cw'];
	$w=$this->w-$this->rMargin-$this->x;
	$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
	$s=$this->utf8_to_codepoints(str_replace("\r",'',$txt));
//	$s=str_replace("\r",'',$txt);
//	$nb=strlen($s);
	$sep=-1;
	$i=0;
	$j=0;
	$l=0;
	$nl=1;
//	while($i<$nb)
	foreach($s as $c)
	{
		//Get next character
//		$c=$s[$i];
//		if($c=="\n")
		if ($c == 10)
		{
			//Explicit line break
//			$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
			$this->Cell($w,$h,$this->usubstr($s,$j,$i-$j),0,2,'',0,$link);
			$i++;
			$sep=-1;
			$j=$i;
			$l=0;
			if($nl==1)
			{
				$this->x=$this->lMargin;
				$w=$this->w-$this->rMargin-$this->x;
				$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
			}
			$nl++;
			continue;
		}
//		if($c==' ')
		if($c==32)
			$sep=$i;
		$l+=$cw[$c];
		if($l>$wmax)
		{
			//Automatic line break
			if($sep==-1)
			{
				if($this->x>$this->lMargin)
				{
					//Move to next line
					$this->x=$this->lMargin;
					$this->y+=$h;
					$w=$this->w-$this->rMargin-$this->x;
					$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
					$i++;
					$nl++;
					continue;
				}
				if($i==$j)
					$i++;
//				$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
				$this->Cell($w,$h,$this->usubstr($s,$j,$i-$j),0,2,'',0,$link);
			}
			else
			{
//				$this->Cell($w,$h,substr($s,$j,$sep-$j),0,2,'',0,$link);
				$this->Cell($w,$h,$this->usubstr($s,$j,$sep-$j),0,2,'',0,$link);
				
				$i=$sep+1;
			}
			$sep=-1;
			$j=$i;
			$l=0;
			if($nl==1)
			{
				$this->x=$this->lMargin;
				$w=$this->w-$this->rMargin-$this->x;
				$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
			}
			$nl++;
		}
		else
			$i++;
	}
	//Last chunk
	if($i!=$j) {
		
		$restxt = "";
		for($ii=$j; $ii<count($s); $ii++) {
			$restxt .= $this->code2utf($s[$ii]);
		}
//		$this->Cell($l/1000*$this->FontSize,$h,substr($s,$j),0,0,'',0,$link);
		$this->Cell($l/1000*$this->FontSize,$h,$this->usubstr($s,$j),0,0,'',0,$link);
	}
}

function usubstr($arr, $from, $to=false)
{
	if (!$to) $to = count($arr);
	$res = "";
	for($i=$from; $i<$to; $i++) {
		$res .= $this->code2utf($arr[$i]);
	}
	
	return $res;
} //usubstr()

function _puttruetypeunicode($font) {
  //Type0 Font
  $widths='';
  $s='';
  $this->_newobj();
  $this->_out('<</Type /Font');
  $this->_out('/Subtype /Type0');
  $this->_out('/BaseFont /'. $font['name'] .'-UCS');
  $this->_out('/Encoding /Identity-H');
  $this->_out('/DescendantFonts ['. ($this->n + 1) .' 0 R]');
  $this->_out('>>');
  $this->_out('endobj');

  //CIDFont
  $this->_newobj();
  $this->_out('<</Type /Font');
  $this->_out('/Subtype /CIDFontType2');
  $this->_out('/BaseFont /'. $font['name']);
  $this->_out('/CIDSystemInfo <</Registry (Adobe) /Ordering (UCS) /Supplement 0>>');
  $this->_out('/FontDescriptor '. ($this->n + 1) .' 0 R');
  $c = 0;
  foreach ($font['cw'] as $i => $w) {
    $widths .= $i .' ['. $w.'] ';
  }
  $this->_out('/W ['. $widths .']');
  $this->_out('/CIDToGIDMap '. ($this->n + 2) .' 0 R');
  $this->_out('>>');
  $this->_out('endobj');

  //Font descriptor
  $this->_newobj();
  $this->_out('<</Type /FontDescriptor');
  $this->_out('/FontName /'.$font['name']);
  foreach ($font['desc'] as $k => $v) {
    $s .= ' /'. $k .' '. $v;
  }
  if ($font['file']) {
		$s .= ' /FontFile2 '. $this->FontFiles[$font['file']]['n'] .' 0 R';
  }
  $this->_out($s);
  $this->_out('>>');
  $this->_out('endobj');

  //Embed CIDToGIDMap
  $this->_newobj();
  if(defined('FPDF_FONTPATH'))
    $file=FPDF_FONTPATH.$font['ctg'];
  else
    $file=$font['ctg'];
  $size=filesize($file);
  if(!$size)
    $this->Error('Font file not found');
  $this->_out('<</Length '.$size);
	if(substr($file,-2) == '.z')
    $this->_out('/Filter /FlateDecode');
  $this->_out('>>');
  $f = fopen($file,'rb');
  $this->_putstream(fread($f,$size));
  fclose($f);
  $this->_out('endobj');
}

function code2utf($number)
{
	if ($number < 0)
		return FALSE;
	
	if ($number < 128)
		return chr($number);
	
	// Removing / Replacing Windows Illegals Characters
	if ($number < 160)
	{
		if ($number==128) $number=8364;
		elseif ($number==129) $number=160; // (Rayo:) #129 using no relevant sign, thus, mapped to the saved-space #160
		elseif ($number==130) $number=8218;
		elseif ($number==131) $number=402;
		elseif ($number==132) $number=8222;
		elseif ($number==133) $number=8230;
		elseif ($number==134) $number=8224;
		elseif ($number==135) $number=8225;
		elseif ($number==136) $number=710;
		elseif ($number==137) $number=8240;
		elseif ($number==138) $number=352;
		elseif ($number==139) $number=8249;
		elseif ($number==140) $number=338;
		elseif ($number==141) $number=160; // (Rayo:) #129 using no relevant sign, thus, mapped to the saved-space #160
		elseif ($number==142) $number=381;
		elseif ($number==143) $number=160; // (Rayo:) #129 using no relevant sign, thus, mapped to the saved-space #160
		elseif ($number==144) $number=160; // (Rayo:) #129 using no relevant sign, thus, mapped to the saved-space #160
		elseif ($number==145) $number=8216;
		elseif ($number==146) $number=8217;
		elseif ($number==147) $number=8220;
		elseif ($number==148) $number=8221;
		elseif ($number==149) $number=8226;
		elseif ($number==150) $number=8211;
		elseif ($number==151) $number=8212;
		elseif ($number==152) $number=732;
		elseif ($number==153) $number=8482;
		elseif ($number==154) $number=353;
		elseif ($number==155) $number=8250;
		elseif ($number==156) $number=339;
		elseif ($number==157) $number=160; // (Rayo:) #129 using no relevant sign, thus, mapped to the saved-space #160
		elseif ($number==158) $number=382;
		elseif ($number==159) $number=376;
	} //if
	
	if ($number < 2048)
		return chr(($number >> 6) + 192) . chr(($number & 63) + 128);
	if ($number < 65536)
		return chr(($number >> 12) + 224) . chr((($number >> 6) & 63) + 128) . chr(($number & 63) + 128);
	if ($number < 2097152)
		return chr(($number >> 18) + 240) . chr((($number >> 12) & 63) + 128) . chr((($number >> 6) & 63) + 128) . chr(($number & 63) + 128);
	
	return FALSE;
} //code2utf()

// UTF-8 to UTF-16BE conversion.
// Correctly handles all illegal UTF-8 sequences.
function utf8_to_utf16be(&$txt, $bom = true) {
  $l = strlen($txt);
  $out = $bom ? "\xFE\xFF" : '';
  for ($i = 0; $i < $l; ++$i) {
    $c = ord($txt{$i});
    // ASCII
    if ($c < 0x80) {
      $out .= "\x00". $txt{$i};
    }
    // Lost continuation byte
    else if ($c < 0xC0) {
      $out .= "\xFF\xFD";
      continue;
    }
    // Multibyte sequence leading byte
    else {
      if ($c < 0xE0) {
        $s = 2;
      }
      else if ($c < 0xF0) {
        $s = 3;
      }
      else if ($c < 0xF8) {
        $s = 4;
      }
      // 5/6 byte sequences not possible for Unicode.
      else {
        $out .= "\xFF\xFD";
        while (ord($txt{$i + 1}) >= 0x80 && ord($txt{$i + 1}) < 0xC0) { ++$i; }
        continue;
      }
      
      $q = array($c);
      // Fetch rest of sequence
      while (ord($txt{$i + 1}) >= 0x80 && ord($txt{$i + 1}) < 0xC0) { ++$i; $q[] = ord($txt{$i}); if($i+1==$l)break; }
      
      // Check length
      if (count($q) != $s) {
        $out .= "\xFF\xFD";        
        continue;
      }
      
      switch ($s) {
        case 2:
          $cp = (($q[0] ^ 0xC0) << 6) | ($q[1] ^ 0x80);
          // Overlong sequence
          if ($cp < 0x80) {
            $out .= "\xFF\xFD";        
          }
          else {
            $out .= chr($cp >> 8);
            $out .= chr($cp & 0xFF);
          }
          continue;

        case 3:
          $cp = (($q[0] ^ 0xE0) << 12) | (($q[1] ^ 0x80) << 6) | ($q[2] ^ 0x80);
          // Overlong sequence
          if ($cp < 0x800) {
            $out .= "\xFF\xFD";        
          }
          // Check for UTF-8 encoded surrogates (caused by a bad UTF-8 encoder)
          else if ($c > 0xD800 && $c < 0xDFFF) {
            $out .= "\xFF\xFD";
          }
          else {
            $out .= chr($cp >> 8);
            $out .= chr($cp & 0xFF);
          }
          continue;

        case 4:
          $cp = (($q[0] ^ 0xF0) << 18) | (($q[1] ^ 0x80) << 12) | (($q[2] ^ 0x80) << 6) | ($q[3] ^ 0x80);
          // Overlong sequence
          if ($cp < 0x10000) {
            $out .= "\xFF\xFD";
          }
          // Outside of the Unicode range
          else if ($cp >= 0x10FFFF) {
            $out .= "\xFF\xFD";            
          }
          else {
            // Use surrogates
            $cp -= 0x10000;
            $s1 = 0xD800 | ($cp >> 10);
            $s2 = 0xDC00 | ($cp & 0x3FF);
            
            $out .= chr($s1 >> 8);
            $out .= chr($s1 & 0xFF);
            $out .= chr($s2 >> 8);
            $out .= chr($s2 & 0xFF);
          }
          continue;
      }
    }
  }
  return $out;
}

// UTF-8 to codepoint array conversion.
// Correctly handles all illegal UTF-8 sequences.
function utf8_to_codepoints(&$txt) {
  $l = strlen($txt);
  $out = array();
  for ($i = 0; $i < $l; ++$i) {
    $c = ord($txt{$i});
    // ASCII
    if ($c < 0x80) {
      $out[] = ord($txt{$i});
    }
    // Lost continuation byte
    else if ($c < 0xC0) {
      $out[] = 0xFFFD;
      continue;
    }
    // Multibyte sequence leading byte
    else {
      if ($c < 0xE0) {
        $s = 2;
      }
      else if ($c < 0xF0) {
        $s = 3;
      }
      else if ($c < 0xF8) {
        $s = 4;
      }
      // 5/6 byte sequences not possible for Unicode.
      else {
        $out[] = 0xFFFD;
        while (ord($txt{$i + 1}) >= 0x80 && ord($txt{$i + 1}) < 0xC0) { ++$i; }
        continue;
      }
      
      $q = array($c);
      // Fetch rest of sequence
      while (ord($txt{$i + 1}) >= 0x80 && ord($txt{$i + 1}) < 0xC0) { ++$i; $q[] = ord($txt{$i}); if($i+1==$l)break;}
      
      // Check length
      if (count($q) != $s) {
        $out[] = 0xFFFD;
        continue;
      }
      
      switch ($s) {
        case 2:
          $cp = (($q[0] ^ 0xC0) << 6) | ($q[1] ^ 0x80);
          // Overlong sequence
          if ($cp < 0x80) {
            $out[] = 0xFFFD;
          }
          else {
            $out[] = $cp;
          }
          continue;

        case 3:
          $cp = (($q[0] ^ 0xE0) << 12) | (($q[1] ^ 0x80) << 6) | ($q[2] ^ 0x80);
          // Overlong sequence
          if ($cp < 0x800) {
            $out[] = 0xFFFD;
          }
          // Check for UTF-8 encoded surrogates (caused by a bad UTF-8 encoder)
          else if ($c > 0xD800 && $c < 0xDFFF) {
            $out[] = 0xFFFD;
          }
          else {
            $out[] = $cp;
          }
          continue;

        case 4:
          $cp = (($q[0] ^ 0xF0) << 18) | (($q[1] ^ 0x80) << 12) | (($q[2] ^ 0x80) << 6) | ($q[3] ^ 0x80);
          // Overlong sequence
          if ($cp < 0x10000) {
            $out[] = 0xFFFD;
          }
          // Outside of the Unicode range
          else if ($cp >= 0x10FFFF) {
            $out[] = 0xFFFD;
          }
          else {
            $out[] = $cp;
          }
          continue;
      }
    }
  }
  return $out;
}

}


}
?>