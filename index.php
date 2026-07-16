<?php

header('Content-Type: application/json');


$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

/*THIS LINE FOR LOCAL SERVER ROUTING*/
$baseName = '/enflowCentralServer';
if (str_starts_with($uri, $baseName)) {
    $uri = substr($uri, strlen($baseName));
}
/*END IT*/


$basePath = __DIR__ . "/api";

/* API ROOT */
if ($uri === '' || $uri === '/') {
    echo json_encode(["message" => "API running"]);
    exit;
}



if ($uri === "/env") {
    require $basePath . "/env.php";
    exit;
}

/* SUBSCRIPTION PLAN ROUTES */
/* SECURE ROUTES */
if ($uri === "/flutterwave") {
    require $basePath . "/SECURE/flutterwave-key.php";
    exit;
}

if ($uri === "/sendTelegramReport") {
    require $basePath . "/SECURE/sendTelegramReport.php";
    exit;
}

/*GET*/
if ($uri === "/subscriptionContent") {
    require $basePath . "/plans/GET/CORS/subscriptionContent.php";
    exit;
}

if ($uri === "/settings") {
    require $basePath . "/plans/GET/CORS/settings.php";
    exit;
}

if ($uri === "/accountStatus") {
    require $basePath . "/plans/GET/CORS/accountStatus.php";
    exit;
}

/*POST*/
if ($uri === "/verifyAccess") {
    require $basePath . "/plans/POST/verifyAccess.php";
    exit;
}

if ($uri === "/deductCredits") {
    require $basePath . "/plans/POST/deductCredits.php";
    exit;
}

if ($uri === "/subPlans") {
    require $basePath . "/plans/POST/subPlans.php";
    exit;
}
if ($uri === "/trialSignup") {
    require $basePath . "/plans/POST/trial-signup.php";
    exit;
}
if ($uri === "/onboarding") {
    require $basePath . "/plans/POST/onboarding-welcome.php";
    exit;
}
if ($uri === "/onboardingSetPassword") {
    require $basePath . "/plans/POST/onboarding-set-password.php";
    exit;
}
if ($uri === "/onboardingVerifyOtp") {
    require $basePath . "/plans/POST/onboarding-verify-otp.php";
    exit;
}
if ($uri === "/onboardingResendOtp") {
    require $basePath . "/plans/POST/onboarding-resend-otp.php";
    exit;
}
if ($uri === "/onboardingBusiness") {
    require $basePath . "/plans/POST/onboarding-business.php";
    exit;
}
if ($uri === "/onboardingBusinessType") {
    require $basePath . "/plans/POST/onboarding-business-type.php";
    exit;
}
if ($uri === "/onboardingTools") {
    require $basePath . "/plans/POST/onboarding-tools.php";
    exit;
}
if ($uri === "/onboardingTeam") {
    require $basePath . "/plans/POST/onboarding-team.php";
    exit;
}
if ($uri === "/onboardingZara") {
    require $basePath . "/plans/POST/onboarding-zara.php";
    exit;
}
if ($uri === "/onboardingFinalize") {
    require $basePath . "/plans/POST/onboarding-finalize.php";
    exit;
}
if ($uri === "/onboardingStatus") {
    require $basePath . "/plans/POST/get-onboarding-status.php";
    exit;
}
if ($uri === "/login") {
    require $basePath . "/plans/POST/login.php";
    exit;
}
if ($uri === "/logout") {
    require $basePath . "/plans/POST/logout.php";
    exit;
}
if ($uri === "/dashboard") {
    require $basePath . "/plans/GET/CORS/dashboard.php";
    exit;
}
if ($uri === "/zaraTopup") {
    require $basePath . "/plans/POST/zara-topup.php";
    exit;
}
if ($uri === "/countdown") {
    require $basePath . "/plans/POST/countdown.php";
    exit;
}




/* 404 */
http_response_code(404);
echo json_encode(["error" => "Route not found"]);
