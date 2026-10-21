<?php

// Standalone, data-free preview. Never run browser-setup.php or application routes.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Livewire\Component;
use Livewire\Livewire;

$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/build/') && is_file($root.'/public'.$path)) {
    return false;
}
if (! preg_match('#^/(__headers/|livewire[^/]*/|flux/)#', $path)) {
    http_response_code(404);
    exit;
}
$runtime = getenv('HEADER_TEST_RUNTIME');
if (! $runtime || ! str_starts_with(realpath($runtime) ?: '', '/tmp/')) {
    throw new RuntimeException('HEADER_TEST_RUNTIME must be an existing temporary directory.');
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('h', 32)),
    'APP_CONFIG_CACHE' => $runtime.'/config.php',
    'APP_ROUTES_CACHE' => $runtime.'/routes.php',
    'LARAVEL_STORAGE_PATH' => $runtime,
    'VIEW_COMPILED_PATH' => $runtime.'/framework/views',
    'SESSION_DRIVER' => 'cookie', 'SESSION_SECURE_COOKIE' => 'false',
    'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'stderr',
    'MAIL_MAILER' => 'array', 'DB_CONNECTION' => 'header_tests_forbidden',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Fail closed even if a future fixture accidentally asks for a named connection.
foreach (array_keys(config('database.connections')) as $connection) {
    config(['database.connections.'.$connection.'.driver' => 'header_tests_forbidden']);
}
DB::extend('header_tests_forbidden', fn () => throw new RuntimeException('Header tests cannot access a database.'));
config(['database.connections.header_tests_forbidden' => ['driver' => 'header_tests_forbidden']]);
view()->addLocation(__DIR__);
Vite::useHotFile($runtime.'/no-vite-hot');

class TerminalHeaderFixture extends Component
{
    public string $page = 'dashboard';

    public string $name = '';

    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function save(): void
    {
        $this->validate(['name' => 'required|min:3']);
    }

    public function render()
    {
        $files = [
            'dashboard' => '⚡dashboard', 'tracking' => 'tracking/⚡index',
            'journal' => 'journal/⚡edit', 'habits' => 'habits/⚡index',
            'habits/create' => 'habits/⚡edit', 'habits/edit' => 'habits/⚡edit',
            'categories' => 'categories/⚡index', 'people' => 'people/⚡index',
            'settings/profile' => 'settings/⚡profile',
        ];
        abort_unless(isset($files[$this->page]), 404);
        $source = file_get_contents(resource_path('views/pages/'.$files[$this->page].'.blade.php'));
        preg_match('/<x-terminal-path\b[^>]*\/>/', $source, $eyebrow);
        preg_match('/<h1\b[^>]*>.*?<\/h1>/s', $source, $heading);
        $data = ['habitId' => $this->page === 'habits/edit' ? 1 : null, 'today' => ['name' => 'Header fixture']];

        return view('component', [
            'eyebrow' => Blade::render($eyebrow[0], $data),
            'heading' => Blade::render($heading[0], $data),
        ]);
    }
}
Livewire::component('terminal-header-fixture', TerminalHeaderFixture::class);
Route::middleware('web')->get('/__headers/{page}', fn (string $page) => view('page', ['page' => $page]))->where('page', '.*');
$app->handleRequest(Request::capture());
