<?php
require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application as Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Mooseware\Silex\YamlConfigServiceProvider;
use Symfony\Component\Debug\ExceptionHandler;
use App\Services\VoyagerService;

ExceptionHandler::register();
$app = new Silex\Application();
$app['env'] = getenv('APP_ENV') ?: 'devel';


$app->register(new YamlConfigServiceProvider([
    'configPath' => __DIR__ . '/../app/config',
]));

$app['debug'] = $app['config']['application']['debug'];

print '<pre>' . print_r($app['config'], true) . '</pre>';

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../app/views',
    'twig.options' => array(
        'cache' => __DIR__ . '/../cache/templates'
    ),
));

$app->register(new \Silex\Provider\DoctrineServiceProvider(), array(
    'dbs.options' => $app['config']['db.options']
));

$app['voyager.service'] = new VoyagerService($app['dbs']['voyager.readonly'], $app['config']['voyager']);


// Swiftmailer
$app->register(new Silex\Provider\SwiftmailerServiceProvider());
$app['swiftmailer.options'] = $app['config']['mail.options'];

$app->before(function (Request $request) {
    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});

$app->before(function (Request $request) {
    $request->query->replace(
        array_change_key_case($request->query->all())
    );
});

$app->get('/', function(Application $app, Request $request) {
    return new Response($app['config']['application']['name'], 200);
});

$app->mount('/', include __DIR__ . '/../app/controllers/voyager.php');
$app->mount('/ezproxy', include __DIR__ . '/../app/controllers/ezproxy.php');

$app->run();

?>
