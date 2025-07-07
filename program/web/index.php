<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<html>
<head>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.4/jquery.min.js"></script>
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css">
<link rel="stylesheet" href="foundation/css/foundation.css" />
<script src="foundation/js/foundation.min.js"></script>
<script src="dist/jsoneditor.js"></script>
<script src="jquery.isloading.min.js"></script>

<style>
button.tiny, .button.tiny {
    padding: 0.625rem 0.25rem 0.6875rem 0.25rem;
    font-size: 0.6875rem;
}

.upload-section {
    margin: 20px 0;
    padding: 20px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
}

.upload-area {
    border: 2px dashed #ccc;
    padding: 20px;
    text-align: center;
    margin: 10px 0;
    background: #fff;
    transition: all 0.3s ease;
    cursor: pointer;
    min-height: 200px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.upload-area:hover, .upload-area.highlight {
    border-color: #007bff;
    background-color: #f8f9fa;
}

.upload-prompt {
    color: #666;
    margin-bottom: 20px;
}

.upload-prompt i {
    margin-bottom: 10px;
    color: #007bff;
}

.file-item {
    display: flex;
    align-items: center;
    padding: 8px;
    margin: 5px 0;
    background: #f8f9fa;
    border-radius: 4px;
    width: 100%;
}

.file-item i {
    margin-right: 8px;
    color: #007bff;
}

#fileList {
    width: 100%;
    margin-top: 20px;
}

#uploadStatus {
    margin-top: 10px;
    padding: 10px;
    display: none;
}

.status-success {
    color: #155724;
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    border-radius: 4px;
}

.status-error {
    color: #721c24;
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    border-radius: 4px;
}

#submit {
    margin-top: 20px;
    background-color: #007bff;
    color: white;
    padding: 10px 20px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
}

#submit:hover {
    background-color: #0056b3;
}
</style>


</head>
<body>

