<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use App\Http\Controllers\Admin\UkReportsController;

$request = Request::create('/admin/uk-reports', 'GET', [
    'from_date' => '2025-10-01',
    'to_date' => '2025-10-20',
    'delivery_mode' => 'all',
]);

$controller = app(UkReportsController::class);
$response = $controller->index($request);
$data = $response->getData();

print_r($data['seriesAttendanceOverTime']);
