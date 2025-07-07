<?php

file_put_contents("dataOutput.json", $_REQUEST['data']);

// Debug log for incoming request data
file_put_contents(__DIR__ . '/data-upload-debug.log', "Request data: " . $_REQUEST['data'] . "\n", FILE_APPEND);

$main_dir = substr(__DIR__, 0, -11);

$data = json_decode($_REQUEST['data'], 1);

$clean_prep = str_replace(" ", "_", $data['Preparer']);
$ini_file = $main_dir . 'preparers\\'. $clean_prep . '.ini';

// Check if preparer file exists before using it
if (file_exists($ini_file)) {
    $ini_data = parse_ini_file($ini_file);
    $data = array_merge($ini_data, $data);
} else {
    // Log that we're using default settings
    error_log("No preparer file found for: " . $data['Preparer'] . ". Using default settings.");
}

// Process uploaded PDFs for vesting schedules
require_once($main_dir . 'program/code/pdf_processor.php');
$pdfProcessor = new PDFProcessor();
try {
    $vestingResults = $pdfProcessor->processAllPDFs();
    
    // Add vesting schedule results to the data
    if (!empty($vestingResults)) {
        $data['vesting_schedules'] = $vestingResults;
        
        // Update plan year limit based on vesting schedule analysis
        foreach ($vestingResults as $result) {
            if (!isset($result['error']) && isset($result['schedule_type'])) {
                $data['vesting_schedule_type'] = $result['schedule_type'];
                break; // Use the first valid schedule found
            }
        }
    }
} catch (Exception $e) {
    error_log("Error processing PDFs: " . $e->getMessage());
}

$main_config_data = parse_ini_file($main_dir . 'config.ini', true);

$file_location = $main_config_data["FILES_LOCATION"];
// Generate a timestamp-based prefix if none provided
$timestamp = date('YmdHis');
$file_name = $timestamp."_PDFReport";
$orig_prefix = $timestamp;

$prefix = $file_location.$timestamp;

// Include files using correct path
require_once(__DIR__ . "/../code/parse-files.php");
require_once(__DIR__ . "/../code/library.php");

// Check for required files in the uploads directory
$uploadDir = './files/uploads/';
$requiredFiles = [
    'AccountFile' => null,
    'CensusFile' => null,
    'PlanSpec' => null
];

// Scan upload directory for required files
$files = scandir($uploadDir);
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    if (strpos($file, 'account') !== false) {
        $requiredFiles['AccountFile'] = $uploadDir . $file;
    } elseif (strpos($file, 'census') !== false) {
        $requiredFiles['CensusFile'] = $uploadDir . $file;
    } elseif (strpos($file, 'plan') !== false) {
        $requiredFiles['PlanSpec'] = $uploadDir . $file;
    }
}

// Check if all required files were found
if (in_array(null, $requiredFiles, true)) {
    $missing = array_keys(array_filter($requiredFiles, function($v) { return $v === null; }));
    http_response_code(400);
    echo json_encode(["error" => "Missing required files: " . implode(', ', $missing)]);
    exit;
}

$data['files'] = [$requiredFiles];

try {
    create_templates($file_name, $data);
    $response = ["success" => true, "message" => "Report created successfully"];
    
    // Delete uploaded input files after report generation
    foreach ($requiredFiles as $filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    // Add final report file path to response
    $finalReport = $file_location . $timestamp . "_FinalReport.pdf";
    $response['finalReportUrl'] = $finalReport;
    
    file_put_contents(__DIR__ . '/data-upload-debug.log', json_encode($response) . "\n", FILE_APPEND);
    echo json_encode($response);
} catch (Exception $e) {
    $errorMsg = $e->getMessage() . "\n" . $e->getTraceAsString();
    file_put_contents(__DIR__ . '/data-upload-error.log', $errorMsg, FILE_APPEND);
    http_response_code(500);
    echo json_encode(["error" => "Internal server error. Please check the logs."]);
    exit;
}

// Use wkhtmltopdf command for PDF generation
$wkhtmltopdf = escapeshellcmd("{$main_dir}program/wkhtmltopdf/bin/wkhtmltopdf");
$headerHtml = escapeshellarg("{$main_dir}program/templates/components/header.html");
$footerHtml = escapeshellarg("{$main_dir}program/templates/components/footer.html");
$inputHtml = escapeshellarg("{$main_dir}compiled/reports.html");
$outputPdf = escapeshellarg("{$file_location}{$file_name}.pdf");

$cmd = "{$wkhtmltopdf} -s Letter -L 0 -T 10 -R 0 -O portrait --header-spacing 9 --enable-smart-shrinking --header-html {$headerHtml} --footer-html {$footerHtml} {$inputHtml} {$outputPdf}";

exec($cmd);

sleep(2);

$counter = 0;

$w = ["B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U", "V", "W", "X", "Y", "Z"];

foreach (glob("{$file_location}{$orig_prefix}*.pdf") as $index => $filename) {
    if (!strpos($filename, "PDFReport") && !strpos($filename, "FinalReport")) {
        $letter = $w[$index];
        $pdf_array[$letter] = $letter . "=\"" . $filename . "\"";
    }
}

if (!empty($pdf_array)) {
    $pdfString = join(" ", $pdf_array);
    $pdfKeys = join(" ", array_keys($pdf_array));
    $myString = "A=\"{$file_location}{$orig_prefix}_PDFReport.pdf\"";
    
    $finalReport = "{$orig_prefix}_FinalReport.pdf";
    $outputPath = "{$file_location}{$finalReport}";
    
    if (file_exists("{$main_dir}program/PDFtk/bin/pdftk")) {
        $cmd = "\"{$main_dir}program/PDFtk/bin/pdftk\" {$myString} {$pdfString} cat A {$pdfKeys} output \"{$outputPath}\"";
        exec($cmd);
    }
}

echo json_encode(["success" => true, "message" => "Report created successfully"]);
