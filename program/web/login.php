<?php
session_start();

// Function to load template content
function load_template($companyKey) {
    $baseTemplate = __DIR__ . '/login.html';
    $companyTemplateDir = __DIR__ . '/../templates/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $companyKey);
    $companyTemplate = $companyTemplateDir . '/login.html';

    if ($companyKey && is_dir($companyTemplateDir) && file_exists($companyTemplate)) {
        return file_get_contents($companyTemplate);
    }
    return file_get_contents($baseTemplate);
}

// Redirect to main app if already logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$companyKey = '';

// Handle POST login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyKey = isset($_POST['companyKey']) ? trim($_POST['companyKey']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // Basic validation
    if (!$companyKey || !$username || !$password) {
        $error = 'Please fill in all fields.';
    } else {
        // TODO: Replace this with real authentication logic
        // For demonstration, accept any username/password if companyKey is not empty
        // You can add your own user validation here

        // Example: simple hardcoded check (for demo only)
        if ($username === 'admin' && $password === 'password') {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['companyKey'] = $companyKey;

            // Redirect to main app page
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

// Load the login template content
$templateContent = load_template($companyKey);

// Inject error message if any
if ($error) {
    $errorHtml = '<div class="error-message" style="display:block;">' . htmlspecialchars($error) . '</div>';
    // Replace the error message div placeholder in template
    $templateContent = preg_replace('/<div class="error-message" id="errorMessage"><\/div>/', $errorHtml, $templateContent);
} else {
    // Ensure error message div is hidden
    $templateContent = preg_replace('/<div class="error-message" id="errorMessage"><\/div>/', '<div class="error-message" id="errorMessage" style="display:none;"></div>', $templateContent);
}

// Pre-fill companyKey field if available
if ($companyKey) {
    $templateContent = preg_replace('/(<input[^>]*name="companyKey"[^>]*value=")[^"]*(")/', '${1}' . htmlspecialchars($companyKey) . '${2}', $templateContent);
} else {
    // Add value attribute if missing
    if (!preg_match('/<input[^>]*name="companyKey"[^>]*value=/', $templateContent)) {
        $templateContent = preg_replace('/(<input[^>]*name="companyKey"[^>]*)(\/?>)/', '${1} value="" ${2}', $templateContent);
    }
}

echo $templateContent;
?>
