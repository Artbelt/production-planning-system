<?php
    require('fpdf.php');
    require('makefont/makefont.php');

    //MakeFont('C:\\Windows\\Fonts\\Calibri.ttf','koi8-r');



    $size = array(38,65);
    $pdf = new FPDF('L', 'mm',$size);
    $pdf->AddPage();
    $pdf->AddFont('Ariall','','ariall.php');
    $pdf->SetFont('Ariall', '', 12);
    $pdf->SetMargins(1, 1, 1);
    $pdf->SetAutoPageBreak(false);
    $pdf->Image('roll.jpg',0,0);
    /** @var  $filter_name */
    $filter_name = 'AF 1601';
    $pdf->Text(30, 8, $filter_name,0,0,"L");
    /** @var  $filter_width */
    $filter_width = "100";
    $pdf->Text(30, 15, $filter_width,0,0,"L");
    /** @var  $order */
    $order = "test_order";
    $pdf->Text(30, 21, $order,0,0,"L");
    /** @var  $height */
    $pdf->SetFont('Ariall', '', 16);
    $height = "48";
    $pdf->Text(7, 32, $height,0,0,"L");


    $pdf->SetLineWidth(1);
    $pdf->Rect(5,25,11,11,"D");
    //$pdf->Cell(2, 2, "Бухта");
    $pdf->Output();

?>