<div class='container'>
    <div class='row'>
        <div class='col-md-12'>
            <div id='editor_holder'></div>
            
            <!-- File Upload Section -->
            <div class="upload-section">
                <h4>Upload ASC Wizard Exports</h4>
                <p>Required files: account.txt, census.txt, plan.txt</p>
                <div class="upload-area" id="dropZone">
                    <input type="file" id="pdfUpload" name="pdfUpload[]" multiple accept=".pdf,.txt,.csv" style="display: none;"/>
                    <div class="upload-prompt">
                        <i class="fa fa-cloud-upload fa-3x"></i>
                        <p>Drop files here or click to browse</p>
                    </div>
                    <div id="requiredFiles" style="margin: 15px 0; text-align: left;">
                        <div><i class="fa fa-check-circle" style="color: #28a745;"></i> account.txt</div>
                        <div><i class="fa fa-check-circle" style="color: #28a745;"></i> census.txt</div>
                        <div><i class="fa fa-check-circle" style="color: #28a745;"></i> plan.txt</div>
                    </div>
                    <div id="fileList"></div>
                    <div id="uploadStatus"></div>
                </div>
            </div>
            
            <button id='submit'>Create Report</button>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Foundation
    $(document).foundation();

    // Set default options for JSONEditor
    JSONEditor.defaults.theme = 'foundation5';
    JSONEditor.defaults.iconlib = 'fontawesome4';
    JSONEditor.defaults.options.disable_edit_json = true;
    JSONEditor.defaults.options.disable_collapse = true;
    JSONEditor.defaults.options.disable_properties = true;

    // Initialize the editor
    var editor = new JSONEditor(document.getElementById('editor_holder'), {
        ajax: true,
        schema: {
            "title": "Actuarial Systems Corporation Report Creator",
            "type": "object",
            "$ref": "report.json"
        }
    });

    editor.on('change', function() {
        var errors = editor.validate();
        if (errors.length) {
            console.log('Validation errors:', errors);
        }
    });

    // File upload handling
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('pdfUpload');
    const fileList = document.getElementById('fileList');
    const uploadStatus = document.getElementById('uploadStatus');

    // Handle drag and drop events
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, highlight, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, unhighlight, false);
    });

    function highlight(e) {
        dropZone.classList.add('highlight');
    }

    function unhighlight(e) {
        dropZone.classList.remove('highlight');
    }

    // Handle file selection
    dropZone.addEventListener('click', () => fileInput.click());
    
    dropZone.addEventListener('drop', handleDrop);
    fileInput.addEventListener('change', handleFiles);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        handleFiles({ target: { files: files } });
    }

    function handleFiles(e) {
        const files = Array.from(e.target.files);
        updateFileList(files);
        uploadFiles(files);
    }

    function updateFileList(files) {
        fileList.innerHTML = files.map(file => `
            <div class="file-item">
                <i class="fa fa-file"></i>
                <span>${file.name}</span>
            </div>
        `).join('');
    }

    function uploadFiles(files) {
        const formData = new FormData();
        files.forEach(file => formData.append('files[]', file));

        $(uploadStatus)
            .removeClass('status-success status-error')
            .show()
            .html('<i class="fa fa-spinner fa-spin"></i> Uploading...');

        $.ajax({
            url: 'upload.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                response = typeof response === 'string' ? JSON.parse(response) : response;
                if (response.error) {
                    $(uploadStatus)
                        .html(response.error)
                        .addClass('status-error');
                } else {
                    $(uploadStatus)
                        .html(`Upload successful! ${response.files.length} files uploaded.`)
                        .addClass('status-success');
                    
                    // Update required files status
                    response.files.forEach(function(file) {
                        if (file.toLowerCase().includes('account')) {
                            $('#requiredFiles div:contains("account.txt") i')
                                .removeClass('fa-times-circle')
                                .addClass('fa-check-circle')
                                .css('color', '#28a745');
                        }
                        if (file.toLowerCase().includes('census')) {
                            $('#requiredFiles div:contains("census.txt") i')
                                .removeClass('fa-times-circle')
                                .addClass('fa-check-circle')
                                .css('color', '#28a745');
                        }
                        if (file.toLowerCase().includes('plan')) {
                            $('#requiredFiles div:contains("plan.txt") i')
                                .removeClass('fa-times-circle')
                                .addClass('fa-check-circle')
                                .css('color', '#28a745');
                        }
                    });
                }
            },
            error: function(xhr, status, error) {
                $(uploadStatus)
                    .html('Upload failed: ' + error)
                    .addClass('status-error');
            }
        });
    }

    // Submit button handling
    $('#submit').on('click', function() {
        // Check if all required files are uploaded
        const missingFiles = [];
        if (!$('#requiredFiles div:contains("account.txt") i').hasClass('fa-check-circle')) {
            missingFiles.push('account.txt');
        }
        if (!$('#requiredFiles div:contains("census.txt") i').hasClass('fa-check-circle')) {
            missingFiles.push('census.txt');
        }
        if (!$('#requiredFiles div:contains("plan.txt") i').hasClass('fa-check-circle')) {
            missingFiles.push('plan.txt');
        }
        
        if (missingFiles.length > 0) {
            alert("Please upload the following required files: " + missingFiles.join(", "));
            return;
        }

        $.isLoading({ text: "Creating Report..." });
        
        var data = JSON.stringify(editor.getValue());
        var formData = new FormData();
        formData.append("data", data);
        
        $.ajax({
            url: "data-upload.php",
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $.isLoading("hide");
                console.log("Raw response:", response);
                try {
                    if (typeof response === 'string') {
                        response = JSON.parse(response);
                    }
                    if (response.error) {
                        alert("Error: " + response.error);
                    } else {
                        alert("Report created successfully!");
                    }
                } catch (e) {
                    alert("Error creating report: Invalid response from server");
                }
            },
            error: function(xhr, status, error) {
                $.isLoading("hide");
                try {
                    var response = JSON.parse(xhr.responseText);
                    alert(response.error || "Error creating report: " + error);
                } catch (e) {
                    alert("Error creating report: " + error);
                }
            }
        });
    });
});

</script>
</body>
</html>
