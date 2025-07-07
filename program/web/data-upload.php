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
$prefixes = [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    if (strpos(strtolower($file), 'account') !== false) {
        $requiredFiles['AccountFile'] = $uploadDir . $file;
        $prefixes[] = substr($file, 0, 7);
    } elseif (strpos(strtolower($file), 'census') !== false) {
        $requiredFiles['CensusFile'] = $uploadDir . $file;
        $prefixes[] = substr($file, 0, 7);
    } elseif (strpos(strtolower($file), 'plan') !== false) {
        $requiredFiles['PlanSpec'] = $uploadDir . $file;
        $prefixes[] = substr($file, 0, 7);
    }
}

// Determine the common prefix (assuming all three have the same prefix)
$commonPrefix = null;
if (count($prefixes) === 3 && $prefixes[0] === $prefixes[1] && $prefixes[1] === $prefixes[2]) {
    $commonPrefix = $prefixes[0];
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
    
    // Append PDFs starting with the common prefix to the final report
    $pdf_array = [];
    if ($commonPrefix !== null) {
        foreach (glob("{$uploadDir}{$commonPrefix}*.pdf") as $index => $filename) {
            $letter = chr(66 + $index); // Start from B
            $pdf_array[$letter] = $letter . "=\"" . $filename . "\"";
        }
    }
    
    $finalReport = "{$timestamp}_FinalReport.pdf";
    $outputPath = "{$file_location}{$finalReport}";
    $mainReport = "{$file_location}{$file_name}.pdf";
    
    if (!empty($pdf_array) && file_exists("{$main_dir}program/PDFtk/bin/pdftk")) {
        $pdfString = join(" ", $pdf_array);
        $pdfKeys = join(" ", array_keys($pdf_array));
        $myString = "A=\"{$mainReport}\"";
        $cmd = "\"{$main_dir}program/PDFtk/bin/pdftk\" {$myString} {$pdfString} cat A {$pdfKeys} output \"{$outputPath}\"";
        exec($cmd);
    } else {
        // If no additional PDFs, rename main report as final report
        rename($mainReport, $outputPath);
    }
    
    // Delete uploaded input files after report generation
    foreach ($requiredFiles as $filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    // Add final report file path to response
    $response = ["success" => true, "message" => "Report created successfully", "finalReportUrl" => $outputPath];
    
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

?>
