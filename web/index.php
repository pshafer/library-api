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

// Register the YAML Config Service provide to read
// environment configurations
$app->register(new YamlConfigServiceProvider([
    'configPath' => __DIR__ . '/../app/config',
]));

// enable/disable debugging based on environment config
$app['debug'] = $app['config']['application']['debug'];

// Instantiate / Register the Twig Template Service Provider
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../app/views',
    'twig.options' => array(
        'cache' => __DIR__ . '/../cache/templates'
    ),
));

// Instantiate / Register the Doctring ORM DB Service Provider
$app->register(new \Silex\Provider\DoctrineServiceProvider(), array(
    'dbs.options' => $app['config']['db.options']
));

// Instantiate the Validator Service Provider
$app->register(new Silex\Provider\ValidatorServiceProvider());


// Instantiate a Voyager Service Provider
$app['voyager.service'] = new VoyagerService($app['dbs']['voyager.readonly'], $app['config']['voyager']);

// Instantiate a Swiftmailer
$app->register(new Silex\Provider\SwiftmailerServiceProvider());
$app['swiftmailer.options'] = $app['config']['mail.options'];


// Middleware that will decode JSON encoded request bodies
$app->before(function (Request $request) {
    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});

// Middleware to lowercase array keys
$app->before(function (Request $request) {
    $request->query->replace(
        array_change_key_case($request->query->all())
    );
});

// Simply Returns The application name on the default route
$app->get('/', function(Application $app, Request $request) {
    return new Response($app['config']['application']['name'], 200);
});

// Mount The Voyager controller at the /v1 base url
$app->mount('/v1', include __DIR__ . '/../app/controllers/voyager.php');

// Mount the EZProxy controller at /ezproxy base url
$app->mount('/ezproxy', include __DIR__ . '/../app/controllers/ezproxy.php');

$app->run();

?>
