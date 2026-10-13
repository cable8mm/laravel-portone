<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$sessionDirectory = getenv('PORTONE_CHECKOUT_FLOW_SESSION_DIR') ?: '';

if ($sessionDirectory === '') {
    http_response_code(503);
    echo 'The checkout flow session directory is not configured.';

    return;
}

$writeJson = static function (string $path, array $value): void {
    if (file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not write the checkout flow browser session.');
    }
};

$waitForJson = static function (string $path, int $timeout): array {
    $deadline = time() + $timeout;

    while (! is_file($path) && time() < $deadline) {
        usleep(100_000);
    }

    if (! is_file($path)) {
        http_response_code(504);

        return ['error' => 'The checkout flow test did not answer the browser request.'];
    }

    $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($value)) {
        throw new RuntimeException('The checkout flow browser response must be a JSON object.');
    }

    return $value;
};

if ($requestPath === '/checkout' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pagePath = $sessionDirectory.'/page.html';

    if (! is_file($pagePath)) {
        http_response_code(503);
        echo 'Waiting for the published checkout view.';

        return;
    }

    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<script type="importmap">{"imports":{"@portone/browser-sdk/v2":"/node_modules/@portone/browser-sdk/dist/v2.js"}}</script>';
    echo '</head><body>'.file_get_contents($pagePath);
    echo '<script>
        const checkout = document.querySelector("[data-portone-checkout]");
        const reportTerminalState = () => {
            if (checkout && ["success", "error"].includes(checkout.dataset.state)) {
                fetch("/report", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        state: checkout.dataset.state,
                        buttonDisabled: document.querySelector("#portone-checkout-button").disabled,
                        statusText: document.querySelector("#portone-checkout-status").textContent,
                    }),
                });
            }
        };
        new MutationObserver(reportTerminalState).observe(checkout, {
            attributes: true,
            childList: true,
            subtree: true,
            characterData: true,
        });
    </script>';
    echo '<script type="module" src="/portone-checkout.js"></script></body></html>';

    return;
}

if ($requestPath === '/portone-checkout.js' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $scriptPath = $sessionDirectory.'/portone-checkout.js';

    if (! is_file($scriptPath)) {
        http_response_code(503);
        echo 'Waiting for the published checkout script.';

        return;
    }

    header('Content-Type: text/javascript; charset=UTF-8');
    readfile($scriptPath);

    return;
}

if ($requestPath === '/report' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $report = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($report) || ! in_array($report['state'] ?? null, ['success', 'error'], true)) {
        http_response_code(400);
        echo 'Invalid checkout state report.';

        return;
    }

    $writeJson($sessionDirectory.'/report.json', $report);
    header('Content-Type: application/json; charset=UTF-8');
    echo '{"received":true}';

    return;
}

if (
    in_array($requestPath, ['/portone/checkout/prepare', '/portone/checkout/complete'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    $action = $requestPath === '/portone/checkout/prepare' ? 'prepare' : 'complete';
    $request = json_decode(file_get_contents('php://input'), true);
    $writeJson($sessionDirectory.'/'.$action.'-request.json', [
        'body' => is_array($request) ? $request : [],
        'receivedAt' => gmdate(DATE_ATOM),
    ]);

    $response = $waitForJson($sessionDirectory.'/'.$action.'-response.json', 900);
    http_response_code((int) ($response['status'] ?? 500));
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response['body'] ?? ['error' => 'Invalid test response.'], JSON_THROW_ON_ERROR);

    return;
}

return false;
